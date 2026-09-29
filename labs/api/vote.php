<?php
// Hastra Labs — community like/dislike consensus for syllabus topic videos.
//
//   GET  ?topic=...                                    → { pick: { id, title, likes, dislikes } | null }
//   POST { topic, videoId, videoTitle, action }         → { ok, likes, dislikes }   (X-CSRF-Token header)
//
// Any student can vote, signed in or not — this is a lightweight "wisdom of
// the crowd" signal, not an account-scoped preference (that part already
// lives in each browser's own localStorage). Counts are per (topic, video)
// so the crowd can prefer a different video than the raw view-count ranking,
// and a later dislike wave can dethrone a video that used to lead.
require __DIR__ . '/../_boot.php';

if (!labs_schema_ready($conn)) labs_json(['pick' => null]);

function labs_vote_topic_hash(string $topic): string {
    return hash('sha256', mb_strtolower(trim(preg_replace('/\s+/u', ' ', $topic))));
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $topic = trim((string)($_GET['topic'] ?? ''));
    if (mb_strlen($topic) < 2) labs_json(['pick' => null]);
    $hash = labs_vote_topic_hash($topic);
    $stmt = mysqli_prepare($conn, "SELECT video_id, video_title, likes, dislikes FROM labs_video_votes
                                    WHERE topic_hash = ? AND likes > dislikes
                                    ORDER BY (likes - dislikes) DESC, likes DESC LIMIT 1");
    mysqli_stmt_bind_param($stmt, 's', $hash);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    labs_json(['pick' => $row ? ['id' => $row['video_id'], 'title' => $row['video_title'],
                                  'likes' => (int)$row['likes'], 'dislikes' => (int)$row['dislikes']] : null]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') labs_json(['error' => 'method'], 405);
if (!labs_csrf_ok()) labs_json(['error' => 'csrf'], 403);

$client = labs_client_key();
if (!labs_rate_hit($conn, "vote:$client", 60, 3600)) labs_json(['error' => 'rate_limited'], 429);

$body = json_decode(file_get_contents('php://input', false, null, 0, 8192), true);
if (!is_array($body)) labs_json(['error' => 'bad_request'], 400);
$topic = trim((string)($body['topic'] ?? ''));
$videoId = (string)($body['videoId'] ?? '');
$videoTitle = mb_substr(trim((string)($body['videoTitle'] ?? '')), 0, 280);
$action = (string)($body['action'] ?? '');
if (mb_strlen($topic) < 2 || !preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId) || !in_array($action, ['like', 'dislike'], true)) {
    labs_json(['error' => 'bad_request'], 400);
}

$hash = labs_vote_topic_hash($topic);
$topicText = mb_substr($topic, 0, 200);
$likeInc = $action === 'like' ? 1 : 0;
$dislikeInc = $action === 'dislike' ? 1 : 0;
$stmt = mysqli_prepare($conn, "INSERT INTO labs_video_votes (topic_hash, video_id, topic_text, video_title, likes, dislikes, updated_at)
                                VALUES (?, ?, ?, ?, ?, ?, NOW())
                                ON DUPLICATE KEY UPDATE likes = likes + VALUES(likes), dislikes = dislikes + VALUES(dislikes),
                                                         video_title = VALUES(video_title), updated_at = NOW()");
mysqli_stmt_bind_param($stmt, 'ssssii', $hash, $videoId, $topicText, $videoTitle, $likeInc, $dislikeInc);
mysqli_stmt_execute($stmt);

$stmt = mysqli_prepare($conn, "SELECT likes, dislikes FROM labs_video_votes WHERE topic_hash = ? AND video_id = ?");
mysqli_stmt_bind_param($stmt, 'ss', $hash, $videoId);
mysqli_stmt_execute($stmt);
$row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
labs_json(['ok' => true, 'likes' => (int)($row['likes'] ?? 0), 'dislikes' => (int)($row['dislikes'] ?? 0)]);
