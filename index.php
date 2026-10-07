<?php
require __DIR__ . '/core/db.php';
secure_session_start();
if (isset($_SESSION["user_id"])) {
    header("Location: " . APP_URL . "workspace/");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Hastra | Secure Software Delivery &amp; Free Security Labs</title>
<?php astra_seo_meta([
    'title' => 'Hastra | Secure Software Delivery & Free Security Labs',
    'description' => 'Hastra runs requirements, projects, testing, deployment, billing and credentials in one encrypted, audited workspace, with free browser-native labs for post-quantum cryptography, SIEM and a syllabus accelerator.',
    'path' => '',
]); ?>
<script type="application/ld+json"><?= json_encode([
    '@context' => 'https://schema.org', '@type' => 'Organization', 'name' => 'Hastra',
    'url' => rtrim(APP_URL, '/') . '/', 'logo' => rtrim(APP_URL, '/') . '/assets/images/apple-touch-icon.png',
], JSON_UNESCAPED_SLASHES) ?></script>
<!-- data-loader-manual: the landing document carries its own preloader -->
<script src="<?= get_base_url() ?>core/theme.js?v=<?= ASSET_VERSION ?>" data-loader-manual></script>
<style>
  /* no overflow lock: the frame is fixed and scrolls itself, so the host
     document never has anything to scroll */
  html, body { margin: 0; background: #05070a; }
  .sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
  html[data-theme="light"], html[data-theme="light"] body { background: #f6ecd2; }
  /* LandingPageFrame: a full-size, overflow-controlled host */
  .landing-page-frame { position: fixed; inset: 0; overflow: hidden; background: #080808; }
  html[data-theme="light"] .landing-page-frame { background: #f6ecd2; }
  .landing-page-frame iframe {
    position: absolute; inset: 0; display: block; width: 100%; height: 100%;
    border: 0; background: inherit;
  }

</style>
</head>
<body data-atmosphere-off>

<div class="sr-only">
  <h1>Hastra: secure software delivery and free security labs</h1>
  <p>Hastra runs requirements, projects, testing, deployment, billing and credentials in one encrypted, audited workspace.
    The free Labs add post-quantum cryptography, SIEM and threat-intel simulators, and a syllabus accelerator, all running in your browser.</p>
  <nav aria-label="Hastra"><a href="<?= get_base_url() ?>labs/">Hastra Labs</a> <a href="<?= get_base_url() ?>signin">Sign in</a> <a href="<?= get_base_url() ?>signup">Create an account</a></nav>
</div>

<div class="threeui-background landing-page-frame" data-state="loading">
  <!-- allow-top-navigation-by-user-activation: Sign In / Get Started in the
       document's own nav open the auth pages in this window, on a click only -->
  <iframe id="landingFrame" title="Hastra · Enterprise Software Delivery &amp; Governance"
          src="<?= get_base_url() ?>landing-pages/hastra.html?v=<?= ASSET_VERSION ?>"
          sandbox="allow-downloads allow-forms allow-modals allow-popups allow-same-origin allow-scripts allow-top-navigation-by-user-activation"
          loading="eager"></iframe>
</div>


<script src="<?= get_base_url() ?>assets/js/landing-host.js?v=<?= ASSET_VERSION ?>"></script>
<?php astra_consent_banner(); ?>
</body>
</html>
