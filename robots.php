<?php
// Served as /robots.txt (see .htaccess). Reads only the config, never the
// database, so a crawler can always fetch it. The paths below are relative to
// wherever the app is mounted ("/" in production, "/Hastra/" on a dev machine).
require __DIR__ . '/config/config.php';
$root = parse_url(APP_URL, PHP_URL_PATH) ?: '/';
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=3600');
$dis = ['auth/', 'api/', 'signin', 'signup', 'workspace/', 'portals/', 'labs/auth', 'labs/api/', 'dashboard'];
echo "User-agent: *\nAllow: " . $root . "\n";
foreach ($dis as $p) echo 'Disallow: ' . $root . $p . "\n";
echo "\nSitemap: " . APP_URL . "sitemap.xml\n";
