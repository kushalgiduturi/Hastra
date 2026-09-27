<?php
// api/security/_bootstrap.php
// Shared entry for the Scan Center API: session, JSON errors instead of
// login redirects, and the caller's company context.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) { http_response_code(404); exit(); }

include __DIR__ . '/../../core/db.php';
secure_session_start();

function scan_api_fail(int $code, string $message): void {
    http_response_code($code);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(['ok' => false, 'error' => $message]);
    exit();
}
function scan_api_ok(array $data): void {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}
function scan_api_csrf(): void {
    $t = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!isset($_SESSION['csrf_token']) || !is_string($t) || !hash_equals($_SESSION['csrf_token'], $t)) scan_api_fail(403, 'Invalid request token. Refresh the page and try again.');
}

if (!isset($_SESSION['user_id'])) scan_api_fail(401, 'Sign in to use the Scan Center.');
if (!astra_security_ready($conn)) scan_api_fail(503, "The Scan Center isn't set up yet. Ask the sysadmin to run the database migration.");
$SCAN_USER = (int)$_SESSION['user_id'];
$SCAN_CTX  = astra_security_context($conn, $SCAN_USER);
if (!$SCAN_CTX) scan_api_fail(403, 'Your account is not linked to a company, so there is nowhere to store scan results.');
