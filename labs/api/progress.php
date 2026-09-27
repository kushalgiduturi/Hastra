<?php
// Hastra Labs — study progress sync for community profiles.
//
//   GET                      → { signedIn, name, progress, updatedAt }
//   POST {progress:{...}}    → { ok, updatedAt }   (X-CSRF-Token header)
//
// The browser is the source of truth (localStorage); this is a backup that
// follows the student between devices. Stored through the column-encryption
// envelope, like every other free-text field in Hastra.
require __DIR__ . '/../_boot.php';
labs_session_start();

const LABS_PROGRESS_MAX_BYTES = 512 * 1024;

if (!labs_schema_ready($conn)) labs_json(['signedIn' => false, 'error' => 'not_configured']);
$profile = labs_current_profile($conn);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!$profile) labs_json(['signedIn' => false]);
    $stmt = mysqli_prepare($conn, "SELECT progress, updated_at FROM labs_profiles WHERE id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $profile['id']);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    $json = $row && $row['progress'] !== null ? astra_db_decrypt($row['progress']) : null;
    labs_json([
        'signedIn'  => true,
        'name'      => $profile['name'],
        'progress'  => $json ? json_decode($json, true) : null,
        'updatedAt' => $row['updated_at'] ?? null,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') labs_json(['error' => 'method'], 405);
if (!labs_csrf_ok()) labs_json(['error' => 'csrf'], 403);
if (!$profile) labs_json(['error' => 'signed_out'], 401);
if (!labs_rate_hit($conn, 'progress:' . $profile['id'], 120, 600)) labs_json(['error' => 'rate_limited'], 429);

$raw = file_get_contents('php://input', false, null, 0, LABS_PROGRESS_MAX_BYTES + 1);
if ($raw === false || strlen($raw) > LABS_PROGRESS_MAX_BYTES) labs_json(['error' => 'too_large'], 413);
$body = json_decode($raw, true);
if (!is_array($body) || !isset($body['progress']) || !is_array($body['progress'])) labs_json(['error' => 'bad_request'], 400);

$enc = astra_db_encrypt(json_encode($body['progress'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
$stmt = mysqli_prepare($conn, "UPDATE labs_profiles SET progress = ?, updated_at = NOW() WHERE id = ?");
mysqli_stmt_bind_param($stmt, 'si', $enc, $profile['id']);
mysqli_stmt_execute($stmt);
labs_json(['ok' => true, 'updatedAt' => date('Y-m-d H:i:s')]);
