<?php
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) { http_response_code(404); exit(); }
// core/escrow.php
// Milestone escrow: dual sign-off -> invoice settled -> deliverable release.
//
//   engineering_review  PM has signed (milestone_signoffs.status = pending_client)
//   payment_pending     client countersigned; an invoice now exists for the
//                       milestone's escrow_amount (created in the same
//                       transaction as the countersignature)
//   released            invoice paid (admin clearance or signed webhook) or
//                       waived (a zero-value milestone). Dossiers linked to the
//                       milestone activate, and their expiry clock starts now.
//   disputed            either party disputed the sign-off; nothing releases
//
// The client-facing mock "Pay" button (portals/client/delivery_billing.php)
// cannot settle an escrow invoice. Only astra_escrow_settle() can, and it is
// called from admin billing and api/payment_webhook.php.

require_once __DIR__ . '/audit_chain.php';

const ASTRA_ESCROW_STATES = ['engineering_review', 'payment_pending', 'released', 'disputed'];

function astra_escrow_ready($conn, bool $refresh = false): bool {
    static $ready = null;
    if ($ready === null || $refresh) {
        $r = mysqli_query($conn,
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND (
                (TABLE_NAME = 'milestone_signoffs' AND COLUMN_NAME IN ('escrow_status', 'invoice_id', 'escrow_amount'))
             OR (TABLE_NAME = 'invoices' AND COLUMN_NAME IN ('milestone_id', 'paid_reference'))
             OR (TABLE_NAME = 'ephemeral_dossiers' AND COLUMN_NAME IN ('milestone_id', 'ttl_minutes')))");
        $ready = $r && (int)mysqli_fetch_row($r)[0] === 7;
    }
    return $ready;
}

// Called by astra_signoff_client_sign() INSIDE its transaction, right after
// the countersignature is written. Throws on failure so the caller rolls the
// whole thing back.
function astra_escrow_open_invoice($conn, array $signoff): array {
    $amount = round((float)$signoff['escrow_amount'], 2);
    $code   = next_code($conn, 'INV');

    $cq = mysqli_prepare($conn, "SELECT company_id FROM users WHERE id = ?");
    mysqli_stmt_bind_param($cq, "i", $signoff['client_user_id']);
    mysqli_stmt_execute($cq);
    $company_id = mysqli_fetch_assoc(mysqli_stmt_get_result($cq))['company_id'] ?? null;

    $waived = $amount <= 0;
    $status = $waived ? 'waived' : 'generated';
    $notes  = 'Escrow for milestone "' . $signoff['milestone_name'] . '"';

    $ins = mysqli_prepare($conn,
        "INSERT INTO invoices (invoice_code, project_id, generated_by, total_amount, status, notes,
                               milestone_id, company_id, client_id, currency)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'INR')");
    mysqli_stmt_bind_param($ins, "siidssiii", $code, $signoff['project_id'], $signoff['pm_user_id'], $amount,
        $status, $notes, $signoff['id'], $company_id, $signoff['client_user_id']);
    if (!mysqli_stmt_execute($ins)) throw new RuntimeException('Could not create the milestone invoice: ' . mysqli_stmt_error($ins));
    $invoice_id = (int)mysqli_insert_id($conn);

    $escrow = $waived ? 'released' : 'payment_pending';
    $upd = mysqli_prepare($conn, "UPDATE milestone_signoffs SET escrow_status = ?, invoice_id = ? WHERE id = ?");
    mysqli_stmt_bind_param($upd, "sii", $escrow, $invoice_id, $signoff['id']);
    mysqli_stmt_execute($upd);

    if ($waived) astra_escrow_activate_dossiers($conn, (int)$signoff['id']);

    return ['invoice_id' => $invoice_id, 'invoice_code' => $code, 'amount' => $amount, 'escrow_status' => $escrow];
}

// Starts the expiry clock on every dormant dossier linked to the milestone.
function astra_escrow_activate_dossiers($conn, int $milestone_id): int {
    $stmt = mysqli_prepare($conn,
        "UPDATE ephemeral_dossiers SET expires_at = NOW() + INTERVAL ttl_minutes MINUTE
         WHERE milestone_id = ? AND expires_at IS NULL AND is_shredded = 0");
    mysqli_stmt_bind_param($stmt, "i", $milestone_id);
    mysqli_stmt_execute($stmt);
    return mysqli_stmt_affected_rows($stmt);
}

// Marks an invoice paid and, for an escrow invoice, releases its milestone.
// Idempotent: settling an already-paid invoice is a no-op that reports success.
// $source is 'admin' or 'webhook'. Returns the invoice row or null.
function astra_escrow_settle($conn, int $invoice_id, string $reference, string $source, ?int $actor_id, &$error = null): ?array {
    mysqli_begin_transaction($conn);
    try {
        $q = mysqli_prepare($conn, "SELECT * FROM invoices WHERE id = ? FOR UPDATE");
        mysqli_stmt_bind_param($q, "i", $invoice_id);
        mysqli_stmt_execute($q);
        $inv = mysqli_fetch_assoc(mysqli_stmt_get_result($q));
        if (!$inv) { mysqli_rollback($conn); $error = "Invoice not found."; return null; }
        if (in_array($inv['status'], ['paid', 'waived'], true)) { mysqli_commit($conn); return $inv; }

        $ms = null;
        if ($inv['milestone_id']) {
            $mq = mysqli_prepare($conn, "SELECT * FROM milestone_signoffs WHERE id = ? FOR UPDATE");
            mysqli_stmt_bind_param($mq, "i", $inv['milestone_id']);
            mysqli_stmt_execute($mq);
            $ms = mysqli_fetch_assoc(mysqli_stmt_get_result($mq));
            if ($ms && $ms['escrow_status'] === 'disputed') {
                mysqli_rollback($conn);
                $error = "This milestone is disputed. Resolve the dispute before settling its invoice.";
                return null;
            }
        }

        $ref = substr(trim($reference), 0, 128) ?: null;
        $u = mysqli_prepare($conn, "UPDATE invoices SET status = 'paid', paid_at = NOW(), paid_reference = ? WHERE id = ?");
        mysqli_stmt_bind_param($u, "si", $ref, $invoice_id);
        mysqli_stmt_execute($u);

        if ($ms) {
            $r = mysqli_prepare($conn, "UPDATE milestone_signoffs SET escrow_status = 'released' WHERE id = ? AND escrow_status = 'payment_pending'");
            mysqli_stmt_bind_param($r, "i", $ms['id']);
            mysqli_stmt_execute($r);
            astra_escrow_activate_dossiers($conn, (int)$ms['id']);
        }
        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        error_log('[hastra-escrow] settle failed: ' . $e->getMessage());
        $error = "Couldn't record the payment.";
        return null;
    }

    // Logged after commit: the ledger must never contain a rolled-back event.
    astra_log_chained($ms ? 'ESCROW_RELEASED' : 'INVOICE_SETTLED',
        "Invoice {$inv['invoice_code']} settled via $source" . ($ref ? " (ref $ref)" : '') . ($ms ? ", milestone \"{$ms['milestone_name']}\" released" : ''),
        $actor_id, $conn);
    $inv['status'] = 'paid';
    return $inv;
}

function astra_escrow_for_milestone($conn, int $milestone_id): array {
    $mq = mysqli_prepare($conn, "SELECT * FROM milestone_signoffs WHERE id = ?");
    mysqli_stmt_bind_param($mq, "i", $milestone_id);
    mysqli_stmt_execute($mq);
    $ms = mysqli_fetch_assoc(mysqli_stmt_get_result($mq)) ?: null;
    $inv = null;
    if ($ms && $ms['invoice_id']) {
        $iq = mysqli_prepare($conn, "SELECT * FROM invoices WHERE id = ?");
        mysqli_stmt_bind_param($iq, "i", $ms['invoice_id']);
        mysqli_stmt_execute($iq);
        $inv = mysqli_fetch_assoc(mysqli_stmt_get_result($iq)) ?: null;
    }
    return [$ms, $inv];
}

// Is a dossier allowed to be opened? Checks the live state, not just the
// status column: both signatures must still verify and the invoice must
// really be paid or waived. Returns ['ok', 'reason', 'signoff', 'invoice',
// 'signatures_valid'].
function astra_escrow_gate($conn, array $dossier): array {
    $res = ['ok' => false, 'reason' => null, 'signoff' => null, 'invoice' => null, 'signatures_valid' => false];
    if (empty($dossier['milestone_id'])) {
        $res['reason'] = "This dossier isn't linked to a milestone, so it can't pass the escrow check.";
        return $res;
    }
    [$ms, $inv] = astra_escrow_for_milestone($conn, (int)$dossier['milestone_id']);
    $res['signoff'] = $ms; $res['invoice'] = $inv;
    if (!$ms) { $res['reason'] = "The milestone for this dossier no longer exists."; return $res; }

    $v = astra_signoff_verify($conn, $ms);
    $res['signatures_valid'] = $v['pm_valid'] === true && $v['client_valid'] === true;

    if ($ms['escrow_status'] === 'disputed')      $res['reason'] = "This milestone is disputed. The handover stays locked until it's resolved.";
    elseif (!$res['signatures_valid'])            $res['reason'] = "The milestone's dual-key signatures are missing or no longer verify.";
    elseif (!$inv)                                $res['reason'] = "No invoice exists for this milestone yet.";
    elseif (!in_array($inv['status'], ['paid', 'waived'], true)) $res['reason'] = "Invoice {$inv['invoice_code']} hasn't been settled yet.";
    elseif ($ms['escrow_status'] !== 'released')  $res['reason'] = "This milestone hasn't been released yet.";
    else $res['ok'] = true;
    return $res;
}

// Three-stage progress for the client UI.
function astra_escrow_stages(?array $ms, ?array $inv): array {
    $signed  = $ms && $ms['status'] === 'completed';
    $settled = $inv && in_array($inv['status'], ['paid', 'waived'], true);
    $open    = $ms && $ms['escrow_status'] === 'released';
    $disputed = $ms && ($ms['escrow_status'] === 'disputed' || $ms['status'] === 'disputed');
    return [
        ['label' => 'Engineering Sign-Off', 'state' => $signed ? 'Complete' : ($disputed ? 'Disputed' : 'Awaiting signatures'), 'done' => $signed],
        ['label' => 'Invoice Settlement',   'state' => $settled ? ($inv['status'] === 'waived' ? 'Waived' : 'Paid') : ($inv ? 'Pending' : 'Not issued'), 'done' => $settled],
        ['label' => 'Secure Handover',      'state' => $open ? 'Unlocked' : 'Locked', 'done' => $open],
    ];
}
