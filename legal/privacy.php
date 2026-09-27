<?php
require __DIR__ . '/_layout.php';
legal_page_start('privacy', 'Privacy Policy', 'What personal data Astra collects, how it is protected, how long it is kept and how to exercise your rights.');
?>
<p>This policy explains what personal data <?= htmlspecialchars(LEGAL_ENTITY_NAME) ?> ("Astra", "we") processes when you use
  the Astra software-delivery platform, why, how it is protected, how long it is kept and the rights you have over it.
  For workspace data your organization puts into Astra, your organization is the controller and we act as its processor;
  for account, security and billing data we are the controller.</p>

<nav class="legal-toc" aria-label="On this page"><ol>
  <li><a href="#collect">Data we collect</a></li>
  <li><a href="#protect">How data is protected</a></li>
  <li><a href="#use">Why we use it</a></li>
  <li><a href="#share">Service providers</a></li>
  <li><a href="#retention">Retention and shredding</a></li>
  <li><a href="#rights">Your rights (GDPR / CCPA)</a></li>
  <li><a href="#contact">Contact</a></li>
</ol></nav>

<h2 id="collect">1. Data we collect</h2>
<table>
  <thead><tr><th scope="col">Category</th><th scope="col">What it includes</th></tr></thead>
  <tbody>
    <tr><td>Account credentials</td><td>Name, email address, phone number, role, and a password stored only as an Argon2id hash
      (never in readable form). One-time email codes used for sign-in verification. If you use Google sign-in: your Google
      account's verified email, display name and a stable Google account identifier. We do not store your Google profile photo.</td></tr>
    <tr><td>Profile</td><td>Gender (used for leave-policy eligibility), optional GitHub and LinkedIn profile links.</td></tr>
    <tr><td>Company identity</td><td>Organization name, email domain, size band, contract reference, logo, account type and leave policy.</td></tr>
    <tr><td>Attendance sync payloads</td><td>If your organization connects an attendance or biometric device, Astra receives only
      the employee email, work date, check-in time and attendance status. Astra does <strong>not</strong> receive or store
      fingerprints, face templates or any other biometric identifier.</td></tr>
    <tr><td>IP and device intelligence</td><td>Your IP address; the country, city and network provider derived from it; whether it
      appears to be a VPN, proxy or hosting address; and a one-way keyed hash (HMAC) of your browser user-agent, language and IP
      subnet used to detect session hijacking.</td></tr>
    <tr><td>Project content</td><td>Requirements, tasks, defects, test results, security-scan uploads and findings, documentation,
      delivery records, handover credentials and ephemeral dossiers.</td></tr>
    <tr><td>Transaction history</td><td>Invoices, milestone escrow records, payment status and the payment provider's transaction
      reference. Card and bank details are handled by the payment provider; Astra never receives them.</td></tr>
    <tr><td>Activity log</td><td>A record of security-relevant actions (sign-ins, approvals, changes) with the time, account and IP address.</td></tr>
  </tbody>
</table>

<h2 id="protect">2. How data is protected</h2>
<h3>Column-level encryption (AES-256-GCM)</h3>
<p>Names, email addresses, phone numbers, gender, company email domains and location details in the activity log are encrypted
  in the database with AES-256-GCM before they are written. Each value gets a fresh random 96-bit IV and a 128-bit
  authentication tag, so identical values never produce identical ciphertext and any tampering is detected on read. Keys are
  versioned so they can be rotated without re-writing everything at once, and they are stored outside the database.
  Handover credentials and ephemeral dossiers are encrypted with the same algorithm under separate keys.</p>
<h3>Blind-index search</h3>
<p>To look up an account by email without storing the email in readable form, Astra stores an HMAC-SHA256 "blind index"
  computed with a second, independent key. Blind indexes are only used for high-entropy identifiers (email, phone, domain,
  access tokens, Google account id), never for low-variety fields such as gender or role, where an index could reveal the
  value by frequency.</p>
<h3>Other safeguards</h3>
<ul>
  <li>A tamper-evident activity log in which each entry is chained to the previous one with an HMAC, so edits or deletions are detectable.</li>
  <li>Email one-time codes on password sign-in, account lock-out after repeated failures, and per-network rate limits.</li>
  <li>Session binding to the device and network that signed in, with automatic sign-out if the session is replayed elsewhere.</li>
  <li>Decoy "honeytoken" records that flag automated data extraction.</li>
</ul>
<p class="legal-note">What these measures do not cover: IP addresses in the activity log, rate-limit and IP-reputation tables are
  stored unencrypted because they are needed for real-time security decisions, and project content outside the fields named
  above is protected by access controls rather than field-level encryption. No system is immune to every attack.</p>

<h2 id="use">3. Why we use it</h2>
<ul>
  <li><strong>To provide the service</strong> you or your organization signed up for (contract).</li>
  <li><strong>To keep accounts and data secure</strong>: fraud, abuse and intrusion detection, VPN/proxy screening, audit logging (legitimate interests; legal obligations).</li>
  <li><strong>To bill and settle milestones</strong> (contract; legal obligations such as tax records).</li>
  <li><strong>To send service emails</strong> such as verification codes and notifications (contract).</li>
  <li><strong>Analytics</strong> only if you allow it in the cookie banner (consent). Astra currently runs no analytics scripts.</li>
</ul>
<p>We do not sell personal data, and we do not use it for advertising or automated decisions with legal effects.</p>

<h2 id="share">4. Service providers</h2>
<table>
  <thead><tr><th scope="col">Provider</th><th scope="col">Purpose</th><th scope="col">Data</th></tr></thead>
  <tbody>
    <tr><td>ip-api.com</td><td>IP reputation (VPN/proxy detection) and approximate location</td><td>IP address</td></tr>
    <tr><td>ipify, ipecho, icanhazip</td><td>Resolving a public IP when requests arrive over a private network</td><td>Server IP address</td></tr>
    <tr><td>Google reCAPTCHA</td><td>Bot protection on the sign-in form</td><td>Browser and interaction signals, IP address</td></tr>
    <tr><td>Google Identity (optional)</td><td>"Continue with Google" sign-in</td><td>The Google account details listed above</td></tr>
    <tr><td>Anthropic (optional, if enabled by the operator)</td><td>Drafting documentation and reading uploaded rosters</td><td>Project records and roster files you choose to process</td></tr>
    <tr><td>Clearbit, DuckDuckGo (only with third-party consent)</td><td>Suggesting a company logo during sign-up</td><td>The company domain you typed; your IP address</td></tr>
    <tr><td>jsDelivr CDN, Google Fonts</td><td>Serving the phone-number widget and one typeface</td><td>IP address (no cookies are set)</td></tr>
    <tr><td>Email and payment providers</td><td>Delivering service email; processing payments</td><td>Email address and message; invoice reference and amount</td></tr>
  </tbody>
</table>
<p>Where a provider is outside your country, transfers rely on the safeguards the law requires (for example Standard Contractual Clauses).</p>

<h2 id="retention">5. Retention and shredding</h2>
<table>
  <thead><tr><th scope="col">Data</th><th scope="col">Kept for</th></tr></thead>
  <tbody>
    <tr><td>Email one-time codes</td><td>10 minutes, then invalid; cleared on use</td></tr>
    <tr><td>Signed-in sessions</td><td>Ended after 30 minutes of inactivity</td></tr>
    <tr><td>Ephemeral dossiers</td><td>Shredded automatically after their view limit (one view by default) or their expiry window
      (24 hours by default, starting when the linked milestone is paid). Shredding overwrites the encrypted payload in the
      database with random data; database backups taken before shredding follow the backup schedule below.</td></tr>
    <tr><td>Account lock-outs and network blocks</td><td>15 minutes (account), 30 minutes (network rate limit), 72 hours (honeytoken trigger)</td></tr>
    <tr><td>Account, company and project data</td><td>For the life of the account, then deleted within 30 days of a verified deletion request or contract end, unless the law requires longer</td></tr>
    <tr><td>Invoices and payment records</td><td>As long as tax and accounting law requires (typically 6 to 8 years)</td></tr>
    <tr><td>Tamper-evident activity log</td><td>Up to 2 years for security and dispute resolution. Because entries are chained, on
      erasure we remove or encrypt-shred the personal details of an entry rather than the entry itself</td></tr>
    <tr><td>Database backups</td><td>Rotated; personal data in backups is overwritten as backups expire</td></tr>
  </tbody>
</table>

<h2 id="rights">6. Your rights (GDPR / CCPA)</h2>
<p>Depending on where you live you may have the right to access, correct, delete, restrict or object to processing of your
  personal data, to receive it in a portable format, to withdraw consent at any time (cookie choices can be changed with the
  "Cookie settings" link in any footer), and, in California, to know what we collect and to not be discriminated against for
  exercising these rights. We do not sell or share personal information for cross-context advertising.</p>
<h3>How to make a request, including deletion</h3>
<ol>
  <li>Email <?= legal_contact() ?> from the address on the account, with the subject "Data request" and what you want (access, correction, deletion, export).</li>
  <li>We verify the request comes from the account holder, for example with a one-time code sent to that address.</li>
  <li>We respond within one month (GDPR) or 45 days (CCPA), and tell you if an extension or a legal exception applies.</li>
  <li>If your account belongs to an organization's workspace, we may refer the request to that organization, as it controls that data.</li>
</ol>
<p>You may also complain to your local data-protection authority.</p>

<h2 id="contact">7. Contact</h2>
<p><?= legal_identity() ?>
  Email <?= legal_contact() ?>. We will post changes to this policy here and, for material changes, notify account holders by email.</p>
<?php legal_page_end(); ?>
