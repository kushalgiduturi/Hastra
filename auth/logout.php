<?php
include __DIR__ . '/../core/db.php';
secure_session_start();

if (isset($_SESSION["user_id"])) {
    log_activity($conn, $_SESSION["user_id"], "logout", $_SESSION["user_name"] ?? null);
    astra_session_revoke($conn);
}

session_unset();
session_destroy();
header("Location: signin");
exit();
?>