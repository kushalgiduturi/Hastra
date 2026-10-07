<?php
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) { http_response_code(404); exit(); }
// core/seo.php
// One place for the social/search tags every public page shares: description,
// robots, canonical, theme colour, Open Graph, Twitter card and the favicon
// set. Absolute URLs come from APP_URL, so the same code is right on localhost,
// on Render and on a custom domain.
//
//   astra_seo_meta([
//       'title'       => 'Cryptography & File Armor · Hastra Labs',   // og/twitter title
//       'description' => '...',
//       'path'        => 'labs/crypto',                                // canonical, relative to the app root
//       'robots'      => 'index, follow',                              // 'noindex, follow' for sign-in pages
//   ]);

const ASTRA_SEO_DEFAULT_DESCRIPTION =
    'Hastra is a governed, auditable workspace for software delivery, plus free browser-native labs: post-quantum '
  . 'cryptography, SIEM and threat-intel simulators, and a syllabus accelerator.';
const ASTRA_SEO_KEYWORDS =
    'post-quantum cryptography, ML-KEM, ML-DSA, SIEM, threat intelligence, cybersecurity labs, secure software delivery, syllabus accelerator';

function astra_seo_meta(array $o = []): void {
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $base   = rtrim(APP_URL, '/') . '/';
    $title  = $o['title'] ?? 'Hastra';
    $desc   = $o['description'] ?? ASTRA_SEO_DEFAULT_DESCRIPTION;
    $robots = $o['robots'] ?? 'index, follow';
    $url    = $base . ltrim((string)($o['path'] ?? ''), '/');
    $image  = $base . 'assets/images/og-preview.png';
    $type   = $o['type'] ?? 'website';
    ?>
<meta name="description" content="<?= $e($desc) ?>">
<meta name="keywords" content="<?= $e($o['keywords'] ?? ASTRA_SEO_KEYWORDS) ?>">
<meta name="author" content="Hastra">
<meta name="robots" content="<?= $e($robots) ?>">
<meta name="theme-color" content="#050505">
<link rel="canonical" href="<?= $e($url) ?>">
<meta property="og:site_name" content="Hastra">
<meta property="og:type" content="<?= $e($type) ?>">
<meta property="og:url" content="<?= $e($url) ?>">
<meta property="og:title" content="<?= $e($title) ?>">
<meta property="og:description" content="<?= $e($desc) ?>">
<meta property="og:image" content="<?= $e($image) ?>">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="Hastra, the torii H mark on a dark crimson background">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= $e($title) ?>">
<meta name="twitter:description" content="<?= $e($desc) ?>">
<meta name="twitter:image" content="<?= $e($image) ?>">
<link rel="icon" type="image/svg+xml" href="<?= $e($base) ?>assets/images/hastra-logo.svg">
<link rel="icon" type="image/png" sizes="32x32" href="<?= $e($base) ?>assets/images/favicon-32.png">
<link rel="alternate icon" href="<?= $e($base) ?>favicon.ico">
<link rel="apple-touch-icon" href="<?= $e($base) ?>assets/images/apple-touch-icon.png">
<?php
}
