<?php
include __DIR__ . '/../core/db.php';
secure_session_start();

if (isset($_SESSION["user_id"])) {
    log_activity($conn, $_SESSION["user_id"], "logout", $_SESSION["user_name"] ?? null);
}

session_unset();
session_destroy();
header("Location: login");
exit();
?>