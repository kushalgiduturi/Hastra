<?php
// api/payment_webhook.php
// Payment-provider callback that settles a milestone escrow invoice
// (core/escrow.php). Nothing else can release an escrow invoice except an
// admin's manual clearance in portals/admin/billing.php.
//
//   POST /api/payment_webhook.php
//   Content-Type: application/json
//   X-Hastra-Timestamp: <unix seconds>
//   X-Hastra-Signature: sha256=<hex HMAC-SHA256 of "<timestamp>.<raw body>">
//   {"invoice_number":"26INV0007","amount":"45000.00","reference":"pay_8Kx...","status":"paid"}
//
// The HMAC key is config/astra_payment_webhook.key (created by the v4
// migration; base64 of 32 bytes). The provider signs with the raw 32 bytes.
// The key never travels on the wire, requests older than 5 minutes are
// rejected (replay protection), the amount must match the invoice exactly,
// and settling an already-paid invoice is a harmless no-op.
include __DIR__ . '/../core/db.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

const PAYMENT_WEBHOOK_KEY_FILE = __DIR__ . '/../config/astra_payment_webhook.key';
const PAYMENT_WEBHOOK_SKEW     = 300; // seconds

function payment_webhook_fail(int $code, string $message): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') payment_webhook_fail(405, 'Use POST.');

$key = astra_read_key_file(PAYMENT_WEBHOOK_KEY_FILE, false);
if (!$key) payment_webhook_fail(503, 'Payment webhook is not configured.');

$raw = file_get_contents('php://input', false, null, 0, 16384);
// The new header names take priority; the pre-rebrand ones are still honoured
// so an already-configured provider keeps working until it updates.
$ts  = $_SERVER['HTTP_X_HASTRA_TIMESTAMP'] ?? $_SERVER['HTTP_X_ASTRA_TIMESTAMP'] ?? '';
$sig = $_SERVER['HTTP_X_HASTRA_SIGNATURE'] ?? $_SERVER['HTTP_X_ASTRA_SIGNATURE'] ?? '';

if (!ctype_digit($ts) || abs(time() - (int)$ts) > PAYMENT_WEBHOOK_SKEW) {
    payment_webhook_fail(401, 'Missing or stale X-Hastra-Timestamp.');
}
$expected = 'sha256=' . hash_hmac('sha256', $ts . '.' . $raw, $key);
if (!hash_equals($expected, $sig)) {
    astra_log_chained('PAYMENT_WEBHOOK_REJECTED', 'Bad signature from ' . ($_SERVER['REMOTE_ADDR'] ?? '?'), null, $conn, 'warning', 'WEBHOOK_SIGNATURE');
    payment_webhook_fail(401, 'Invalid signature.');
}

$body = json_decode($raw, true);
if (!is_array($body) || ($body['status'] ?? '') !== 'paid' || empty($body['invoice_number']) || !isset($body['amount'])) {
    payment_webhook_fail(422, 'Expected invoice_number, amount and status "paid".');
}

$q = mysqli_prepare($conn, "SELECT id, total_amount, status, milestone_id FROM invoices WHERE invoice_code = ?");
$code = (string)$body['invoice_number'];
mysqli_stmt_bind_param($q, "s", $code);
mysqli_stmt_execute($q);
$inv = mysqli_fetch_assoc(mysqli_stmt_get_result($q));
if (!$inv) payment_webhook_fail(404, 'Unknown invoice.');
if (empty($inv['milestone_id'])) payment_webhook_fail(422, 'That invoice is not a milestone escrow invoice.');

// Compare in paise to avoid float rounding.
if ((int)round((float)$body['amount'] * 100) !== (int)round((float)$inv['total_amount'] * 100)) {
    astra_log_chained('PAYMENT_WEBHOOK_REJECTED', "Amount mismatch for $code: got {$body['amount']}, expected {$inv['total_amount']}", null, $conn, 'warning', 'WEBHOOK_AMOUNT');
    payment_webhook_fail(422, 'Amount does not match the invoice.');
}

$settled = astra_escrow_settle($conn, (int)$inv['id'], (string)($body['reference'] ?? ''), 'webhook', null, $error);
if (!$settled) payment_webhook_fail(409, $error ?: 'Could not settle the invoice.');

echo json_encode(['success' => true, 'invoice_number' => $code, 'status' => $settled['status']]);
