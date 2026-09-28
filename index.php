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
<link rel="icon" type="image/svg+xml" href="<?= get_base_url() ?>assets/images/hastra-logo.svg">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Hastra · Enterprise Software Delivery &amp; Governance</title>
<meta name="description" content="Hastra: governed, auditable enterprise software delivery.">
<!-- data-loader-manual: the landing document carries its own preloader -->
<script src="<?= get_base_url() ?>core/theme.js?v=<?= ASSET_VERSION ?>" data-loader-manual></script>
<style>
  /* no overflow lock: the frame is fixed and scrolls itself, so the host
     document never has anything to scroll */
  html, body { margin: 0; background: #05070a; }
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
