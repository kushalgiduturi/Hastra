<?php
// Served as /sitemap.xml (see .htaccess). Only the public, indexable pages.
// Reads the config only (no database). lastmod is when the page's own source
// last changed, so it is true without anyone having to remember to edit it.
require __DIR__ . '/config/config.php';
$pages = [
    // path               source file                priority  changefreq
    ['',                  'landing-pages/hastra.html', '1.0', 'weekly'],
    ['labs/',             'labs/index.php',          '0.9', 'weekly'],
    ['labs/crypto',       'labs/crypto.php',         '0.8', 'monthly'],
    ['labs/siem',         'labs/siem.php',           '0.8', 'monthly'],
    ['labs/syllabus',     'labs/syllabus.php',       '0.8', 'weekly'],
];
header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($pages as [$path, $src, $prio, $freq]) {
    $t = @filemtime(__DIR__ . '/' . $src) ?: time();
    echo "  <url>\n    <loc>" . htmlspecialchars(APP_URL . $path, ENT_XML1) . "</loc>\n    <lastmod>" . date('Y-m-d', $t)
       . "</lastmod>\n    <changefreq>$freq</changefreq>\n    <priority>$prio</priority>\n  </url>\n";
}
echo "</urlset>\n";
