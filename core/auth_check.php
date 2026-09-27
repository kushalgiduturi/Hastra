<?php
// core/auth_check.php
// Mandatory profile-completion barrier. Called once per authenticated page
// (see render_sidebar() in core/dock.php, invoked from each portal's
// _nav.php) — renders an unclosable modal over the page for any signed-in
// user who hasn't picked a gender yet, blocking interaction with the
// dashboard underneath until they do. api/complete_profile.php is the
// endpoint the modal's JS posts to.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) { http_response_code(404); exit(); }

function astra_profile_barrier_needed($conn, $user_id) {
    $stmt = mysqli_prepare($conn, "SELECT gender, profile_updated FROM users WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if (!$row) return false;

    if ($row['gender'] !== null) {
        // Gender is already on file — the barrier is done, full stop, even if
        // profile_updated somehow wasn't flipped (e.g. a manual DB edit).
        // Self-heal that flag here so this check stays a single cheap lookup
        // instead of a permanent OR against a stale column.
        if ((int)$row['profile_updated'] !== 1) {
            $fix = mysqli_prepare($conn, "UPDATE users SET profile_updated = 1 WHERE id = ?");
            mysqli_stmt_bind_param($fix, "i", $user_id);
            mysqli_stmt_execute($fix);
        }
        return false;
    }

    return true;
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
          <button type="button" class="pb-opt" data-gender="male">
            <span class="pb-opt-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="10" cy="14" r="6"/><path d="M14.5 9.5L20 4M20 4h-5M20 4v5" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
            <span class="pb-opt-text"><span class="pb-opt-title">Male</span><span class="pb-opt-desc">He / him</span></span>
            <span class="pb-opt-check"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 13l4 4L19 7" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
          </button>
          <button type="button" class="pb-opt" data-gender="female">
            <span class="pb-opt-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="9" r="6"/><path d="M12 15v6M9 19h6" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
            <span class="pb-opt-text"><span class="pb-opt-title">Female</span><span class="pb-opt-desc">She / her</span></span>
            <span class="pb-opt-check"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 13l4 4L19 7" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
          </button>
          <button type="button" class="pb-opt" data-gender="prefer_not_to_say">
            <span class="pb-opt-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3l7 3v5c0 4.5-3 8.5-7 10-4-1.5-7-5.5-7-10V6l7-3z" stroke-linejoin="round"/></svg></span>
            <span class="pb-opt-text"><span class="pb-opt-title">Prefer not to say</span><span class="pb-opt-desc">Kept private</span></span>
            <span class="pb-opt-check"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 13l4 4L19 7" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
          </button>
        </div>
        <div id="pbError" class="pb-error" role="alert"></div>
        <button type="button" id="pbSubmit" class="pb-submit" disabled>Continue</button>
      </div>
    </div>
    <link rel="stylesheet" href="<?= get_base_url() ?>assets/css/profile-barrier.css?v=<?= ASSET_VERSION ?>">
    <script src="<?= get_base_url() ?>assets/js/profile-barrier.js?v=<?= ASSET_VERSION ?>" defer></script>
    <?php
}
