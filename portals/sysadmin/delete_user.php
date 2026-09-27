<?php
// portals/sysadmin/delete_user.php
// Account deletion: the sysadmin requests an OTP, and the account is deleted
// as soon as that OTP is verified. Deleted accounts are archived in deleted_users.
include __DIR__ . '/../../core/db.php';
secure_session_start();
verify_session($conn, "sysadmin");

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require __DIR__ . '/../../PHPMailer/PHPMailer.php';
require __DIR__ . '/../../PHPMailer/SMTP.php';
require __DIR__ . '/../../PHPMailer/Exception.php';

header('Content-Type: application/json');

const DELETE_OTP_TTL      = 600;   // seconds
const DELETE_OTP_ATTEMPTS = 5;

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    exit();
}

verify_csrf_token();

$action   = trim($_POST["action"] ?? "");
$actor_id = (int)$_SESSION["user_id"];

function respond($success, $message, array $extra = []) {
    echo json_encode(['success' => $success, 'message' => $message] + $extra);
    exit();
}

function send_mail_to($to, $subject, $body) {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host        = MAIL_HOST;
    $mail->SMTPAuth    = MAIL_AUTH;
    $mail->Port        = MAIL_PORT;
    $mail->SMTPSecure  = MAIL_SECURE;
    $mail->SMTPAutoTLS = false;
    $mail->setFrom(MAIL_FROM, MAIL_NAME);
    $mail->addAddress($to);
    $mail->Subject = $subject;
    $mail->Body    = $body;
    $mail->send();
}

// Returns the target user, or ends the request with an error.
function load_deletable_user($conn, $target_id, $actor_id) {
    if (!$target_id) respond(false, "Invalid user.");
    if ($target_id === $actor_id) respond(false, "You cannot delete your own account.");

    $stmt = mysqli_prepare($conn, "SELECT id, name, email, role FROM users WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $target_id);
    mysqli_stmt_execute($stmt);
    $target = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if (!$target) respond(false, "User not found.");
    astra_decrypt_user_row($target);

    if (strcasecmp($target["email"], PRIMARY_SYSADMIN_EMAIL) === 0) {
        respond(false, "This account is protected and cannot be deleted.");
    }
    return $target;
}

// ── STEP 1: send an OTP to the sysadmin ──────────────────────────────────────
if ($action === "request_delete") {
    $target = load_deletable_user($conn, (int)($_POST["target_id"] ?? 0), $actor_id);

    $sadmin = mysqli_prepare($conn, "SELECT email FROM users WHERE id = ?");
    mysqli_stmt_bind_param($sadmin, "i", $actor_id);
    mysqli_stmt_execute($sadmin);
    $sadmin_row   = mysqli_fetch_assoc(mysqli_stmt_get_result($sadmin));
    astra_decrypt_user_row($sadmin_row);
    $sadmin_email = $sadmin_row["email"] ?? null;
    if (!$sadmin_email) respond(false, "Could not find your email address.");

    $otp = (string)random_int(100000, 999999);
    $_SESSION["delete_otp"] = [
        "target_id" => (int)$target["id"],
        "hash"      => password_hash($otp, PASSWORD_DEFAULT),
        "expires"   => time() + DELETE_OTP_TTL,
        "attempts"  => 0,
    ];

    try {
        send_mail_to($sadmin_email, "Confirm Account Deletion: OTP",
            "You requested to delete the account of: {$target['name']} ({$target['email']})\n\n" .
            "Your confirmation OTP is: $otp\n\n" .
            "This OTP expires in 10 minutes. The account is deleted as soon as the OTP is entered.\n\n" .
            "If you did not request this, you can ignore this email.");
    } catch (Exception $e) {
        unset($_SESSION["delete_otp"]);
        respond(false, "Could not send OTP. Please try again.");
    }

    respond(true, "OTP sent to your email.", [
        "target_id"   => (int)$target["id"],
        "target_name" => $target["name"],
    ]);
}

// ── STEP 2: verify the OTP and delete immediately ────────────────────────────
if ($action === "verify_otp") {
    $target_id = (int)($_POST["target_id"] ?? 0);
    $pending   = $_SESSION["delete_otp"] ?? null;
    $entered   = trim($_POST["otp"] ?? "");

    if (!$pending || $pending["target_id"] !== $target_id || $pending["expires"] < time()) {
        unset($_SESSION["delete_otp"]);
        respond(false, "This OTP has expired. Close this window and click Delete again.");
    }
    if (++$_SESSION["delete_otp"]["attempts"] > DELETE_OTP_ATTEMPTS) {
        unset($_SESSION["delete_otp"]);
        respond(false, "Too many wrong attempts. Close this window and click Delete again.");
    }
    if (!preg_match('/^\d{6}$/', $entered) || !password_verify($entered, $pending["hash"])) {
        respond(false, "Incorrect OTP. Check your email and try again.");
    }
    unset($_SESSION["delete_otp"]);

    $target = load_deletable_user($conn, $target_id, $actor_id);

    $actor = mysqli_prepare($conn, "SELECT name FROM users WHERE id = ?");
    mysqli_stmt_bind_param($actor, "i", $actor_id);
    mysqli_stmt_execute($actor);
    $actor_row  = mysqli_fetch_assoc(mysqli_stmt_get_result($actor));
    astra_decrypt_user_row($actor_row);
    $actor_name = $actor_row["name"] ?? "Unknown";

    mysqli_begin_transaction($conn);
    try {
        $archive = mysqli_prepare($conn,
            "INSERT INTO deleted_users (id, name, email, role, deleted_at, deleted_by, deleted_by_name)
             VALUES (?, ?, ?, ?, NOW(), ?, ?)");
        mysqli_stmt_bind_param($archive, "isssis",
            $target["id"], $target["name"], $target["email"], $target["role"], $actor_id, $actor_name);
        mysqli_stmt_execute($archive);

        $del = mysqli_prepare($conn, "DELETE FROM users WHERE id = ?");
        mysqli_stmt_bind_param($del, "i", $target_id);
        mysqli_stmt_execute($del);
        if (mysqli_stmt_affected_rows($del) !== 1) throw new RuntimeException("user not deleted");

        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        error_log("delete_user($target_id) failed: " . $e->getMessage());
        respond(false, "Could not delete this account. It may still be linked to projects or tasks.");
    }

    try { log_activity($conn, $actor_id, "user_deleted", $actor_name); } catch (Throwable $e) {}

    try {
        send_mail_to($target["email"], "Your Hastra account has been deleted",
            "Dear {$target['name']},\n\nYour account has been deleted by a system administrator.\n\n" .
            "If you believe this is a mistake, please contact support.");
    } catch (Exception $e) {}

    respond(true, "{$target['name']}'s account has been deleted.");
}

respond(false, "Invalid action.");
