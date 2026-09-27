<?php
require __DIR__ . '/_layout.php';
legal_page_start('refunds', 'Refund Policy', 'Billing cycles, refunds, disputes and non-refundable items on Hastra.');
?>
<p>This policy covers two kinds of payment: fees for using Hastra itself, and <strong>milestone escrow settlements</strong> that a
  client pays a delivery organization through Hastra. It does not reduce any right you have under consumer law.</p>

<h2 id="cycles">1. Billing cycles</h2>
<table>
  <thead><tr><th scope="col">Account type</th><th scope="col">How it is billed</th></tr></thead>
  <tbody>
    <tr><td>Enterprise organization (full workspace)</td><td>Per workspace, monthly or annually in advance, as set out in the order form or plan page. Annual plans renew unless cancelled at least 30 days before renewal.</td></tr>
    <tr><td>Solo developer / studio</td><td>Monthly in advance. Cancel any time; the plan stays active until the end of the paid month.</td></tr>
    <tr><td>Client organization or individual client</td><td>No platform fee for the client account. Clients pay the delivery organization's milestone invoices.</td></tr>
    <tr><td>Milestone escrow settlement</td><td>One invoice per milestone, raised by the delivery organization. Funds settle through the payment provider; the linked deliverable is released only after the payment clears <em>and</em> both the delivery project manager and the client have signed the milestone.</td></tr>
  </tbody>
</table>

<h2 id="platform">2. Refunds of Hastra fees</h2>
<ul>
  <li><strong>First subscription:</strong> if Hastra is not right for you, ask within 14 days of your first payment for a full refund of that payment.</li>
  <li><strong>Annual plans:</strong> after the first 14 days, cancelling stops renewal; unused months are not refunded unless the law or your enterprise agreement requires it.</li>
  <li><strong>Service failures:</strong> if we miss the availability target in the <a href="terms#sla">Terms</a> and your agreement provides service credits, credits are applied to the next invoice.</li>
  <li><strong>Billing errors</strong> (duplicate or incorrect charges) are always refunded in full.</li>
</ul>

<h2 id="escrow">3. Milestone escrow settlements</h2>
<p>The delivery organization, not Hastra, is the seller of the work paid for through a milestone invoice. Refund requests for
  work are decided between the client and the delivery organization under their contract. Hastra supports that process:</p>
<ul>
  <li><strong>Before the client signs the milestone:</strong> the invoice may be cancelled, and any payment received is returned to the client through the payment provider.</li>
  <li><strong>After payment but before dual sign-off:</strong> the deliverable stays locked. If the parties agree to cancel, or a dispute is decided in the client's favour, the payment is refunded and the milestone reopened.</li>
  <li><strong>After dual sign-off and release:</strong> the milestone is final on Hastra. Any further remedy is a matter between the parties under their contract.</li>
</ul>

<h2 id="non-refundable">4. Non-refundable items</h2>
<ul>
  <li><strong>Ephemeral dossiers that have been decrypted.</strong> Once a dossier has been opened, or shredded after its view
    limit or expiry, its contents have been delivered and then permanently overwritten, and cannot be recovered or "returned".
    Payment for the milestone that released it cannot be refunded on the ground that the dossier is no longer available.</li>
  <li>Milestones that both parties have signed and that have been released.</li>
  <li>Fees for periods already used, except as stated in section 2 or required by law.</li>
</ul>

<h2 id="disputes">5. Disputes and timelines</h2>
<ol>
  <li>Raise a dispute within <strong>30 days</strong> of the invoice date (or, for an escrow milestone, before signing it) by emailing <?= legal_contact() ?> with the invoice number and what went wrong.</li>
  <li>We acknowledge within <strong>2 business days</strong>. For escrow disputes we notify the other party and freeze release of the linked deliverable while the dispute is open.</li>
  <li>We aim to resolve, or to give each party the records they need (approvals, test evidence and the activity log), within <strong>10 business days</strong>.</li>
  <li>Approved refunds are issued to the original payment method within <strong>10 business days</strong>; your bank may take longer to show them.</li>
</ol>
<p>Please contact us before opening a chargeback with your bank; a chargeback on an escrow invoice freezes the milestone until it is resolved.</p>
<?php legal_page_end(); ?>
