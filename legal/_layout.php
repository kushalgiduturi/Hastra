<?php
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) { http_response_code(404); exit(); }
// Shared frame for the policy documents in legal/. These are templates drawn
// from how this codebase actually handles data; have qualified counsel review
// them (and fill in the LEGAL_* placeholders in config/config.php) before
// relying on them.
require_once __DIR__ . '/../core/db.php';
secure_session_start();

function legal_page_start(string $slug, string $title, string $summary): void {
    $b = get_base_url();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($title) ?> · Astra</title>
<meta name="description" content="<?= htmlspecialchars($summary) ?>">
<script src="<?= $b ?>core/theme.js?v=<?= ASSET_VERSION ?>"></script>
<link rel="stylesheet" href="<?= $b ?>core/theme.css?v=<?= ASSET_VERSION ?>">
<link rel="stylesheet" href="<?= $b ?>assets/css/theme-authkit.css?v=<?= ASSET_VERSION ?>">
<?php astra_compliance_css(); ?>
</head>
<body class="legal-page" data-atmosphere-scene-off>
<a class="sr-only" href="#legal-main">Skip to content</a>
<header class="legal-top">
  <a class="legal-brand" href="<?= $b ?>">
    <svg viewBox="0 0 48 48" role="img" aria-label="Astra star brandmark logo"><path fill="currentColor" d="M24 3 L45 45 H34.4 L24 24.2 L13.6 45 H3 Z"/><path fill="var(--accent)" d="M24 14.5 L27.7 22 L35 25.5 L27.7 29 L24 36.5 L20.3 29 L13 25.5 L20.3 22 Z"/></svg>
    <span>Astra</span>
  </a>
  <nav aria-label="Legal documents">
    <?php foreach (ASTRA_LEGAL_DOCS as $s => $t): ?>
      <a href="<?= $b ?>legal/<?= $s ?>"<?= $s === $slug ? ' aria-current="page"' : '' ?>><?= htmlspecialchars($t) ?></a>
    <?php endforeach; ?>
  </nav>
  <button type="button" class="legal-theme" onclick="toggleTheme()" aria-label="Switch between light and dark theme">Theme</button>
</header>
<main id="legal-main" class="legal-doc">
  <h1><?= htmlspecialchars($title) ?></h1>
  <p class="legal-meta">Effective <?= htmlspecialchars(LEGAL_EFFECTIVE_DATE) ?> · Version <?= htmlspecialchars(LEGAL_TERMS_VERSION) ?> · Operated by <?= htmlspecialchars(LEGAL_ENTITY_NAME) ?></p>
    <?php
}

function legal_page_end(): void {
    ?>
</main>
<?php astra_legal_footer('page'); ?>
<?php astra_consent_banner(); ?>
</body>
</html>
    <?php
}

// "Name, address. Company ID …." with whichever details are configured.
function legal_identity(): string {
    $parts = [htmlspecialchars(LEGAL_ENTITY_NAME)];
    if (LEGAL_ADDRESS !== '') $parts[] = htmlspecialchars(LEGAL_ADDRESS);
    $s = implode(', ', $parts) . '.';
    if (LEGAL_COMPANY_ID !== '') $s .= ' Company ID ' . htmlspecialchars(LEGAL_COMPANY_ID) . '.';
    return $s;
}

// Shorthand for the operator's contact line used throughout the documents.
function legal_contact(): string {
    $m = htmlspecialchars(LEGAL_SUPPORT_EMAIL);
    return '<a href="mailto:' . $m . '">' . $m . '</a>';
}
