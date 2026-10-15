<?php
// portals/sysadmin/insights.php  (address: /workspace/sysadmin/insights)
// What the first-party telemetry in core/telemetry.php has recorded:
//   - analytics: page views, daily visitors, top pages, referrers, campaigns,
//     named events and devices (only visitors who allowed Analytics, minus
//     anyone sending Global Privacy Control / Do Not Track)
//   - errors: browser and server faults, scrubbed and de-duplicated, which the
//     sysadmin can resolve (delete) once fixed
// Both tables keep 30 days, so the range tops out at 30.
include __DIR__ . '/../../core/db.php';
secure_session_start();
verify_session($conn, "sysadmin");

$ready = astra_telemetry_ready($conn);
$days  = (int)($_GET['days'] ?? 30);
if (!in_array($days, [7, 14, 30], true)) $days = 30;
$source = in_array($_GET['source'] ?? '', ['client', 'server'], true) ? $_GET['source'] : '';
$self   = get_base_url() . 'workspace/sysadmin/insights';
$e      = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

// ── Resolve errors (POST, CSRF) ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ok = isset($_POST['csrf_token'], $_SESSION['csrf_token']) && is_string($_POST['csrf_token'])
       && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
    if ($ok && $ready && ($_POST['action'] ?? '') === 'resolve') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = mysqli_prepare($conn, "DELETE FROM app_errors WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);
        $_SESSION['insights_flash'] = mysqli_stmt_affected_rows($stmt) ? 'Issue resolved. If it happens again it will reappear as a new issue.' : 'That issue was already resolved.';
        try { log_activity($conn, (int)$_SESSION['user_id'], 'app_error_resolved'); } catch (Throwable $ex) {}
    } elseif ($ok && $ready && ($_POST['action'] ?? '') === 'resolve_all') {
        $src = in_array($_POST['source'] ?? '', ['client', 'server'], true) ? $_POST['source'] : '';
        $n = (int)$days;
        $sql = "DELETE FROM app_errors WHERE last_seen >= CURDATE() - INTERVAL " . ($n - 1) . " DAY" . ($src ? " AND source = ?" : "");
        $stmt = mysqli_prepare($conn, $sql);
        if ($src) mysqli_stmt_bind_param($stmt, "s", $src);
        mysqli_stmt_execute($stmt);
        $_SESSION['insights_flash'] = mysqli_stmt_affected_rows($stmt) . ' issue(s) resolved.';
        try { log_activity($conn, (int)$_SESSION['user_id'], 'app_errors_resolved_bulk'); } catch (Throwable $ex) {}
    } elseif (!$ok) {
        $_SESSION['insights_flash'] = 'This form expired. Reload the page and try again.';
    }
    header('Location: ' . $self . '?days=' . $days . ($source ? '&source=' . $source : '') . '#errors');
    exit();
}
$flash = $_SESSION['insights_flash'] ?? '';
unset($_SESSION['insights_flash']);

// ── Queries ─────────────────────────────────────────────────────────────────
function ins_rows($conn, string $sql, string $types = '', array $params = []): array {
    $stmt = mysqli_prepare($conn, $sql);
    if ($types) mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
}
$since = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
$daily = $pages = $refs = $camps = $events = $devices = $errors = $err_daily = [];
$totals = ['views' => 0, 'visitors' => 0, 'issues' => 0, 'occ' => 0, 'client' => 0, 'server' => 0];

if ($ready) {
    foreach (ins_rows($conn,
        "SELECT DATE(created_at) d, COUNT(*) views, COUNT(DISTINCT visitor_day) visitors
           FROM analytics_events WHERE event = 'pageview' AND created_at >= ? GROUP BY DATE(created_at)", 's', [$since]) as $r) {
        $daily[$r['d']] = ['views' => (int)$r['views'], 'visitors' => (int)$r['visitors']];
    }
    $pages   = ins_rows($conn, "SELECT path label, COUNT(*) n FROM analytics_events WHERE event = 'pageview' AND created_at >= ? GROUP BY path ORDER BY n DESC LIMIT 10", 's', [$since]);
    $refs    = ins_rows($conn, "SELECT referrer_host label, COUNT(*) n FROM analytics_events WHERE event = 'pageview' AND referrer_host <> '' AND created_at >= ? GROUP BY referrer_host ORDER BY n DESC LIMIT 10", 's', [$since]);
    $camps   = ins_rows($conn, "SELECT CONCAT(utm_source, IF(utm_medium <> '', CONCAT(' / ', utm_medium), ''), IF(utm_campaign <> '', CONCAT(' · ', utm_campaign), '')) label, COUNT(*) n
                                  FROM analytics_events WHERE utm_source <> '' AND created_at >= ? GROUP BY utm_source, utm_medium, utm_campaign ORDER BY n DESC LIMIT 10", 's', [$since]);
    $events  = ins_rows($conn, "SELECT event label, COUNT(*) n FROM analytics_events WHERE event <> 'pageview' AND created_at >= ? GROUP BY event ORDER BY n DESC", 's', [$since]);
    $devices = ins_rows($conn, "SELECT device label, COUNT(*) n FROM analytics_events WHERE event = 'pageview' AND created_at >= ? GROUP BY device ORDER BY n DESC", 's', [$since]);

    $types = 's'; $params = [$since];
    $where = "last_seen >= ?";
    if ($source) { $where .= " AND source = ?"; $types .= 's'; $params[] = $source; }
    $errors = ins_rows($conn, "SELECT * FROM app_errors WHERE $where ORDER BY last_seen DESC LIMIT 100", $types, $params);
    foreach (ins_rows($conn, "SELECT source, COUNT(*) issues, COALESCE(SUM(occurrences), 0) occ FROM app_errors WHERE last_seen >= ? GROUP BY source", 's', [$since]) as $r) {
        $totals['issues'] += (int)$r['issues'];
        $totals['occ']    += (int)$r['occ'];
        $totals[$r['source'] === 'server' ? 'server' : 'client'] += (int)$r['issues'];
    }
    foreach (ins_rows($conn, "SELECT DATE(first_seen) d, COUNT(*) n FROM app_errors WHERE first_seen >= ? GROUP BY DATE(first_seen)", 's', [$since]) as $r) {
        $err_daily[$r['d']] = (int)$r['n'];
    }
}
// one entry per day, oldest first, including empty days
$series_views = $series_visitors = $series_errors = [];
for ($i = $days - 1; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $series_views[$d]    = $daily[$d]['views'] ?? 0;
    $series_visitors[$d] = $daily[$d]['visitors'] ?? 0;
    $series_errors[$d]   = $err_daily[$d] ?? 0;
}
$totals['views']    = array_sum($series_views);
$totals['visitors'] = array_sum($series_visitors);
$avg_visitors       = $days ? $totals['visitors'] / $days : 0;

// ── CSV export ──────────────────────────────────────────────────────────────
if (isset($_GET['export'])) {
    $kind = $_GET['export'];
    $sets = [
        'daily'  => [['date', 'page_views', 'visitors', 'new_error_issues'], array_map(fn($d) => [$d, $series_views[$d], $series_visitors[$d], $series_errors[$d]], array_keys($series_views))],
        'pages'  => [['path', 'page_views'], array_map(fn($r) => [$r['label'], $r['n']], $pages)],
        'errors' => [['source', 'environment', 'message', 'location', 'path', 'browser', 'occurrences', 'first_seen', 'last_seen'],
                     array_map(fn($r) => [$r['source'], $r['environment'], $r['message'], $r['location'], $r['path'], $r['browser'], $r['occurrences'], $r['first_seen'], $r['last_seen']], $errors)],
    ];
    if (!isset($sets[$kind])) { http_response_code(400); exit('Unknown export.'); }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="hastra-' . $kind . '-' . date('Ymd') . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    // a cell starting with = + - @ would run as a formula in a spreadsheet
    $safe = fn($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
    fputcsv($out, $sets[$kind][0]);
    foreach ($sets[$kind][1] as $row) fputcsv($out, array_map($safe, $row));
    exit();
}

// ── Rendering helpers ───────────────────────────────────────────────────────
function ins_compact(float $n): string {
    if ($n >= 1e6) return rtrim(rtrim(number_format($n / 1e6, 1), '0'), '.') . 'M';
    if ($n >= 1e4) return rtrim(rtrim(number_format($n / 1e3, 1), '0'), '.') . 'K';
    return number_format($n, $n < 10 && floor($n) != $n ? 1 : 0);
}
function ins_nice_max(int $max): int {
    if ($max <= 4) return 4;
    $mag = 10 ** floor(log10($max));
    foreach ([1, 2, 2.5, 5, 10] as $m) if ($m * $mag >= $max) return (int)ceil($m * $mag);
    return $max;
}
// Single-series column chart as inline SVG: hairline grid, 4px rounded caps
// square at the baseline, 2px gaps, a native tooltip per column and a hit area
// taller than the mark. $series is date => value.
function ins_columns(array $series, string $title, string $unit): string {
    $W = 720; $H = 220; $padL = 40; $padR = 8; $padT = 12; $padB = 28;
    $n = count($series); $max = ins_nice_max(max($series ?: [0]));
    $plotW = $W - $padL - $padR; $plotH = $H - $padT - $padB;
    $slot = $plotW / max($n, 1); $bw = min(24, $slot - 2);
    $y = fn($v) => $padT + $plotH - ($v / $max) * $plotH;
    $svg = '<svg class="ins-chart" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="' . htmlspecialchars($title) . '">';
    foreach ([0, .5, 1] as $f) {
        $v = (int)round($max * $f); $yy = round($y($v), 1);
        $svg .= '<line class="ins-grid" x1="' . $padL . '" x2="' . ($W - $padR) . '" y1="' . $yy . '" y2="' . $yy . '"/>';
        $svg .= '<text class="ins-tick" x="' . ($padL - 8) . '" y="' . ($yy + 4) . '" text-anchor="end">' . number_format($v) . '</text>';
    }
    $every = $n <= 7 ? 1 : ($n <= 14 ? 2 : 5);
    $i = 0;
    foreach ($series as $date => $v) {
        $x = $padL + $i * $slot + ($slot - $bw) / 2;
        $label = date('j M', strtotime($date));
        $tip = date('D j M', strtotime($date)) . ': ' . number_format($v) . ' ' . $unit;
        $svg .= '<g class="ins-col" tabindex="0" aria-label="' . htmlspecialchars($tip) . '"><title>' . htmlspecialchars($tip) . '</title>';
        $svg .= '<rect class="ins-hit" x="' . round($padL + $i * $slot, 1) . '" y="' . $padT . '" width="' . round($slot, 1) . '" height="' . $plotH . '"/>';
        if ($v > 0) {
            $top = $y($v); $h = $padT + $plotH - $top; $r = min(4, $h, $bw / 2);
            $x0 = round($x, 1); $x1 = round($x + $bw, 1); $yb = $padT + $plotH; $yt = round($top, 1);
            $svg .= '<path class="ins-bar" d="M' . $x0 . ',' . $yb . 'V' . ($yt + $r) . 'Q' . $x0 . ',' . $yt . ' ' . ($x0 + $r) . ',' . $yt
                  . 'H' . ($x1 - $r) . 'Q' . $x1 . ',' . $yt . ' ' . $x1 . ',' . ($yt + $r) . 'V' . $yb . 'Z"/>';
        }
        $svg .= '</g>';
        if ($i % $every === 0 || $i === $n - 1) {
            $svg .= '<text class="ins-tick" x="' . round($x + $bw / 2, 1) . '" y="' . ($H - 8) . '" text-anchor="middle">' . $label . '</text>';
        }
        $i++;
    }
    $svg .= '<line class="ins-axis" x1="' . $padL . '" x2="' . ($W - $padR) . '" y1="' . ($padT + $plotH) . '" y2="' . ($padT + $plotH) . '"/>';
    return $svg . '</svg>';
}
function ins_table(array $series, string $col): string {
    $h = '<details class="ins-tableview"><summary>Show as table</summary><table><thead><tr><th scope="col">Date</th><th scope="col" class="num">' . htmlspecialchars($col) . '</th></tr></thead><tbody>';
    foreach (array_reverse($series, true) as $d => $v) $h .= '<tr><td>' . date('D j M Y', strtotime($d)) . '</td><td class="num">' . number_format($v) . '</td></tr>';
    return $h . '</tbody></table></details>';
}
function ins_ranked(array $rows, string $empty): string {
    if (!$rows) return '<p class="ins-empty">' . htmlspecialchars($empty) . '</p>';
    $total = array_sum(array_map(fn($r) => (int)$r['n'], $rows)); $top = max(array_map(fn($r) => (int)$r['n'], $rows));
    $h = '<ol class="ins-ranked">';
    foreach ($rows as $r) {
        $n = (int)$r['n']; $pct = $total ? round($n / $total * 100) : 0;
        $h .= '<li><div class="ins-rk-row"><span class="ins-rk-label" title="' . htmlspecialchars($r['label']) . '">' . htmlspecialchars($r['label']) . '</span>'
            . '<span class="ins-rk-val">' . number_format($n) . ' <small>' . $pct . '%</small></span></div>'
            . '<span class="ins-rk-bar" style="width:' . max(1, round($n / $top * 100, 1)) . '%" aria-hidden="true"></span></li>';
    }
    return $h . '</ol>';
}
$csrf = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Analytics &amp; Errors · Hastra</title>
<?php astra_page_meta('Analytics & Errors · Hastra'); ?>
<script src="<?= get_base_url() ?>core/theme.js?v=<?= ASSET_VERSION ?>"></script>
<link rel="stylesheet" href="<?= get_base_url() ?>core/theme.css?v=<?= ASSET_VERSION ?>"><link rel="stylesheet" href="<?= get_base_url() ?>assets/css/theme-contrast.css?v=<?= ASSET_VERSION ?>"><link rel="stylesheet" href="<?= get_base_url() ?>assets/css/theme-fog-boxless.css?v=<?= ASSET_VERSION ?>">
<link rel="stylesheet" href="<?= get_base_url() ?>assets/css/fonts.css?v=<?= ASSET_VERSION ?>">
<style>
  * { box-sizing: border-box; }
  body { margin: 0; background: var(--navy); color: var(--text); font-family: var(--font-sans); font-size: 14px; }
  .wrap { max-width: 1120px; margin: 0 auto; padding: 2rem 1.2rem 5rem; }
  h1 { font-size: 24px; font-weight: 700; margin: 0.8rem 0 0.35rem; }
  .lede { color: var(--text-dim); margin: 0 0 1.4rem; line-height: 1.6; max-width: 76ch; }
  .ins-flash { margin: 0 0 1.2rem; padding-left: .9rem; border-left: 3px solid var(--green); }

  /* filters: one row, above everything they affect */
  .ins-filters { display: flex; flex-wrap: wrap; gap: 6px 22px; align-items: center; margin: 0 0 1.8rem; }
  .ins-filters span { color: var(--text-dim); font-size: 12px; font-family: 'Share Tech Mono', monospace; letter-spacing: .08em; text-transform: uppercase; }
  .ins-seg { display: inline-flex; gap: 14px; }
  .ins-seg a { min-height: 40px; display: inline-flex; align-items: center; color: var(--text-dim); text-decoration: none; font-weight: 600; border-bottom: 2px solid transparent; }
  .ins-seg a:hover { color: var(--text); }
  .ins-seg a[aria-current="true"] { color: var(--text); border-bottom-color: var(--accent-bright); }

  /* figures: typography, no tiles */
  .ins-figs { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 18px 28px; margin: 0 0 2.2rem; }
  @media (max-width: 760px) { .ins-figs { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
  .ins-fig .v { font-size: 34px; font-weight: 650; line-height: 1.1; font-variant-numeric: proportional-nums; }
  .ins-fig .l { color: var(--text-dim); font-size: 12.5px; margin-top: 4px; }
  .ins-fig.hero .v { font-size: 52px; }

  h2 { font-size: 18px; margin: 2.6rem 0 .3rem; }
  h2 + .sub { color: var(--text-dim); margin: 0 0 1rem; font-size: 13px; }
  h3 { font-size: 13px; font-family: 'Share Tech Mono', monospace; letter-spacing: .08em; text-transform: uppercase; color: var(--text-dim); font-weight: 400; margin: 1.8rem 0 .6rem; }
  .ins-export { font-size: 12.5px; color: var(--accent-bright); text-decoration: underline; text-underline-offset: 4px; margin-left: 10px; }

  /* charts */
  .ins-chart { width: 100%; height: auto; display: block; overflow: visible; }
  .ins-grid, .ins-axis { stroke: var(--border-dim); stroke-width: 1; }
  .ins-axis { stroke: var(--text-dim); opacity: .45; }
  .ins-tick { fill: var(--text-dim); font-size: 11px; font-family: var(--font-sans); }
  .ins-bar { fill: var(--accent-bright); }
  .ins-hit { fill: transparent; }
  .ins-col:hover .ins-hit, .ins-col:focus .ins-hit { fill: var(--text); fill-opacity: .06; }
  .ins-col:focus { outline: none; }
  .ins-col:focus-visible .ins-hit { stroke: var(--accent-bright); stroke-width: 2; }
  .ins-tableview { margin: .4rem 0 0; }
  .ins-tableview summary { cursor: pointer; color: var(--text-dim); font-size: 12.5px; min-height: 36px; display: inline-flex; align-items: center; }
  .ins-tableview table { width: auto; min-width: 280px; margin-top: .4rem; }

  /* ranked lists: label, value, share and a thin proportional bar */
  .ins-cols { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px 48px; }
  @media (max-width: 860px) { .ins-cols { grid-template-columns: minmax(0, 1fr); } }
  .ins-ranked { list-style: none; margin: 0; padding: 0; }
  .ins-ranked li { padding: 8px 0 9px; border-bottom: 1px solid var(--border-dim); }
  .ins-rk-row { display: flex; justify-content: space-between; gap: 14px; align-items: baseline; }
  .ins-rk-label { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-family: 'Share Tech Mono', monospace; font-size: 13px; }
  .ins-rk-val { flex: none; font-variant-numeric: tabular-nums; font-weight: 600; }
  .ins-rk-val small { color: var(--text-dim); font-weight: 400; margin-left: 4px; }
  .ins-rk-bar { display: block; height: 4px; margin-top: 6px; border-radius: 0 4px 4px 0; background: var(--accent-bright); }
  .ins-empty { color: var(--text-dim); margin: .2rem 0 0; }

  /* errors */
  .ins-errs-wrap { overflow-x: auto; }
  .ins-errs { width: 100%; border-collapse: collapse; }
  .ins-errs th { text-align: left; font-size: 11px; font-family: 'Share Tech Mono', monospace; letter-spacing: .08em; text-transform: uppercase; color: var(--text-dim); font-weight: 400; padding: 8px 10px 8px 0; border-bottom: 1px solid var(--border-dim); }
  .ins-errs td { padding: 12px 10px 12px 0; border-bottom: 1px solid var(--border-dim); vertical-align: top; }
  .ins-errs .num, .ins-tableview .num { text-align: right; font-variant-numeric: tabular-nums; }
  .ins-msg { font-weight: 600; word-break: break-word; max-width: 54ch; }
  .ins-loc { display: block; color: var(--text-dim); font-family: 'Share Tech Mono', monospace; font-size: 12px; margin-top: 3px; word-break: break-all; }
  .ins-errs details summary { cursor: pointer; color: var(--text-dim); font-size: 12px; margin-top: 4px; }
  .ins-errs pre { white-space: pre-wrap; word-break: break-all; font-size: 11.5px; max-height: 260px; overflow: auto; margin: 6px 0 0; color: var(--text-dim); }
  .ins-tag { font-family: 'Share Tech Mono', monospace; font-size: 11.5px; color: var(--text-dim); white-space: nowrap; }
  .ins-tag b { color: var(--text); font-weight: 400; }
  .ins-resolve { background: none; border: 0; padding: 0; min-height: 36px; cursor: pointer; font: 600 13px/1 var(--font-sans); color: var(--text);
    text-decoration: underline; text-decoration-color: var(--accent-bright); text-underline-offset: 5px; text-decoration-thickness: 2px; }
  .ins-bulk { margin-top: 1rem; }
  .ins-note { color: var(--text-dim); font-size: 12.5px; line-height: 1.6; max-width: 80ch; margin-top: 2.4rem; }
  .ins-note a { color: var(--accent-bright); text-underline-offset: 3px; }
</style>
</head>
<body>
<?php $nav_current = 'insights'; include __DIR__ . '/_nav.php'; ?>
<main class="wrap" id="main-content">
  <h1>Analytics &amp; errors</h1>
  <p class="lede">First-party numbers from Hastra's own database. Page views are counted only for visitors who allowed Analytics
    in the cookie banner and do not send Global Privacy Control or Do Not Track, so these are a consenting sample, not every visit.
    Errors come from every visitor, with emails, numbers and secrets removed. Everything is kept for 30 days.</p>

  <?php if ($flash): ?><p class="ins-flash" role="status"><?= $e($flash) ?></p><?php endif; ?>
  <?php if (!$ready): ?><p class="ins-flash" role="alert" style="border-left-color:var(--red)">The telemetry tables are not available. Run the database migration, then reload.</p><?php endif; ?>

  <div class="ins-filters" role="group" aria-label="Filters">
    <span>Range</span>
    <nav class="ins-seg" aria-label="Date range">
      <?php foreach ([7, 14, 30] as $d): ?>
        <a href="<?= $e($self . '?' . http_build_query(array_filter(['days' => $d, 'source' => $source]))) ?>" aria-current="<?= $d === $days ? 'true' : 'false' ?>">Last <?= $d ?> days</a>
      <?php endforeach; ?>
    </nav>
  </div>

  <div class="ins-figs">
    <div class="ins-fig hero"><div class="v"><?= ins_compact($totals['views']) ?></div><div class="l">Page views, last <?= $days ?> days</div></div>
    <div class="ins-fig"><div class="v"><?= ins_compact($avg_visitors) ?></div><div class="l">Visitors per day, on average</div></div>
    <div class="ins-fig"><div class="v"><?= ins_compact($totals['issues']) ?></div><div class="l">Error issues seen in this range</div></div>
    <div class="ins-fig"><div class="v"><?= ins_compact($totals['occ']) ?></div><div class="l">Error occurrences (all time, those issues)</div></div>
  </div>

  <h2>Page views per day <a class="ins-export" href="<?= $e($self . '?days=' . $days . '&export=daily') ?>">Download CSV</a></h2>
  <p class="sub">Each column is one day. Hover or focus a column for its count.</p>
  <?= ins_columns($series_views, "Page views per day, last $days days", 'page views') ?>
  <?= ins_table($series_views, 'Page views') ?>

  <h2>Visitors per day</h2>
  <p class="sub">A visitor is counted once per day through a code that changes daily, so the same person on two days counts twice.</p>
  <?= ins_columns($series_visitors, "Visitors per day, last $days days", 'visitors') ?>
  <?= ins_table($series_visitors, 'Visitors') ?>

  <div class="ins-cols">
    <section aria-labelledby="h-pages">
      <h3 id="h-pages">Top pages <a class="ins-export" href="<?= $e($self . '?days=' . $days . '&export=pages') ?>">CSV</a></h3>
      <?= ins_ranked($pages, 'No page views recorded in this range yet.') ?>
    </section>
    <section aria-labelledby="h-refs">
      <h3 id="h-refs">Referring sites</h3>
      <?= ins_ranked($refs, 'No visits from other sites in this range. Direct visits and visits from Hastra itself are not listed.') ?>
    </section>
    <section aria-labelledby="h-camps">
      <h3 id="h-camps">Campaigns (UTM source / medium · campaign)</h3>
      <?= ins_ranked($camps, 'No visits arrived through a link with utm_ tags in this range.') ?>
    </section>
    <section aria-labelledby="h-events">
      <h3 id="h-events">Actions</h3>
      <?= ins_ranked($events, 'No named actions (search, sign-up starts, Labs runs) recorded in this range.') ?>
      <h3>Devices</h3>
      <?= ins_ranked($devices, 'No page views recorded in this range yet.') ?>
    </section>
  </div>

  <h2 id="errors">Errors <a class="ins-export" href="<?= $e($self . '?' . http_build_query(array_filter(['days' => $days, 'source' => $source, 'export' => 'errors']))) ?>">Download CSV</a></h2>
  <p class="sub"><?= number_format($totals['client']) ?> browser and <?= number_format($totals['server']) ?> server issue(s) seen in the last <?= $days ?> days. The same message at the same place counts as one issue.</p>

  <h3>New issues per day</h3>
  <?= ins_columns($series_errors, "New error issues per day, last $days days", 'new issues') ?>
  <?= ins_table($series_errors, 'New issues') ?>

  <div class="ins-filters" role="group" aria-label="Error source" style="margin:1.6rem 0 .6rem">
    <span>Source</span>
    <nav class="ins-seg" aria-label="Error source">
      <?php foreach (['' => 'All', 'client' => 'Browser', 'server' => 'Server'] as $k => $lbl): ?>
        <a href="<?= $e($self . '?' . http_build_query(array_filter(['days' => $days, 'source' => $k])) . '#errors') ?>" aria-current="<?= $k === $source ? 'true' : 'false' ?>"><?= $lbl ?></a>
      <?php endforeach; ?>
    </nav>
  </div>

  <?php if (!$errors): ?>
    <p class="ins-empty">No errors recorded in this range<?= $source ? ' for this source' : '' ?>. Nice.</p>
  <?php else: ?>
  <div class="ins-errs-wrap">
    <table class="ins-errs">
      <caption class="hx-sr">Error issues, most recently seen first</caption>
      <thead><tr><th scope="col">Issue</th><th scope="col">Where</th><th scope="col" class="num">Count</th><th scope="col">Last seen</th><th scope="col"><span class="hx-sr">Action</span></th></tr></thead>
      <tbody>
      <?php foreach ($errors as $r): ?>
        <tr>
          <td>
            <div class="ins-msg"><?= $e($r['message']) ?></div>
            <?php if ($r['location'] !== ''): ?><span class="ins-loc"><?= $e($r['location']) ?></span><?php endif; ?>
            <?php if (trim((string)$r['stack']) !== ''): ?><details><summary>Stack trace</summary><pre><?= $e($r['stack']) ?></pre></details><?php endif; ?>
          </td>
          <td>
            <span class="ins-loc" style="margin-top:0"><?= $e($r['path']) ?></span>
            <span class="ins-tag"><b><?= $r['source'] === 'server' ? 'Server' : 'Browser' ?></b> · <?= $e($r['browser']) ?> · <?= $e($r['environment']) ?></span>
          </td>
          <td class="num"><?= number_format((int)$r['occurrences']) ?></td>
          <td><span class="ins-tag"><time datetime="<?= $e(date('c', strtotime($r['last_seen']))) ?>"><?= $e(date('j M, H:i', strtotime($r['last_seen']))) ?></time></span>
              <span class="ins-tag" style="display:block">first <?= $e(date('j M', strtotime($r['first_seen']))) ?></span></td>
          <td>
            <form method="post" action="<?= $e($self . '?' . http_build_query(array_filter(['days' => $days, 'source' => $source]))) ?>"
                  data-confirm="Mark this issue as resolved? It is removed from the list, and reappears as a new issue if it happens again." data-confirm-action="Resolve">
              <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
              <input type="hidden" name="action" value="resolve">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button type="submit" class="ins-resolve" aria-label="Resolve: <?= $e(mb_substr($r['message'], 0, 80)) ?>">Resolve</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <form method="post" class="ins-bulk" action="<?= $e($self . '?' . http_build_query(array_filter(['days' => $days, 'source' => $source]))) ?>"
        data-confirm="Resolve all <?= count($errors) ?> issue(s) shown<?= $source ? ' from this source' : '' ?> for the last <?= $days ?> days?" data-confirm-action="Resolve all">
    <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
    <input type="hidden" name="action" value="resolve_all">
    <input type="hidden" name="source" value="<?= $e($source) ?>">
    <button type="submit" class="ins-resolve">Resolve all shown</button>
  </form>
  <?php endif; ?>

  <p class="ins-note">How this is collected: <code>core/telemetry.php</code>. Visitors who decline Analytics are never counted; no cookie,
    account or IP address is stored with an event. Retention and wording match the
    <a href="<?= get_base_url() ?>legal/cookies#performance">Cookie Policy</a>.</p>
</main>
</body>
</html>
