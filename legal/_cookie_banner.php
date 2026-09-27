<?php
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) { http_response_code(404); exit(); }
// Rendered through astra_consent_banner() (core/legal.php). Hidden until
// assets/js/cookie-consent.js finds no stored choice; it has no close button,
// so it stays until one of the three choices is made.
$b = get_base_url();
?>
<section id="astra-consent" class="consent-bar" role="region" aria-labelledby="consent-title" hidden>
  <div class="consent-in">
    <div class="consent-copy">
      <h2 id="consent-title">Your privacy choices</h2>
      <p>Astra uses essential cookies to keep you signed in and to protect forms. With your permission we also allow
        analytics and third-party content such as logo lookups. See the <a href="<?= $b ?>legal/cookies">Cookie Policy</a>
        and <a href="<?= $b ?>legal/privacy">Privacy Policy</a>.</p>
    </div>
    <fieldset class="consent-toggles">
      <legend class="sr-only">Cookie categories</legend>
      <label class="consent-toggle is-locked">
        <input type="checkbox" checked disabled aria-describedby="consent-essential-d">
        <span class="consent-switch" aria-hidden="true"></span>
        <span><b>Essential</b> <small id="consent-essential-d">Always on: session, CSRF and security cookies</small></span>
      </label>
      <label class="consent-toggle">
        <input type="checkbox" data-consent-cat="analytics" aria-describedby="consent-analytics-d">
        <span class="consent-switch" aria-hidden="true"></span>
        <span><b>Analytics</b> <small id="consent-analytics-d">Performance and usage telemetry</small></span>
      </label>
      <label class="consent-toggle">
        <input type="checkbox" data-consent-cat="thirdparty" aria-describedby="consent-thirdparty-d">
        <span class="consent-switch" aria-hidden="true"></span>
        <span><b>Third-party integrations</b> <small id="consent-thirdparty-d">External logos, embeds and demo media</small></span>
      </label>
    </fieldset>
    <div class="consent-actions">
      <button type="button" class="consent-btn" data-consent-action="reject">Reject non-essential</button>
      <button type="button" class="consent-btn" data-consent-action="save">Save choices</button>
      <button type="button" class="consent-btn consent-btn--primary" data-consent-action="accept">Accept all</button>
    </div>
  </div>
</section>
<script src="<?= $b ?>assets/js/cookie-consent.js?v=<?= ASSET_VERSION ?>"></script>
