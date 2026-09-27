<?php
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) { http_response_code(404); exit(); }
// core/authkit.php
// Render helpers for the AuthKit "Frosted Glass Cathedral at Midnight"
// design system (assets/css/theme-authkit.css + authkit-ambient.css +
// authkit-typography.css). A page adopting AuthKit loads theme-authkit.css
// after core/theme.css, then uses these instead of hand-rolling the
// ambient background or the eyebrow/heading/subtitle lockup each time.

// Background layers: a fixed obsidian canvas plus a spotlight beam that runs
// the full document height (the beam is absolutely positioned against
// <body>, which authkit-ambient.css makes position:relative). Call once,
// right after <body> opens. Real page content needs `class="authkit-content"`
// on its outermost wrapper so it stacks above both layers.
function render_authkit_ambient() {
    ?>
    <div class="authkit-ambient" aria-hidden="true"></div>
    <div class="authkit-beam" aria-hidden="true"></div>
    <?php
}

// The eyebrow + Skywash-gradient heading + subtitle lockup used in place of
// a plain section heading. $eyebrow is optional — pass '' to omit it and
// its flanking lines.
function render_authkit_heading(string $heading, string $subtitle = '', string $eyebrow = '') {
    $heading_html  = htmlspecialchars($heading);
    $subtitle_html = htmlspecialchars($subtitle);
    $eyebrow_html  = htmlspecialchars($eyebrow);
    ?>
    <div class="authkit-lockup">
      <?php if ($eyebrow !== ''): ?>
      <div class="authkit-eyebrow">
        <span class="authkit-eyebrow-line" aria-hidden="true"></span>
        <span class="authkit-eyebrow-label"><?= $eyebrow_html ?></span>
        <span class="authkit-eyebrow-line" aria-hidden="true"></span>
      </div>
      <?php endif; ?>
      <h2 class="authkit-heading"><?= $heading_html ?></h2>
      <?php if ($subtitle !== ''): ?>
      <p class="authkit-subtitle"><?= $subtitle_html ?></p>
      <?php endif; ?>
    </div>
    <?php
}
