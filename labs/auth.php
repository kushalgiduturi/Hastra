<?php
// Hastra Labs — free community session (independent of enterprise accounts).
//
//   POST action=start     create a profile, show its recovery code once
//   POST action=restore   unlock an existing profile with its recovery code
//   POST action=signout   end this session (and forget this device)
//   POST action=delete    erase the profile and everything synced to it
//
// Every POST is CSRF-checked and answered with a redirect (PRG).
require __DIR__ . '/_boot.php';
labs_session_start();

$ready = labs_schema_ready($conn);
$profile = labs_current_profile($conn);
$lb = labs_base();

function labs_auth_back(string $flash = '', string $kind = 'ok'): void {
    if ($flash !== '') $_SESSION['labs_flash'] = [$kind, $flash];
    header('Location: ' . labs_base() . 'auth', true, 303);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!labs_csrf_ok()) labs_auth_back('That form expired. Please try again.', 'err');
    if (!$ready) labs_auth_back('Community sessions are not set up on this server yet (run the database migration).', 'err');
    $action = (string)($_POST['action'] ?? '');
    $remember = !empty($_POST['remember']);
    $client = labs_client_key();

    if ($action === 'start') {
        if (!labs_rate_hit($conn, "start:$client", 10, 3600)) labs_auth_back('Too many new sessions from this network. Try again in an hour.', 'err');
        $p = labs_profile_create($conn, (string)($_POST['display_name'] ?? ''));
        labs_sign_in($conn, $p['id'], $remember);
        $_SESSION['labs_new_code'] = $p['code'];   // shown exactly once, below
        labs_auth_back('Your free session is ready.');
    }
    if ($action === 'restore') {
        // 100-bit codes can't be guessed, but the limit keeps the endpoint cheap.
        if (!labs_rate_hit($conn, "restore:$client", 10, 900)) labs_auth_back('Too many attempts. Wait 15 minutes and try again.', 'err');
        $p = labs_profile_by_code($conn, (string)($_POST['code'] ?? ''));
        if (!$p) labs_auth_back('That recovery code does not match any profile. Check it and try again.', 'err');
        labs_sign_in($conn, $p['id'], $remember);
        labs_auth_back('Welcome back, ' . $p['name'] . '. Your progress is synced.');
    }
    if ($action === 'signout') {
        labs_sign_out($conn);
        labs_auth_back('Signed out. Your progress stays on this device and on the server.');
    }
    if ($action === 'delete' && $profile) {
        if (($_POST['confirm'] ?? '') !== 'DELETE') labs_auth_back('Type DELETE to confirm.', 'err');
        $stmt = mysqli_prepare($conn, "DELETE FROM labs_profiles WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $profile['id']);
        mysqli_stmt_execute($stmt);
        labs_sign_out($conn);
        labs_auth_back('Your community profile and its synced progress were deleted.');
    }
    labs_auth_back();
}

$flash = $_SESSION['labs_flash'] ?? null; unset($_SESSION['labs_flash']);
$new_code = $_SESSION['labs_new_code'] ?? null; unset($_SESSION['labs_new_code']);
$csrf = labs_csrf_token();

labs_head('Community session', 'auth', $profile);
?>
<section class="lx-hero lx-hero--compact">
  <p class="lx-eyebrow"><span class="lx-dot"></span> Community session · no email, no password</p>
  <h1 class="lx-h1">Your free Hastra Labs session</h1>
  <p class="lx-lead">A session only syncs your study progress between devices. Every tool works without one, and nothing
    you encrypt or hash ever leaves your browser. There is no email and no password: you get a recovery code, and we
    store only a one-way fingerprint of it.</p>
</section>

<?php if ($flash): ?>
  <div class="lx-alert lx-alert--<?= $flash[0] === 'err' ? 'err' : 'ok' ?>" role="<?= $flash[0] === 'err' ? 'alert' : 'status' ?>"><?= labs_e($flash[1]) ?></div>
<?php endif; ?>

<?php if (!$ready): ?>
  <div class="lx-alert lx-alert--err" role="alert">Community sessions need the Labs database tables. A sysadmin can create them from
    <b>Sysadmin → Database migration</b>, or run <code>php config/migrations/2026_09_labs.php</code>.</div>
<?php endif; ?>

<?php if ($new_code): ?>
  <section class="lx-pane lx-codebox" aria-labelledby="codehead">
    <h2 id="codehead" class="lx-h2">Save your recovery code now</h2>
    <p class="lx-muted">This is the only way back into this profile on another device, and it is shown <b>once</b>.
      Anyone holding it can read your synced study progress, so keep it private.</p>
    <div class="lx-code-row">
      <output class="lx-code" id="lx-recovery"><?= labs_e($new_code) ?></output>
      <button type="button" class="lx-btn btn-beam" data-copy="#lx-recovery">Copy</button>
      <button type="button" class="lx-btn lx-btn--ghost" data-download-code>Download .txt</button>
    </div>
  </section>
<?php endif; ?>

<?php if ($profile): ?>
  <section class="lx-grid-2">
    <div class="lx-pane">
      <h2 class="lx-h2">Signed in as <?= labs_e($profile['name']) ?></h2>
      <p class="lx-muted">Syllabus checkmarks, liked and skipped videos, and your study targets sync to this profile.</p>
      <div class="lx-row">
        <a class="lx-btn btn-beam" href="<?= $lb ?>syllabus">Open the study hub</a>
        <form method="post" action="<?= $lb ?>auth">
          <input type="hidden" name="csrf_token" value="<?= labs_e($csrf) ?>">
          <input type="hidden" name="action" value="signout">
          <button class="lx-btn lx-btn--ghost" type="submit">Sign out</button>
        </form>
      </div>
    </div>
    <div class="lx-pane lx-pane--danger">
      <h2 class="lx-h2">Delete my data</h2>
      <p class="lx-muted">Erases this profile and all synced progress from the server. Progress saved in this browser is
        not affected; clear it from the study hub if you want it gone too.</p>
      <form method="post" action="<?= $lb ?>auth" class="lx-row">
        <input type="hidden" name="csrf_token" value="<?= labs_e($csrf) ?>">
        <input type="hidden" name="action" value="delete">
        <label class="lx-sr" for="confirm-del">Type DELETE to confirm</label>
        <input class="lx-input" id="confirm-del" name="confirm" placeholder="Type DELETE" autocomplete="off" required pattern="DELETE">
        <button class="lx-btn lx-btn--danger" type="submit">Delete profile</button>
      </form>
    </div>
  </section>
<?php else: ?>
  <section class="lx-grid-2">
    <form class="lx-pane" method="post" action="<?= $lb ?>auth">
      <h2 class="lx-h2">Start a free session</h2>
      <input type="hidden" name="csrf_token" value="<?= labs_e($csrf) ?>">
      <input type="hidden" name="action" value="start">
      <label class="lx-label" for="dn">Display name <span class="lx-muted">(optional, shown only to you)</span></label>
      <input class="lx-input" id="dn" name="display_name" maxlength="40" placeholder="Student" autocomplete="nickname">
      <label class="lx-check"><input type="checkbox" name="remember" value="1" checked> Remember this device for 60 days</label>
      <button class="lx-btn btn-beam" type="submit" <?= $ready ? '' : 'disabled' ?>>Create my session</button>
    </form>
    <form class="lx-pane" method="post" action="<?= $lb ?>auth">
      <h2 class="lx-h2">Restore with a recovery code</h2>
      <input type="hidden" name="csrf_token" value="<?= labs_e($csrf) ?>">
      <input type="hidden" name="action" value="restore">
      <label class="lx-label" for="rc">Recovery code</label>
      <input class="lx-input lx-mono" id="rc" name="code" placeholder="HLAB-XXXXX-XXXXX-XXXXX-XXXXX" autocomplete="off" spellcheck="false" required>
      <label class="lx-check"><input type="checkbox" name="remember" value="1"> Remember this device for 60 days</label>
      <button class="lx-btn" type="submit" <?= $ready ? '' : 'disabled' ?>>Restore my progress</button>
    </form>
  </section>
<?php endif; ?>
<?php labs_foot(['labs-common']); ?>
