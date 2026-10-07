<?php
// Hastra Labs — public entry point for the free community tools and student
// hub. No enterprise organisation, account or sign-in is needed to use it.
require __DIR__ . '/_boot.php';
labs_session_start();
$profile = labs_current_profile($conn);
$lb = labs_base();

labs_head('Free engineering tools & student hub', 'hub', $profile);
?>
<section class="lx-hero">
  <p class="lx-eyebrow"><span class="lx-dot"></span> Hastra Labs · free &amp; open to everyone</p>
  <h1 class="lx-h1">Security tools and a study coach, <em>running in your browser</em>.</h1>
  <p class="lx-lead">Encrypt files with classical or post-quantum cryptography, hash credentials the right way, practise SOC
    engineering on live calculators, and turn any syllabus into a paced video course. No enterprise account needed, and
    no sign-in unless you want your study progress to follow you between devices.</p>
</section>

<div class="lx-cards">
  <a class="lx-card" href="<?= $lb ?>crypto">
    <span class="lx-card-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="10" rx="2"/><path d="M7 11V8a5 5 0 0 1 10 0v3"/><circle cx="12" cy="16" r="1.6"/></svg></span>
    <h2>Cryptography &amp; File Armor</h2>
    <p>Encrypt text or any file with AES-256-GCM, ChaCha20-Poly1305, RSA-4096, X25519, ML-KEM or the X-Wing hybrid.
      Sign with ML-DSA. Hash passwords and machine tokens with the engine each one needs.</p>
    <ul><li>FIPS 203 ML-KEM</li><li>FIPS 204 ML-DSA</li><li>Argon2id</li><li>BLAKE3</li></ul>
    <span class="lx-card-go">Open vault &rarr;</span>
  </a>
  <a class="lx-card" href="<?= $lb ?>siem">
    <span class="lx-card-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><path d="M12 12 18.5 5.5"/><circle cx="12" cy="12" r="1.2" fill="currentColor"/></svg></span>
    <h2>SIEM / Threat Intel &amp; SOC Lab</h2>
    <p>Normalize STIX 2.1, TAXII and MISP feeds, score UEBA risk live, size a 15M-EPS pipeline across hot, warm and cold
      tiers, and correlate M365, CloudTrail and Kubernetes logs on one timeline.</p>
    <ul><li>STIX 2.1</li><li>UEBA</li><li>Storage tiering</li><li>Multi-cloud</li></ul>
    <span class="lx-card-go">Enter the lab &rarr;</span>
  </a>
  <a class="lx-card" href="<?= $lb ?>syllabus">
    <span class="lx-card-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 9l10-5 10 5-10 5z"/><path d="M6 11v5c0 1.5 3 3 6 3s6-1.5 6-3v-5"/><path d="M22 9v6"/></svg></span>
    <h2>Syllabus Accelerator &amp; Video Tutor</h2>
    <p>Drop in PDF, Word or text syllabi. Hastra extracts every unit and topic, pairs each with the most-viewed tutorial,
      forecasts your finish date, and keeps you on pace.</p>
    <ul><li>PDF · DOCX · TXT</li><li>View-ranked videos</li><li>Pace forecast</li></ul>
    <span class="lx-card-go">Start studying &rarr;</span>
  </a>
</div>

<div class="lx-pledge">
  <div><b>Your data stays with you</b><p>Encryption, hashing, key generation and document parsing all happen in your
    browser. Plaintext, passwords and keys are never uploaded.</p></div>
  <div><b>Audited primitives, pinned</b><p>WebCrypto for AES, RSA, HMAC and PBKDF2; the audited @noble libraries for ML-KEM,
    ML-DSA, X25519, ChaCha20 and BLAKE3; hash-wasm for Argon2id and bcrypt.</p></div>
  <div><b>Optional, password-free sync</b><p><?php if ($profile): ?>You're signed in as <b><?= labs_e($profile['name']) ?></b>;
    your study progress syncs automatically.<?php else: ?>Start a <a href="<?= $lb ?>auth">free session</a> to sync study
    progress. You get a recovery code instead of a password.<?php endif; ?></p></div>
</div>
<?php labs_foot(['labs-common']); ?>
