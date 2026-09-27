<?php
require __DIR__ . '/_layout.php';
legal_page_start('terms', 'Terms & Conditions', 'The terms that govern use of the Hastra platform.');
?>
<p>These terms are an agreement between you (and, if you register for an organization, that organization) and
  <?= htmlspecialchars(LEGAL_ENTITY_NAME) ?> for use of Hastra. By creating an account or signing in you confirm you have read
  them and the <a href="privacy">Privacy Policy</a>. If you do not agree, do not use Hastra.</p>

<nav class="legal-toc" aria-label="On this page"><ol>
  <li><a href="#accounts">Accounts and workspaces</a></li>
  <li><a href="#use">Acceptable use</a></li>
  <li><a href="#tenancy">Multi-tenant workspace boundaries</a></li>
  <li><a href="#governance">SSDLC governance and sign-offs</a></li>
  <li><a href="#milestones">Dual-key milestones, escrow and liability</a></li>
  <li><a href="#sla">Availability</a></li>
  <li><a href="#suspension">Suspension and termination</a></li>
  <li><a href="#ip">Intellectual property</a></li>
  <li><a href="#liability">Warranties and limitation of liability</a></li>
  <li><a href="#general">General</a></li>
</ol></nav>

<h2 id="accounts">1. Accounts and workspaces</h2>
<ul>
  <li>You must give accurate details and keep your credentials, email account and any linked Google account secure. You are responsible for activity under your account.</li>
  <li>The person who registers an organization becomes its administrator and may add, change and remove members. The organization, not Hastra, decides who may access its workspace.</li>
  <li>You must be old enough to enter a binding contract where you live.</li>
</ul>

<h2 id="use">2. Acceptable use</h2>
<p>You must not:</p>
<ul>
  <li>access, probe or attempt to access another organization's workspace, or any account, data or system you are not authorized to use;</li>
  <li>scrape, bulk-export or automatically extract data outside the features Hastra provides;</li>
  <li>submit or interact with decoy ("honeytoken") identifiers, or otherwise attempt to discover or bypass security controls;</li>
  <li>evade network controls, including by rotating IP addresses, using anonymizing proxies or VPNs where the sign-in screen blocks them, or exceeding rate limits;</li>
  <li>upload malware, or content you do not have the right to share, or that infringes others' rights or the law;</li>
  <li>use Hastra to send spam or to harass others, or resell it without our written permission.</li>
</ul>
<p>Security research is welcome only with our prior written agreement; contact <?= legal_contact() ?>.</p>

<h2 id="tenancy">3. Multi-tenant workspace boundaries</h2>
<p>Each organization's data is logically separated and scoped to that organization. Client and delivery organizations only see
  records that are shared with them through a project. You agree not to attempt to cross these boundaries. If you become
  aware that you can see another organization's data, stop, do not use it, and tell us immediately.</p>

<h2 id="governance">4. SSDLC governance and sign-offs</h2>
<p>Hastra enforces workflow gates (for example: requirements must be versioned, tests and security checks recorded, and
  approvals captured before a stage advances). These gates record who approved what and when. They are tools that support your
  process; they do not replace your own review. An approval given in Hastra by an authorized member binds the organization that
  member belongs to, and it is recorded in the tamper-evident activity log.</p>

<h2 id="milestones">5. Dual-key milestones, escrow and liability</h2>
<ul>
  <li>A milestone is sealed only when both the delivery project manager and the client approve it. Each party is responsible for reviewing the evidence before approving; an approval is final for that milestone.</li>
  <li>Deliverables linked to an escrow invoice are released only after payment clears and both approvals match. Ephemeral dossiers are shredded after their view limit or expiry, and cannot be recovered afterwards; download or record anything you need before viewing expires.</li>
  <li>Hastra records approvals and releases but is not a party to the commercial agreement between a delivery organization and its client, and is not responsible for the quality, fitness or timeliness of the software delivered between them.</li>
</ul>

<h2 id="sla">6. Availability</h2>
<p>We aim for at least <strong>99.5% monthly availability</strong> of the Hastra web application, excluding scheduled maintenance
  announced at least 48 hours ahead, emergency security maintenance, and causes outside our reasonable control (including
  failures of your network, identity provider or third-party services). Enterprise agreements may set a different commitment and
  remedies; where none is agreed, this target is not a guarantee.</p>

<h2 id="suspension">7. Suspension and termination</h2>
<ul>
  <li><strong>Automatic security blocks.</strong> Submitting a honeytoken identifier, or activity that matches automated
    extraction, immediately blocks the originating network for 72 hours and alerts us. Repeated failed sign-ins lock the account
    for 15 minutes and the network for 30 minutes.</li>
  <li><strong>Account suspension.</strong> We may suspend an account or workspace that triggers a honeytoken, breaches section 2,
    or poses a security risk to others, while we investigate. Where it is safe to do so we will tell the account holder and give
    them a chance to respond. Mistaken blocks are lifted as soon as they are confirmed.</li>
  <li>You may close your account at any time by contacting <?= legal_contact() ?>. We may end these terms on 30 days' notice, or
    immediately for serious breach. Sections 5, 8, 9 and 10 survive termination.</li>
</ul>

<h2 id="ip">8. Intellectual property</h2>
<p>You keep all rights to the content you and your organization put into Hastra, and grant us only the licence needed to host,
  process and display it to run the service. Hastra's software, design and trademarks remain ours. Feedback you give us may be used
  without obligation. You must not upload material that infringes anyone's intellectual property; we will remove it on a valid notice.</p>

<h2 id="liability">9. Warranties and limitation of liability</h2>
<p>Hastra is provided "as is" to the extent the law allows. Security features reduce risk but no software is free of defects or
  immune to attack, and we do not promise uninterrupted or error-free operation. To the extent the law allows, our total
  liability for any claim is limited to the fees you paid us in the 12 months before the claim, and we are not liable for indirect
  or consequential loss. Nothing in these terms limits liability that cannot be limited by law, or your statutory consumer rights.</p>

<h2 id="general">10. General</h2>
<p>We may update these terms; material changes are announced by email or in-app at least 30 days before they apply, and each
  account's acceptance is recorded against the version in force. These terms are governed by the laws of the place where
  <?= htmlspecialchars(LEGAL_ENTITY_NAME) ?> is based, unless your local consumer law says otherwise. Contact:
  <?= legal_contact() ?><?= LEGAL_ADDRESS !== '' ? ', ' . htmlspecialchars(LEGAL_ADDRESS) : '' ?>.</p>
<?php legal_page_end(); ?>
