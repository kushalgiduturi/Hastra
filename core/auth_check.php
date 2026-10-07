<?php
// core/auth_check.php
// Mandatory profile-completion barrier. Called once per authenticated page
// (see render_sidebar() in core/dock.php, invoked from each portal's
// _nav.php) — renders an unclosable modal over the page for any signed-in
// user who hasn't picked a gender yet, blocking interaction with the
// dashboard underneath until they do. api/complete_profile.php is the
// endpoint the modal's JS posts to.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) { http_response_code(404); exit(); }

// Self-described roles for people who are not part of an organization. This is
// a profile label only: it never grants access (authorization comes from
// users.role, which only an administrator or the sign-up flow sets).
const ASTRA_FOCUS_OPTIONS = [
    'security_analyst'   => 'Security Analyst',
    'researcher_student' => 'Researcher / Student',
    'independent_client' => 'Independent Client',
    'auditor'            => 'Auditor',
];

// Is this person part of an organization (so their role is managed by its
// administrator), and what is their role called? Organizations are the
// client_org and full_org accounts, plus anyone who is a team member or the
// system administrator. Individuals and solo studios choose for themselves.
function astra_profile_role_context($conn, int $user_id): array {
    $has_type = company_schema_ready($conn) && db_column_exists($conn, 'companies', 'account_type');
    $sql = $has_type
        ? "SELECT u.role, u.client_role, c.account_type FROM users u LEFT JOIN companies c ON c.id = u.company_id WHERE u.id = ?"
        : "SELECT role, client_role, NULL AS account_type FROM users WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: ['role' => 'client', 'client_role' => null, 'account_type' => null];
    $locked = in_array($row['account_type'], ['client_org', 'full_org'], true)
           || in_array($row['role'], ['employee', 'pending_employee', 'sysadmin'], true);
    $labels = ['admin' => 'Workspace administrator', 'employee' => 'Team member', 'pending_employee' => 'Team member (pending)',
               'sysadmin' => 'System administrator'];
    $label = $row['role'] === 'client'
        ? (defined('CLIENT_ROLES') && isset(CLIENT_ROLES[$row['client_role']]) ? CLIENT_ROLES[$row['client_role']] : 'Client')
        : ($labels[$row['role']] ?? 'Member');
    return ['locked' => $locked, 'label' => $label];
}

// users.profile_focus holds that label. Created on first use so a deploy needs no manual migration.
function astra_ensure_profile_focus_column($conn): bool {
    if (db_column_exists($conn, 'users', 'profile_focus')) return true;
    try { mysqli_query($conn, "ALTER TABLE users ADD COLUMN profile_focus VARCHAR(40) NULL"); } catch (Throwable $e) { /* another request won the race */ }
    return db_column_exists($conn, 'users', 'profile_focus');
}

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
    $role = astra_profile_role_context($conn, (int)$_SESSION['user_id']);
    if (!$role['locked']) astra_ensure_profile_focus_column($conn);
    ?>
    <div id="profileBarrierOverlay" class="profile-barrier-overlay" data-csrf="<?= htmlspecialchars($csrf) ?>" data-endpoint="<?= htmlspecialchars(get_base_url()) ?>api/complete_profile.php" data-needs-focus="<?= $role['locked'] ? '0' : '1' ?>">
      <div class="profile-barrier-modal" role="dialog" aria-modal="true" aria-labelledby="pbTitle">
        <p class="pb-eyebrow">First-time setup</p>
        <h2 id="pbTitle">One more thing before you continue</h2>
        <p class="pb-lead">Finish setting up your Hastra account. You'll only need to do this once.</p>

        <div class="pb-field">
          <span class="pb-label" id="pbGenderLabel">Identity</span>
          <div class="pb-choices" role="radiogroup" aria-labelledby="pbGenderLabel">
            <button type="button" class="pb-opt" role="radio" aria-checked="false" data-gender="male">Male</button>
            <button type="button" class="pb-opt" role="radio" aria-checked="false" data-gender="female">Female</button>
            <button type="button" class="pb-opt" role="radio" aria-checked="false" data-gender="prefer_not_to_say">Prefer not to say</button>
          </div>
        </div>

        <div class="pb-field">
          <span class="pb-label" id="pbRoleLabel">Role</span>
          <?php if ($role['locked']): ?>
          <p class="pb-locked">
            <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"/></svg>
            <strong><?= htmlspecialchars($role['label']) ?></strong>
          </p>
          <p class="pb-note">Role locked by your organization's policy. Contact your Organization System Administrator to change your assigned role.</p>
          <?php else: ?>
          <div class="pb-choices" role="radiogroup" aria-labelledby="pbRoleLabel">
            <?php foreach (ASTRA_FOCUS_OPTIONS as $value => $text): ?>
            <button type="button" class="pb-opt pb-role" role="radio" aria-checked="false" data-focus="<?= htmlspecialchars($value) ?>"><?= htmlspecialchars($text) ?></button>
            <?php endforeach; ?>
          </div>
          <p class="pb-note">Describes how you will use Hastra. It does not change what you can access.</p>
          <?php endif; ?>
        </div>

        <div id="pbError" class="pb-error" role="alert"></div>
        <button type="button" id="pbSubmit" class="pb-submit" disabled>Continue</button>
      </div>
    </div>
    <link rel="stylesheet" href="<?= get_base_url() ?>assets/css/profile-barrier.css?v=<?= ASSET_VERSION ?>">
    <script src="<?= get_base_url() ?>assets/js/profile-barrier.js?v=<?= ASSET_VERSION ?>" defer></script>
    <?php
}
