<?php
// core/auth_check.php
// Mandatory profile-completion barrier. Called once per authenticated page
// (see render_sidebar() in core/dock.php, invoked from each portal's
// _nav.php) — renders an unclosable modal over the page for any signed-in
// user who hasn't picked a gender yet, blocking interaction with the
// dashboard underneath until they do. api/complete_profile.php is the
// endpoint the modal's JS posts to.
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'auth_check.php') { http_response_code(404); exit(); }

function astra_profile_barrier_needed($conn, $user_id) {
    $stmt = mysqli_prepare($conn, "SELECT gender, profile_updated FROM users WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if (!$row) return false;
    return (int)$row['profile_updated'] === 0 || $row['gender'] === null;
}

// Renders the barrier overlay if (and only if) the signed-in user still
// needs to complete it. No-op for logged-out visitors or already-complete
// profiles, so it's safe to call unconditionally on every authenticated page.
function render_profile_barrier($conn) {
    if (!isset($_SESSION['user_id'])) return;
    if (!astra_profile_barrier_needed($conn, (int)$_SESSION['user_id'])) return;
    $csrf = generate_csrf_token();
    ?>
    <div id="profileBarrierOverlay" class="profile-barrier-overlay" data-csrf="<?= htmlspecialchars($csrf) ?>" data-endpoint="<?= htmlspecialchars(get_base_url()) ?>api/complete_profile.php">
      <div class="profile-barrier-modal" role="dialog" aria-modal="true" aria-labelledby="pbTitle">
        <h2 id="pbTitle">One more thing before you continue</h2>
        <p>Select your gender to finish setting up your Astra account. You'll only need to do this once.</p>
        <div class="pb-options">
          <button type="button" class="pb-opt" data-gender="male">Male</button>
          <button type="button" class="pb-opt" data-gender="female">Female</button>
          <button type="button" class="pb-opt" data-gender="prefer_not_to_say">Prefer not to say</button>
        </div>
        <div id="pbError" class="pb-error" role="alert"></div>
      </div>
    </div>
    <link rel="stylesheet" href="<?= get_base_url() ?>assets/css/profile-barrier.css?v=<?= ASSET_VERSION ?>">
    <script src="<?= get_base_url() ?>assets/js/profile-barrier.js?v=<?= ASSET_VERSION ?>" defer></script>
    <?php
}
