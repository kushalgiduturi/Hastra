<?php
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) { http_response_code(404); exit(); }
// Astra — shared compliance fragments: the legal footer, the cookie-consent
// banner and the "Continue with Google" button. Every public page renders
// these through the functions below so the wording stays identical.

const ASTRA_LEGAL_DOCS = [
    'privacy' => 'Privacy Policy',
    'terms'   => 'Terms & Conditions',
    'cookies' => 'Cookie Policy',
    'refunds' => 'Refund Policy',
];

function astra_compliance_css(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    echo '<link rel="stylesheet" href="' . get_base_url() . 'assets/css/compliance.css?v=' . ASSET_VERSION . '">' . "\n";
}

// Business identity + policy links. $variant 'card' sits inside an auth card,
// 'page' is a full-width footer.
function astra_legal_footer(string $variant = 'card'): void {
    astra_compliance_css();
    $b = get_base_url();
    $e = fn($s) => htmlspecialchars($s, ENT_QUOTES);
    ?>
<footer class="legal-foot legal-foot--<?= $e($variant) ?>" aria-label="Legal and company information">
  <nav class="legal-links" aria-label="Legal documents">
    <?php foreach (ASTRA_LEGAL_DOCS as $slug => $title): ?>
      <a href="<?= $b ?>legal/<?= $slug ?>"><?= $e($title) ?></a>
    <?php endforeach; ?>
    <button type="button" class="legal-linkbtn" data-consent-open>Cookie settings</button>
  </nav>
  <address class="legal-entity">
    <span>&copy; <?= date('Y') ?> <?= $e(LEGAL_ENTITY_NAME) ?></span>
    <?php if (LEGAL_ADDRESS !== ''): ?><span><?= $e(LEGAL_ADDRESS) ?></span><?php endif; ?>
    <?php if (LEGAL_COMPANY_ID !== ''): ?><span>Company ID: <?= $e(LEGAL_COMPANY_ID) ?></span><?php endif; ?>
    <span>Support: <a href="mailto:<?= $e(LEGAL_SUPPORT_EMAIL) ?>"><?= $e(LEGAL_SUPPORT_EMAIL) ?></a></span>
  </address>
</footer>
    <?php
}

function astra_consent_banner(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    astra_compliance_css();
    include __DIR__ . '/../legal/_cookie_banner.php';
}

// A link for sign-in, or a submit button carrying the form it sits in (the
// registration form posts its track and workspace details along with it).
function astra_google_button(string $mode = 'link', string $label = 'Continue with Google'): void {
    astra_compliance_css();
    $g = '<svg class="google-icon" width="18" height="18" viewBox="0 0 18 18" aria-hidden="true" focusable="false">'
       . '<path fill="#4285F4" d="M17.64 9.2c0-.64-.06-1.25-.16-1.84H9v3.48h4.84a4.14 4.14 0 0 1-1.8 2.72v2.26h2.92c1.7-1.57 2.68-3.88 2.68-6.62z"/>'
       . '<path fill="#34A853" d="M9 18c2.43 0 4.47-.8 5.96-2.18l-2.92-2.26c-.8.54-1.84.86-3.04.86-2.34 0-4.33-1.58-5.04-3.71H.96v2.33A9 9 0 0 0 9 18z"/>'
       . '<path fill="#FBBC05" d="M3.96 10.71A5.41 5.41 0 0 1 3.68 9c0-.59.1-1.17.28-1.71V4.96H.96A9 9 0 0 0 0 9c0 1.45.35 2.83.96 4.04l3-2.33z"/>'
       . '<path fill="#EA4335" d="M9 3.58c1.32 0 2.5.45 3.44 1.35l2.58-2.58A9 9 0 0 0 .96 4.96l3 2.33C4.67 5.16 6.66 3.58 9 3.58z"/>'
       . '</svg>';
    $text = '<span>' . htmlspecialchars($label) . '</span>';
    if ($mode === 'submit') {
        echo '<button type="submit" class="btn-google-sso" formaction="' . get_base_url() . 'auth/google_auth.php" formnovalidate'
           . ' name="action" value="register" data-google-sso aria-label="Sign up with your Google account">' . $g . $text . '</button>';
    } else {
        echo '<a href="' . get_base_url() . 'auth/google_auth.php?action=login" class="btn-google-sso" role="button"'
           . ' aria-label="Sign in with your Google account">' . $g . $text . '</a>';
    }
}

function astra_sso_divider(string $text = 'or continue with email'): void {
    echo '<div class="sso-divider" role="separator"><span>' . htmlspecialchars($text) . '</span></div>';
}

// Messages for ?google_error=… — a fixed map, so nothing from the URL is echoed.
function astra_google_error_message(): string {
    $m = [
        'not_configured'   => 'Google sign-in isn\'t configured on this server yet. Use your email and password.',
        'expired'          => 'That Google sign-in attempt expired. Please try again.',
        'cancelled'        => 'Google sign-in was cancelled.',
        'verify_failed'    => 'We couldn\'t verify your Google account. Please try again.',
        'vpn'              => 'VPN or Proxy connection detected. Please disable your VPN to continue into the Astra platform.',
        'ip_locked'        => 'Too many attempts from your network. Please try again later.',
        'locked'           => 'This account is temporarily locked. Try again later.',
        'google_mismatch'  => 'This Astra account is linked to a different Google account.',
        'no_account'       => 'There\'s no Astra account for that Google email yet. Choose a track below to create one.',
        'terms_required'   => 'Please accept the Terms and Conditions and acknowledge the Privacy Policy to continue.',
        'bad_track'        => 'Choose a registration track first.',
        'company_required' => 'Enter your organization\'s name before continuing with Google.',
        'size_required'    => 'Choose your company\'s size before continuing with Google.',
        'country_required' => 'Enter your country before continuing with Google.',
        'too_long'         => 'One of the workspace fields is too long.',
        'company_taken'    => 'That organization is already registered on Astra. Ask its admin to add you to the team.',
        'create_failed'    => 'Your Google account was verified but the workspace couldn\'t be created. Please contact support.',
    ];
    return $m[$_GET['google_error'] ?? ''] ?? '';
}
