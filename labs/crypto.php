<?php
// Hastra Labs — Module 1: Universal Cryptography & File Armor.
// All work happens in assets/js/labs-crypto-ui.js + pqc-crypto.js, in the
// browser. This page only renders the controls.
require __DIR__ . '/_boot.php';
labs_session_start();
$profile = labs_current_profile($conn);

$alg_groups = [
    'Symmetric · passphrase' => ['aes-256-gcm' => 'AES-256-GCM', 'chacha20-poly1305' => 'ChaCha20-Poly1305'],
    'Asymmetric · classical' => ['rsa-oaep-4096' => 'RSA-4096-OAEP', 'x25519' => 'X25519 (ECIES)'],
    'Post-quantum · NIST FIPS 203' => ['ml-kem-768' => 'ML-KEM-768', 'ml-kem-1024' => 'ML-KEM-1024'],
    'Hybrid · PQC + classical' => ['xwing' => 'X-Wing (ML-KEM-768 + X25519)'],
];
$key_algs = [
    'Encryption' => ['xwing' => 'X-Wing hybrid (recommended)', 'ml-kem-1024' => 'ML-KEM-1024', 'ml-kem-768' => 'ML-KEM-768', 'x25519' => 'X25519', 'rsa-oaep-4096' => 'RSA-4096-OAEP'],
    'Signatures' => ['ml-dsa-65' => 'ML-DSA-65', 'ml-dsa-87' => 'ML-DSA-87'],
];
function labs_options(array $groups, string $selected = ''): void {
    foreach ($groups as $label => $opts) {
        echo '<optgroup label="' . labs_e($label) . '">';
        foreach ($opts as $v => $t) echo '<option value="' . labs_e($v) . '"' . ($v === $selected ? ' selected' : '') . '>' . labs_e($t) . '</option>';
        echo '</optgroup>';
    }
}

labs_head('Cryptography & File Armor', 'crypto', $profile);
?>
<section class="lx-hero lx-hero--compact">
  <p class="lx-eyebrow"><span class="lx-dot"></span> Module 01 · runs entirely in your browser</p>
  <h1 class="lx-h1">Universal Cryptography &amp; <em>File Armor</em></h1>
  <p class="lx-lead">Seal text or any file with classical, post-quantum or hybrid encryption, sign it with ML-DSA, and hash
    credentials with the engine each kind of secret needs. Nothing you type or drop here is uploaded.</p>
</section>

<div class="lx-subnav" role="tablist" aria-label="Cryptography tools" id="cx-tabs">
  <button role="tab" aria-selected="true"  aria-controls="cx-p-seal"  id="cx-t-seal"  data-hash="seal">Encrypt &amp; Decrypt</button>
  <button role="tab" aria-selected="false" aria-controls="cx-p-keys"  id="cx-t-keys"  data-hash="keys">Key pairs</button>
  <button role="tab" aria-selected="false" aria-controls="cx-p-sign"  id="cx-t-sign"  data-hash="sign">Sign &amp; Verify</button>
  <button role="tab" aria-selected="false" aria-controls="cx-p-hash"  id="cx-t-hash"  data-hash="hash">Password &amp; Token Hasher</button>
  <button role="tab" aria-selected="false" aria-controls="cx-p-test"  id="cx-t-test"  data-hash="selftest">Self-test</button>
</div>

<!-- ═══ Encrypt & Decrypt ═══════════════════════════════════════════════ -->
<section role="tabpanel" id="cx-p-seal" aria-labelledby="cx-t-seal">
  <div class="lx-grid-2">
    <div class="lx-pane">
      <div class="lx-seg" role="radiogroup" aria-label="Direction">
        <label class="lx-seg-opt"><input type="radio" name="cx-dir" value="enc" checked><span><b>Encrypt</b>Seal text or a file</span></label>
        <label class="lx-seg-opt"><input type="radio" name="cx-dir" value="dec"><span><b>Decrypt</b>Open a .hlx envelope</span></label>
      </div>

      <div id="cx-enc" class="lx-mt">
        <h2 class="lx-h3">1 · Payload</h2>
        <label class="lx-label" for="cx-text">Text</label>
        <textarea id="cx-text" class="lx-textarea" rows="4" placeholder="Type or paste a message… or drop a file below instead."></textarea>
        <div class="lx-drop lx-mt" id="cx-drop" tabindex="0" role="button" aria-describedby="cx-drop-hint">
          <input type="file" id="cx-file" tabindex="-1" aria-label="Choose a file to encrypt">
          <b>Drop any file here</b><small id="cx-drop-hint">.pdf, .docx, .zip, .png, .bin, anything · up to 256 MB · or click to browse</small>
          <span id="cx-file-chip"></span>
        </div>

        <h2 class="lx-h3 lx-mt">2 · Algorithm</h2>
        <label class="lx-label" for="cx-alg">Encryption engine</label>
        <select id="cx-alg" class="lx-select"><?php labs_options($alg_groups, 'xwing'); ?></select>
        <p class="lx-help" id="cx-alg-note"></p>

        <div id="cx-pass-wrap" class="lx-mt" hidden>
          <label class="lx-label" for="cx-pass">Passphrase</label>
          <input type="password" id="cx-pass" class="lx-input" autocomplete="new-password" placeholder="A long passphrase you will remember">
          <div class="lx-meter lx-mt" aria-hidden="true"><i id="cx-pass-meter"></i></div>
          <p class="lx-help" id="cx-pass-strength">Strength appears here.</p>
        </div>
        <div id="cx-rcpt-wrap" class="lx-mt">
          <label class="lx-label" for="cx-rcpt">Recipient public key (.hpub.json)</label>
          <div class="lx-drop" id="cx-rcpt-drop" tabindex="0" role="button">
            <input type="file" id="cx-rcpt" accept=".json,application/json" tabindex="-1" aria-label="Load a recipient public key">
            <b>Load a public key file</b><small>Make one in <a href="#keys" data-goto="cx-t-keys">Key pairs</a>, or use a key someone sent you</small>
            <span id="cx-rcpt-chip"></span>
          </div>
        </div>

        <button id="cx-run-enc" class="lx-btn lx-btn--block lx-mt btn-beam" type="button">Encrypt</button>
      </div>

      <div id="cx-dec" class="lx-mt" hidden>
        <h2 class="lx-h3">1 · Envelope</h2>
        <div class="lx-drop" id="cx-env-drop" tabindex="0" role="button">
          <input type="file" id="cx-env" tabindex="-1" aria-label="Choose an envelope to decrypt">
          <b>Drop a .hlx envelope</b><small>or paste an armored text envelope below</small>
          <span id="cx-env-chip"></span>
        </div>
        <label class="lx-label lx-mt" for="cx-armor">Armored text</label>
        <textarea id="cx-armor" class="lx-textarea" rows="4" placeholder="-----BEGIN HASTRA LABS ENVELOPE-----"></textarea>

        <h2 class="lx-h3 lx-mt">2 · Key</h2>
        <div id="cx-dpass-wrap">
          <label class="lx-label" for="cx-dpass">Passphrase <span class="lx-muted">(for AES-256-GCM / ChaCha20 envelopes)</span></label>
          <input type="password" id="cx-dpass" class="lx-input" autocomplete="current-password">
        </div>
        <div class="lx-drop lx-mt" id="cx-sk-drop" tabindex="0" role="button">
          <input type="file" id="cx-sk" accept=".json,application/json" tabindex="-1" aria-label="Load a secret key">
          <b>Or load a secret key (.hkey.json)</b><small>for RSA, X25519, ML-KEM and X-Wing envelopes</small>
          <span id="cx-sk-chip"></span>
        </div>
        <label class="lx-label lx-mt" for="cx-skpass">Secret-key passphrase <span class="lx-muted">(only if the key file is protected)</span></label>
        <input type="password" id="cx-skpass" class="lx-input" autocomplete="off">
        <button id="cx-run-dec" class="lx-btn lx-btn--block lx-mt btn-beam" type="button">Decrypt</button>
      </div>
    </div>

    <div class="lx-pane">
      <h2 class="lx-h3">Result</h2>
      <div id="cx-result" class="lx-stack"><pre class="lx-out" id="cx-out">// Your envelope or plaintext appears here. Nothing leaves this tab.</pre></div>
      <hr class="lx-sep">
      <h2 class="lx-h3">Envelope anatomy</h2>
      <dl class="lx-kv" id="cx-anatomy"><dt>Format</dt><dd>"HLX1" · u32 header length · JSON header · AEAD ciphertext. The header is authenticated as associated data.</dd><dd></dd></dl>
    </div>
  </div>
</section>

<!-- ═══ Key pairs ════════════════════════════════════════════════════════ -->
<section role="tabpanel" id="cx-p-keys" aria-labelledby="cx-t-keys" hidden>
  <div class="lx-grid-2">
    <div class="lx-pane">
      <h2 class="lx-h2">Generate a key pair</h2>
      <p class="lx-muted">Keys are created with your browser's CSPRNG and never leave this page unless you download them.
        Share the <b>public</b> file; keep the <b>secret</b> file private.</p>
      <label class="lx-label lx-mt" for="kx-alg">Algorithm</label>
      <select id="kx-alg" class="lx-select"><?php labs_options($key_algs, 'xwing'); ?></select>
      <p class="lx-help" id="kx-note"></p>
      <label class="lx-label lx-mt" for="kx-pass">Protect the secret key with a passphrase <span class="lx-muted">(optional, recommended)</span></label>
      <input type="password" id="kx-pass" class="lx-input" autocomplete="new-password">
      <button id="kx-run" class="lx-btn lx-btn--block lx-mt btn-beam" type="button">Generate key pair</button>
      <div id="kx-out" class="lx-mt"></div>
    </div>
    <div class="lx-pane">
      <h2 class="lx-h2">Sizes at a glance</h2>
      <div class="lx-tablewrap">
        <table class="lx-table">
          <thead><tr><th>Algorithm</th><th>Public key</th><th>Secret key</th><th>Ciphertext / signature</th><th>Quantum-safe</th></tr></thead>
          <tbody>
            <tr><td>X25519</td><td>32 B</td><td>32 B</td><td>32 B (ephemeral key)</td><td>No</td></tr>
            <tr><td>RSA-4096-OAEP</td><td>~550 B</td><td>~2.4 KB</td><td>512 B</td><td>No</td></tr>
            <tr><td>ML-KEM-768</td><td>1,184 B</td><td>2,400 B</td><td>1,088 B</td><td>Yes · cat. 3</td></tr>
            <tr><td>ML-KEM-1024</td><td>1,568 B</td><td>3,168 B</td><td>1,568 B</td><td>Yes · cat. 5</td></tr>
            <tr><td>X-Wing hybrid</td><td>1,216 B</td><td>32 B (seed)</td><td>1,120 B</td><td>Yes, and classical too</td></tr>
            <tr><td>ML-DSA-65</td><td>1,952 B</td><td>4,032 B</td><td>3,309 B</td><td>Yes · cat. 3</td></tr>
            <tr><td>ML-DSA-87</td><td>2,592 B</td><td>4,896 B</td><td>4,627 B</td><td>Yes · cat. 5</td></tr>
          </tbody>
        </table>
      </div>
      <p class="lx-help">“Harvest now, decrypt later”: anything encrypted today with RSA or X25519 alone can be recorded and
        opened once a large quantum computer exists. Use ML-KEM or the X-Wing hybrid for data that must stay secret for years.</p>
    </div>
  </div>
</section>

<!-- ═══ Sign & Verify ════════════════════════════════════════════════════ -->
<section role="tabpanel" id="cx-p-sign" aria-labelledby="cx-t-sign" hidden>
  <div class="lx-grid-2">
    <div class="lx-pane">
      <h2 class="lx-h2">Sign with ML-DSA</h2>
      <label class="lx-label" for="sx-text">Message</label>
      <textarea id="sx-text" class="lx-textarea" rows="3" placeholder="Type a message… or drop a file below"></textarea>
      <div class="lx-drop lx-mt" id="sx-drop" tabindex="0" role="button">
        <input type="file" id="sx-file" tabindex="-1" aria-label="Choose a file to sign">
        <b>Drop a file to sign</b><small>the signature covers every byte</small><span id="sx-file-chip"></span>
      </div>
      <div class="lx-drop lx-mt" id="sx-sk-drop" tabindex="0" role="button">
        <input type="file" id="sx-sk" accept=".json,application/json" tabindex="-1" aria-label="Load an ML-DSA secret key">
        <b>Load an ML-DSA secret key</b><small>.hkey.json from Key pairs</small><span id="sx-sk-chip"></span>
      </div>
      <label class="lx-label lx-mt" for="sx-skpass">Secret-key passphrase <span class="lx-muted">(if protected)</span></label>
      <input type="password" id="sx-skpass" class="lx-input" autocomplete="off">
      <button id="sx-run" class="lx-btn lx-btn--block lx-mt btn-beam" type="button">Sign</button>
    </div>
    <div class="lx-pane">
      <h2 class="lx-h2">Verify a signature</h2>
      <label class="lx-label" for="vx-text">Message</label>
      <textarea id="vx-text" class="lx-textarea" rows="3" placeholder="The exact message that was signed… or drop the file"></textarea>
      <div class="lx-drop lx-mt" id="vx-drop" tabindex="0" role="button">
        <input type="file" id="vx-file" tabindex="-1" aria-label="Choose the signed file">
        <b>Drop the signed file</b><small>byte-for-byte the original</small><span id="vx-file-chip"></span>
      </div>
      <div class="lx-grid-2 lx-mt">
        <div class="lx-drop" id="vx-sig-drop" tabindex="0" role="button">
          <input type="file" id="vx-sig" accept=".json,application/json" tabindex="-1" aria-label="Load the signature file">
          <b>Signature</b><small>.hsig.json</small><span id="vx-sig-chip"></span>
        </div>
        <div class="lx-drop" id="vx-pk-drop" tabindex="0" role="button">
          <input type="file" id="vx-pk" accept=".json,application/json" tabindex="-1" aria-label="Load the signer's public key">
          <b>Signer's key</b><small>.hpub.json</small><span id="vx-pk-chip"></span>
        </div>
      </div>
      <button id="vx-run" class="lx-btn lx-btn--block lx-mt btn-beam" type="button">Verify</button>
      <div id="sx-out" class="lx-mt"></div>
    </div>
  </div>
</section>

<!-- ═══ Password & Token Hasher ══════════════════════════════════════════ -->
<section role="tabpanel" id="cx-p-hash" aria-labelledby="cx-t-hash" hidden>
  <div class="lx-grid-2">
    <div class="lx-pane">
      <h2 class="lx-h2">What kind of secret is this?</h2>
      <div class="lx-seg" role="radiogroup" aria-label="Credential classification">
        <label class="lx-seg-opt"><input type="radio" name="hx-class" value="user_password" checked><span><b>Normal user password</b>A human types it at a login. Low entropy, so hash it slowly and memory-hard.</span></label>
        <label class="lx-seg-opt"><input type="radio" name="hx-class" value="app_secret"><span><b>App secret / machine token</b>An API key or service credential. High entropy, checked on every request, so hash it fast and keyed.</span></label>
      </div>
      <label class="lx-label lx-mt" for="hx-engine">Hashing engine</label>
      <select id="hx-engine" class="lx-select"></select>
      <p class="lx-help" id="hx-engine-note"></p>

      <label class="lx-label lx-mt" for="hx-secret">Secret</label>
      <div class="lx-row" style="flex-wrap:nowrap">
        <input type="password" id="hx-secret" class="lx-input" autocomplete="off" spellcheck="false" placeholder="Type a password or paste an API secret">
        <button type="button" class="lx-btn lx-btn--ghost lx-btn--sm" id="hx-show" aria-pressed="false">Show</button>
      </div>
      <div class="lx-row lx-mt" id="hx-gen-row" hidden>
        <button type="button" class="lx-btn lx-btn--ghost lx-btn--sm" id="hx-gen">Generate a 256-bit token</button>
      </div>
      <div id="hx-key-wrap" class="lx-mt" hidden>
        <label class="lx-label" for="hx-key">Hash key / pepper <span class="lx-muted">(64 hex chars; leave empty to generate one)</span></label>
        <input id="hx-key" class="lx-input lx-mono" spellcheck="false" autocomplete="off">
      </div>
      <button id="hx-run" class="lx-btn lx-btn--block lx-mt btn-beam" type="button">Derive hardened hash</button>

      <hr class="lx-sep">
      <h2 class="lx-h3">Strength</h2>
      <div class="lx-row lx-row--between"><b id="hx-bits">— bits</b><span class="lx-badge lx-badge--mute" id="hx-rating">empty</span></div>
      <div class="lx-meter lx-mt" aria-hidden="true"><i id="hx-meter"></i></div>
      <ul class="lx-rules lx-mt" id="hx-findings"></ul>
      <div class="lx-tablewrap lx-mt"><table class="lx-table"><thead><tr><th>Attacker</th><th>Average time to guess</th></tr></thead><tbody id="hx-crack"></tbody></table></div>
      <p class="lx-help">Guess rates are rough planning assumptions for one GPU rig (10/s online, 10¹¹/s unsalted SHA-256,
        2×10⁴/s bcrypt-12, 500/s Argon2id-64MiB), not a guarantee.</p>
    </div>
    <div class="lx-pane">
      <h2 class="lx-h3">Derived hash</h2>
      <dl class="lx-kv" id="hx-out"><dt>Status</dt><dd>Nothing derived yet.</dd><dd></dd></dl>
      <hr class="lx-sep">
      <h2 class="lx-h3">Verification tester</h2>
      <label class="lx-label" for="hv-stored">Stored hash</label>
      <textarea id="hv-stored" class="lx-textarea" rows="3" spellcheck="false" placeholder="$argon2id$v=19$… · $2b$12$… · $pbkdf2-sha512$… · hmac-sha256$kid=…$… · blake3-keyed$kid=…$…"></textarea>
      <label class="lx-label lx-mt" for="hv-key">Key <span class="lx-muted">(HMAC / BLAKE3 only)</span></label>
      <input id="hv-key" class="lx-input lx-mono" spellcheck="false" autocomplete="off">
      <label class="lx-label lx-mt" for="hv-candidate">Candidate secret</label>
      <input type="password" id="hv-candidate" class="lx-input" autocomplete="off">
      <button id="hv-run" class="lx-btn lx-btn--block lx-mt" type="button">Verify</button>
      <div id="hv-out" class="lx-mt" role="status"></div>
    </div>
  </div>
</section>

<!-- ═══ Self-test ════════════════════════════════════════════════════════ -->
<section role="tabpanel" id="cx-p-test" aria-labelledby="cx-t-test" hidden>
  <div class="lx-pane">
    <h2 class="lx-h2">Cryptographic self-test</h2>
    <p class="lx-muted">Runs published known-answer vectors (RFC 4231 HMAC, PBKDF2-SHA512, BLAKE3) and a full round trip through
      every engine, including a tampered envelope and a forged signature that must both be rejected.</p>
    <button id="tx-run" class="lx-btn lx-mt btn-beam" type="button">Run self-test</button>
    <div class="lx-tablewrap lx-mt"><table class="lx-table"><thead><tr><th>Check</th><th>Result</th><th>Time</th></tr></thead><tbody id="tx-out"><tr><td colspan="3" class="lx-muted">Not run yet.</td></tr></tbody></table></div>
  </div>
</section>
<?php labs_foot(['labs-common', 'labs-crypto-ui']); ?>
