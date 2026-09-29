<?php
// Hastra Labs — view-ranked tutorial videos for one syllabus topic.
//
//   GET ?topic=Impossible Travel Detection
//   → { mode: "ranked", query, searchUrl, videos: [{ id, title, channel, views, duration, published }] }
//   → { mode: "link",   query, searchUrl, reason }     (no API key, quota spent, or the API failed)
//
// The query is the topic name alone; results come from the
// YouTube Data API v3 ordered by view count, re-sorted on the real
// statistics.viewCount, with live streams, Shorts and non-embeddable videos
// dropped. The API key stays on this server (env HASTRA_YOUTUBE_API_KEY or
// config/youtube.key, which is git-ignored). A search costs 100 quota units
// of the free 10,000/day, so results are cached for a week and fresh lookups
// are capped per network and per day.
require __DIR__ . '/../_boot.php';

const LABS_YT_TTL_DAYS      = 7;
const LABS_YT_KEEP          = 12;
const LABS_YT_MIN_SECONDS   = 120;     // tutorials, not Shorts
const LABS_YT_DAILY_FRESH   = 90;      // ~9,100 units/day incl. videos.list
const LABS_YT_IP_FRESH_HOUR = 40;

function labs_yt_key(): ?string {
    $k = getenv('HASTRA_YOUTUBE_API_KEY');
    if ($k) return trim($k);
    $f = __DIR__ . '/../../config/youtube.key';
    if (is_file($f)) {
        $k = trim((string)file_get_contents($f));
        if ($k !== '') return $k;
    }
    return null;
}

function labs_yt_get(string $url): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = is_string($body) ? json_decode($body, true) : null;
    if ($code !== 200 || !is_array($json)) {
        $reason = $json['error']['errors'][0]['reason'] ?? ('http_' . $code);
        error_log('[hastra-labs] youtube api: ' . $reason);   // never log the URL: it carries the key
        return ['__error' => $reason];
    }
    return $json;
}

// ISO-8601 duration (PT1H2M3S) → seconds.
function labs_yt_seconds(string $iso): int {
    if (!preg_match('/^P(?:(\d+)D)?T?(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$/', $iso, $m)) return 0;
    return ((int)($m[1] ?? 0)) * 86400 + ((int)($m[2] ?? 0)) * 3600 + ((int)($m[3] ?? 0)) * 60 + (int)($m[4] ?? 0);
}

// search.list (ordered by view count) + videos.list (real counts, durations),
// filtered down to embeddable, non-live tutorials. Shared by the exact topic
// search and the broadened "related topic" fallback below.
function labs_yt_fetch(string $q, string $key, int $keep): array {
    $search = labs_yt_get('https://www.googleapis.com/youtube/v3/search?' . http_build_query([
        'part' => 'snippet', 'type' => 'video', 'order' => 'viewCount', 'maxResults' => 25,
        'q' => $q, 'videoEmbeddable' => 'true', 'safeSearch' => 'moderate', 'key' => $key,
    ]));
    if (isset($search['__error'])) return ['error' => $search['__error']];
    $ids = [];
    foreach ($search['items'] ?? [] as $it) {
        $id = $it['id']['videoId'] ?? '';
        if (preg_match('/^[A-Za-z0-9_-]{11}$/', $id)) $ids[] = $id;
    }
    if (!$ids) return ['videos' => []];
    $detail = labs_yt_get('https://www.googleapis.com/youtube/v3/videos?' . http_build_query([
        'part' => 'snippet,statistics,contentDetails,status', 'id' => implode(',', $ids), 'key' => $key,
    ]));
    if (isset($detail['__error'])) return ['error' => $detail['__error']];
    $videos = [];
    foreach ($detail['items'] ?? [] as $v) {
        $secs = labs_yt_seconds((string)($v['contentDetails']['duration'] ?? ''));
        if ($secs < LABS_YT_MIN_SECONDS) continue;
        if (($v['snippet']['liveBroadcastContent'] ?? 'none') !== 'none') continue;
        if (isset($v['status']['embeddable']) && !$v['status']['embeddable']) continue;
        $videos[] = [
            'id'        => $v['id'],
            'title'     => html_entity_decode((string)($v['snippet']['title'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'channel'   => html_entity_decode((string)($v['snippet']['channelTitle'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'views'     => (int)($v['statistics']['viewCount'] ?? 0),
            'duration'  => $secs,
            'published' => substr((string)($v['snippet']['publishedAt'] ?? ''), 0, 10),
        ];
    }
    usort($videos, fn($a, $b) => $b['views'] <=> $a['views']);
    return ['videos' => array_slice($videos, 0, $keep)];
}

// When the exact topic has no embeddable tutorial, widen the search —
// progressively shorter candidates, from "cut at the first
// conjunction/punctuation" down to just the first two words, so
// "TAXII 2.1 collections and polling" tries "TAXII 2.1 collections" and
// then "TAXII 2.1" until one of them actually finds a tutorial.
function labs_yt_broaden_candidates(string $topic): array {
    $s = trim(preg_replace('/\s*\([^)]*\)/', '', $topic));
    $cut = trim(preg_split('/\s+(?:and|or|vs\.?|versus|with|via|using|for)\s+|\s*[,;:]\s*|\s+-\s+/i', $s, 2)[0]);
    $candidates = [];
    if ($cut !== '' && mb_strtolower($cut) !== mb_strtolower($s)) $candidates[] = $cut;
    $words = preg_split('/\s+/', $cut !== '' ? $cut : $s);
    for ($n = min(3, count($words) - 1); $n >= 2; $n--) $candidates[] = implode(' ', array_slice($words, 0, $n));
    $seen = [mb_strtolower(trim($topic))];
    $out = [];
    foreach ($candidates as $c) {
        $k = mb_strtolower($c);
        if ($c !== '' && mb_strlen($c) >= 2 && !in_array($k, $seen, true)) { $out[] = $c; $seen[] = $k; }
    }
    return $out;
}

// A short, plain-English explanation of the topic, from Wikipedia's free
// public search + summary APIs (no key needed). Best-effort: any failure
// just means no description is shown, never an error to the student.
function labs_ext_get_json(string $url): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: Hastra-Labs/1.0 (https://hastra.onrender.com)'],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = is_string($body) ? json_decode($body, true) : null;
    return ($code === 200 && is_array($json)) ? $json : null;
}
// The significant (len >= 4, non-generic) words in a topic — used to check
// that a Wikipedia match is actually about the topic asked, not just
// something that shares one incidental word with it.
function labs_wiki_significant_words(string $s): array {
    preg_match_all('/[A-Za-z]{4,}/', $s, $m);
    static $stop = ['this', 'that', 'with', 'from', 'into', 'onto', 'over', 'under', 'about', 'their', 'there',
                     'which', 'while', 'using', 'than', 'then', 'also', 'more', 'most', 'less', 'least', 'such',
                     'each', 'both', 'other', 'some', 'many', 'much', 'very', 'when', 'where'];
    $out = [];
    foreach ($m[0] as $w) { $lw = mb_strtolower($w); if (!in_array($lw, $stop, true)) $out[$lw] = true; }
    return array_keys($out);
}

// A short, plain-English explanation of the exact topic asked — never a
// broadened or context-substituted phrase — from Wikipedia's free public
// search + summary APIs (no key needed). A search match is only trusted if
// a strict majority of the topic's own significant words actually appear in
// the matched page's title; otherwise it is discarded rather than shown as
// if it explains the topic (seen in testing: a bare full-text search for
// "IOC normalization and refanging" landed on an unrelated football
// federation's "normalisation committee", which merely shared one word).
// Best-effort throughout: any failure just means no description is shown.
function labs_wiki_summary(string $topic): ?array {
    $search = labs_ext_get_json('https://en.wikipedia.org/w/api.php?' . http_build_query([
        'action' => 'query', 'list' => 'search', 'srsearch' => $topic, 'format' => 'json', 'srlimit' => 1,
    ]));
    $title = $search['query']['search'][0]['title'] ?? null;
    if (!$title) return null;
    $sig = labs_wiki_significant_words($topic);
    if ($sig) {
        $titleLower = mb_strtolower($title);
        $hits = 0;
        foreach ($sig as $w) if (preg_match('/\b' . preg_quote($w, '/') . '\b/u', $titleLower)) $hits++;
        if ($hits < intdiv(count($sig), 2) + 1) return null;   // not actually about this topic
    }
    $summary = labs_ext_get_json('https://en.wikipedia.org/api/rest_v1/page/summary/' . rawurlencode(str_replace(' ', '_', $title)));
    $extract = trim((string)($summary['extract'] ?? ''));
    if ($extract === '' || ($summary['type'] ?? '') === 'disambiguation') return null;
    return [
        'title'   => (string)($summary['title'] ?? $title),
        'extract' => mb_substr($extract, 0, 600),
        'url'     => (string)($summary['content_urls']['desktop']['page'] ?? ('https://en.wikipedia.org/wiki/' . rawurlencode(str_replace(' ', '_', $title)))),
    ];
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') labs_json(['error' => 'method'], 405);

$topic = (string)($_GET['topic'] ?? '');
$topic = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $topic);
$topic = trim(preg_replace('/\s+/u', ' ', (string)$topic));
$topic = mb_substr($topic, 0, 120);
if (mb_strlen($topic) < 2) labs_json(['error' => 'topic'], 400);

$query = $topic;
$search_url = 'https://www.youtube.com/results?search_query=' . rawurlencode($query) . '&sp=CAM%3D'; // sorted by views
$link = fn(string $reason) => labs_json(['mode' => 'link', 'query' => $query, 'searchUrl' => $search_url, 'reason' => $reason]);

$client = labs_client_key();
if (!labs_rate_hit($conn, "yt-any:$client", 600, 3600)) labs_json(['error' => 'rate_limited'], 429);

// ── Cache ──
$qhash = hash('sha256', mb_strtolower($query));
if (labs_schema_ready($conn)) {
    $stmt = mysqli_prepare($conn, "SELECT payload FROM labs_video_cache WHERE query_hash = ? AND fetched_at > DATE_SUB(NOW(), INTERVAL ? DAY)");
    $ttl = LABS_YT_TTL_DAYS;
    mysqli_stmt_bind_param($stmt, 'si', $qhash, $ttl);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_row(mysqli_stmt_get_result($stmt));
    if ($row) {
        $cached = json_decode($row[0], true);
        if (is_array($cached)) { $cached['cached'] = true; labs_json($cached); }
    }
}

$key = labs_yt_key();
if (!$key) $link('no_api_key');
if (!labs_rate_hit($conn, "yt-fresh:$client", LABS_YT_IP_FRESH_HOUR, 3600)) $link('rate_limited');
if (!labs_rate_hit($conn, 'yt-fresh:global', LABS_YT_DAILY_FRESH, 86400)) $link('daily_quota');

// ── exact topic ──
$exact = labs_yt_fetch($query, $key, LABS_YT_KEEP);
if (isset($exact['error'])) $link($exact['error'] === 'quotaExceeded' ? 'daily_quota' : 'api_error');
$videos = $exact['videos'];

// ── no embeddable tutorial for the exact topic: widen the search, and pull
//    a short plain-English explanation of the topic from Wikipedia, so the
//    student still leaves with something useful ──
$related = null;
$description = null;
if (!$videos) {
    $candidates = labs_yt_broaden_candidates($query);
    foreach (array_slice($candidates, 0, 2) as $cand) {
        $wide = labs_yt_fetch($cand, $key, 6);
        if (!isset($wide['error']) && $wide['videos']) { $related = ['query' => $cand, 'videos' => $wide['videos']]; break; }
    }
    // The description answers the exact topic asked only — never a
    // broadened or substituted phrase, even if that phrase is what found
    // the related video above.
    $description = labs_wiki_summary($query);
}

$payload = ['mode' => 'ranked', 'query' => $query, 'searchUrl' => $search_url, 'videos' => $videos,
            'related' => $related, 'description' => $description, 'fetchedAt' => date('c')];
if (labs_schema_ready($conn)) {
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $stmt = mysqli_prepare($conn, "REPLACE INTO labs_video_cache (query_hash, query_text, payload, fetched_at) VALUES (?, ?, ?, NOW())");
    $qtext = mb_substr($query, 0, 200);
    mysqli_stmt_bind_param($stmt, 'sss', $qhash, $qtext, $json);
    mysqli_stmt_execute($stmt);
}
labs_json($payload);
