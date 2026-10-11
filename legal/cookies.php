<?php
require __DIR__ . '/_layout.php';
legal_page_start('cookies', 'Cookie Policy', 'The cookies and browser storage Hastra uses, by category, and how to change your choices.');
?>
<p>This policy lists every cookie and browser-storage item Hastra uses, grouped by category. Only
  <strong>strictly essential</strong> items are used without your consent. Everything else waits for your choice in the cookie
  banner, which you can reopen at any time with <button type="button" class="legal-linkbtn" data-consent-open>Cookie settings</button>.</p>

<h2 id="essential">1. Strictly essential</h2>
<p>Needed to sign you in, keep the session secure and protect forms. They cannot be switched off, because the service cannot work without them.</p>
<table>
  <thead><tr><th scope="col">Name</th><th scope="col">Purpose</th><th scope="col">Lifetime</th><th scope="col">Flags</th></tr></thead>
  <tbody>
    <tr><td><code>PHPSESSID</code></td><td>Session token. Server-side it also holds your CSRF token, which every form must send back.</td>
      <td>Until the browser closes; the session expires after 30 minutes of inactivity</td>
      <td><code>HttpOnly</code> (unreadable by scripts), <code>SameSite=Strict</code> (never sent on cross-site requests), <code>Secure</code> whenever the site is served over HTTPS</td></tr>
    <tr><td><code>hastra_goauth</code></td><td>Only while you use "Continue with Google": an encrypted record of the sign-in attempt (anti-forgery state, nonce and PKCE verifier), deleted on return.</td>
      <td>10 minutes</td><td><code>HttpOnly</code>, <code>SameSite=Lax</code> (so it survives Google's redirect back), <code>Secure</code> over HTTPS, limited to <code>/auth/</code></td></tr>
    <tr><td><code>hastra_consent_state</code> (local storage)</td><td>Remembers your cookie choices so the banner does not reappear.</td><td>Until you clear site data</td><td>Not sent to the server</td></tr>
    <tr><td><code>astra_theme</code> (local storage)</td><td>Remembers light or dark theme.</td><td>Until you clear site data</td><td>Not sent to the server</td></tr>
    <tr><td><code>astra_intro_played</code>, <code>astra_login_seen</code> (session storage)</td><td>Plays the intro animations once per tab.</td><td>Until the tab closes</td><td>Not sent to the server</td></tr>
    <tr><td><code>HASTRA_LABS</code></td><td>Hastra Labs session: holds the Labs form-protection token and, if you start a free Labs session, which session you are in.</td>
      <td>Until the browser closes</td><td><code>HttpOnly</code>, <code>SameSite=Lax</code>, <code>Secure</code> over HTTPS, limited to <code>/labs/</code></td></tr>
    <tr><td><code>hastra_labs_device</code></td><td>Only if you tick "remember this device" when starting a Labs session: a random token that keeps you signed in to the Labs. Stored server-side only as a keyed hash.</td>
      <td>60 days, or until you sign out</td><td><code>HttpOnly</code>, <code>SameSite=Lax</code>, <code>Secure</code> over HTTPS, limited to <code>/labs/</code></td></tr>
    <tr><td><code>hastra_labs_trial_count</code>, <code>hastra_labs_trial_at</code> (local storage)</td><td>Counts the one free Labs run you get before a free session or account is needed. No device fingerprint is taken.</td><td>Until you clear site data</td><td>Not sent to the server</td></tr>
    <tr><td><code>hastra_labs_study_v1</code> (local storage)</td><td>Your syllabi and study progress in the Syllabus Accelerator, kept on your device.</td><td>Until you remove the syllabus or clear site data</td><td>Not sent to the server unless you start a Labs session to sync it</td></tr>
    <tr><td><code>hastra_utm</code>, <code>hastra_support</code> (session storage)</td><td>Campaign tags from the link you arrived by (used only if you allow Analytics) and the support address for the contact button.</td><td>Until the tab closes</td><td>Not sent to the server on their own</td></tr>
  </tbody>
</table>

<h2 id="security">2. Security and fraud prevention</h2>
<p>Used to stop automated abuse of sign-in. We rely on legitimate interest (keeping accounts secure) rather than consent, because
  disabling them would leave sign-in open to credential-stuffing bots.</p>
<table>
  <thead><tr><th scope="col">Name</th><th scope="col">Set by</th><th scope="col">Purpose</th><th scope="col">Lifetime</th></tr></thead>
  <tbody>
    <tr><td><code>_GRECAPTCHA</code> and related</td><td>Google reCAPTCHA (sign-in page only)</td><td>Distinguishing people from bots</td><td>Up to 6 months</td></tr>
  </tbody>
</table>
<p>Hastra's own security controls (session binding, rate limits, VPN screening, honeytokens) run on the server and set no additional cookies.</p>

<h2 id="performance">3. Performance and telemetry</h2>
<p>Switched off unless you allow <strong>Analytics</strong>. Hastra uses no third-party analytics. If you opt in, your browser
  tells Hastra's own server which page you viewed and a few named actions (for example opening search or starting sign-up),
  together with the referring site's domain and any <code>utm_source</code>, <code>utm_medium</code> or <code>utm_campaign</code>
  tags in the link you followed. No cookie is set, no account or IP address is stored with the event, and a visitor is counted
  through a code that changes every day, so visits cannot be linked from one day to the next. Campaign tags are kept in your
  browser's session storage only until you close the tab. Events are deleted after 30 days. A Global Privacy Control or
  Do Not Track signal turns this off even if you opted in.</p>
<p>Separately, if a page fails with an error, your browser reports the error message and where in the code it happened, so it
  can be fixed. Email addresses, numbers, tokens and passwords are removed before it is stored, nothing identifies you, and
  reports are deleted after 30 days.</p>

<h2 id="third-party">4. Third-party integrations</h2>
<p>Switched off unless you allow <strong>Third-party integrations</strong>. When off, external content is replaced by a
  "Click to load external asset" button, so nothing is fetched from the third party until you ask for that specific item.</p>
<table>
  <thead><tr><th scope="col">Integration</th><th scope="col">Where</th><th scope="col">What is sent</th></tr></thead>
  <tbody>
    <tr><td>Clearbit logo lookup, DuckDuckGo icons</td><td>Organization sign-up, logo suggestions</td><td>The domain you typed and your IP address</td></tr>
  </tbody>
</table>
<p>The phone-number widget on sign-up (from the jsDelivr CDN) and the monospace typeface (from Google Fonts) are loaded to make
  those pages work. They set no cookies, but the providers receive your IP address when the files download.</p>

<h2 id="control">5. Managing your choices</h2>
<ul>
  <li>Use <button type="button" class="legal-linkbtn" data-consent-open>Cookie settings</button> (also in every page footer) to change your choices. Withdrawing consent stops those items loading from then on.</li>
  <li>You can also delete cookies and site data in your browser. Blocking essential cookies will prevent sign-in.</li>
</ul>
<p>Questions: <?= legal_contact() ?>.</p>
<?php legal_page_end(); ?>
