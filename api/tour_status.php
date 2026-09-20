<?php
// api/tour_status.php  (P18)
// Tiny JSON endpoint backing the onboarding tour (assets/js/tour.js).
//
//   GET  ?page=<page_key>                          -> { ok, seen, status }
//   POST page=<page_key>&status=<completed|skipped>
//        &csrf_token=<session csrf token>           -> { ok, status }
//
// Session-scoped: a user only ever reads/writes their own row. No output
// besides JSON, so it's safe to call from fetch() on every portal page.
include __DIR__ . '/../core/db.php';
secure_session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Not signed in.']);
    exit();
}
$user_id = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $page = trim($_GET['page'] ?? '');
    if (!tour_page_key_valid($page)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'Invalid page key.']);
        exit();
    }
    $status = get_tour_status($conn, $user_id, $page);
    echo json_encode(['ok' => true, 'seen' => $status !== null, 'status' => $status]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Invalid request.']);
        exit();
    }
    $page   = trim($_POST['page'] ?? '');
    $status = trim($_POST['status'] ?? '');
    if (!tour_page_key_valid($page) || !in_array($status, ['completed', 'skipped'], true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'Invalid request.']);
        exit();
    }
    $ok = set_tour_status($conn, $user_id, $page, $status);
    echo json_encode(['ok' => $ok, 'status' => $status]);
    exit();
}

http_response_code(405);
echo json_encode(['ok' => false, 'message' => 'Method not allowed.']);
