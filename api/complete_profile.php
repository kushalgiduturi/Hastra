<?php
// api/complete_profile.php
// Backend for the mandatory profile-completion barrier (core/auth_check.php).
//   POST gender=<male|female|prefer_not_to_say>&focus=<role label>&csrf_token=<session token>
//     -> { ok, message? }
//   focus is required from people outside an organization and refused from
//   organization members (core/auth_check.php: astra_profile_role_context).
// Session-scoped: a user only ever updates their own row.
include __DIR__ . '/../core/db.php';
secure_session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Not signed in.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed.']);
    exit();
}

if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Invalid request. Please refresh the page and try again.']);
    exit();
}

$gender = $_POST['gender'] ?? '';
if (!in_array($gender, ['male', 'female', 'prefer_not_to_say'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Choose one of the options.']);
    exit();
}

$user_id    = (int)$_SESSION['user_id'];

// Role label: organization members never choose (their administrator manages
// it, so a posted value is refused, not ignored); everyone else must.
$role  = astra_profile_role_context($conn, $user_id);
$focus = trim((string)($_POST['focus'] ?? ''));
if ($role['locked']) {
    if ($focus !== '') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => "Your role is managed by your organization's administrator."]);
        exit();
    }
} elseif (!isset(astra_focus_options()[$focus]) || !astra_ensure_profile_focus_column($conn)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Choose your role.']);
    exit();
}

$gender_enc = astra_db_encrypt($gender);
if ($role['locked']) {
    $upd = mysqli_prepare($conn, "UPDATE users SET gender = ?, profile_updated = 1 WHERE id = ?");
    mysqli_stmt_bind_param($upd, "si", $gender_enc, $user_id);
} else {
    $upd = mysqli_prepare($conn, "UPDATE users SET gender = ?, profile_focus = ?, profile_updated = 1 WHERE id = ?");
    mysqli_stmt_bind_param($upd, "ssi", $gender_enc, $focus, $user_id);
}

if (mysqli_stmt_execute($upd)) {
    // independent people go straight to the lab that fits the role they chose
    $landing = $role['locked'] ? null : astra_focus_landing($focus);
    echo json_encode(['ok' => true] + ($landing ? ['redirect' => $landing] : []));
} else {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Failed to save. Please try again.']);
}
