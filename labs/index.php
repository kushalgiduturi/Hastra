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
    engineering on live calculators, and turn any syllabus into a paced video course. Your first run is free with no sign-up;
    after that, a free Labs session or Hastra account keeps the tools unlimited. No card, no paid tier.</p>
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

<section class="lx-faq" aria-labelledby="lx-faq-title">
  <h2 id="lx-faq-title">Questions people ask</h2>
  <details>
    <summary>Is Hastra Labs really free?</summary>
    <p>Yes. Every Labs tool is free. Your first run needs no sign-up; after that a free Labs session (no password, just a
      recovery code) or a free Hastra account keeps the tools unlimited. There is no card, trial period or paid Labs tier.</p>
  </details>
  <details>
    <summary>Do my files, messages or passwords leave my computer?</summary>
    <p>No. Encryption, hashing, key generation and document parsing run in your browser with WebCrypto and audited
      open-source libraries. Plaintext, passphrases and private keys are never uploaded. If you start a Labs session, only
      your study progress is synced.</p>
  </details>
  <details>
    <summary>Is the post-quantum cryptography production grade?</summary>
    <p>The ML-KEM (FIPS 203) and ML-DSA (FIPS 204) implementations come from the audited @noble libraries, pinned to fixed
      versions. The Labs are for learning and for protecting your own files; for regulated production systems, use a
      certified module and your organization's key management.</p>
  </details>
  <details>
    <summary>Can I lose what I encrypted?</summary>
    <p>Yes, if you lose the passphrase or private key. Hastra never sees them, so nobody, including us, can recover the data.
      Keep the key file somewhere safe before you delete the original.</p>
  </details>
  <details>
    <summary>How do I delete my Labs data?</summary>
    <p>Study progress kept in your browser is removed with the Remove button in the Syllabus Accelerator or by clearing site
      data. A synced Labs session or Hastra account can be erased at any time from
      <a href="<?= labs_e(get_base_url()) ?>privacy/delete-data">Delete my data</a>.</p>
  </details>
</section>
<?php labs_foot(['labs-common']); ?>
