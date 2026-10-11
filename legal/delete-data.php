<?php
// legal/delete-data.php  (address: /privacy/delete-data)
// Self-service data deletion request. See core/data_deletion.php for what is
// erased and kept, and why nothing is deleted until the emailed link is
// confirmed with a button press.
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../core/data_deletion.php';

$b      = get_base_url();
$state  = 'form';          // form | sent | confirm | done | error
$notice = '';
$errors = [];
$email_value = '';
$token  = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$ip     = astra_rate_client_ip();

// prefill for a signed-in visitor (they still confirm by email: a left-open
// laptop is not consent to erase an account)
if (!empty($_SESSION['user_id'])) {
    $me = mysqli_prepare($conn, "SELECT email FROM users WHERE id = ?");
    $uid = (int)$_SESSION['user_id'];
    mysqli_stmt_bind_param($me, "i", $uid);
    mysqli_stmt_execute($me);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($me));
    if ($row) { astra_decrypt_user_row($row); $email_value = (string)$row['email']; }
}

function dd_csrf_ok(): bool {
    return isset($_POST['csrf_token'], $_SESSION['csrf_token']) && is_string($_POST['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}
function dd_mask(string $email): string {
    [$u, $d] = array_pad(explode('@', $email, 2), 2, '');
    return mb_substr($u, 0, 1) . str_repeat('•', max(2, mb_strlen($u) - 1)) . '@' . $d;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if (!dd_csrf_ok()) {
        $state = 'error';
        $notice = 'This form expired. Reload the page and try again.';
    } elseif ($action === 'request') {
        $email_value = trim((string)($_POST['email'] ?? ''));
        if ($email_value === '') $errors['email'] = 'Enter the email address on your Hastra account.';
        elseif (!filter_var($email_value, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email address, like name@example.com.';
        if (empty($_POST['understand'])) $errors['understand'] = 'Tick this box to confirm you understand erasure is permanent.';

        if (!$errors) {
            if (!astra_rate_allow($conn, 'erase-ip:' . $ip, 5, 3600) || !astra_rate_allow($conn, 'erase-email:' . strtolower($email_value), 3, 3600)) {
                $state = 'error';
                $notice = 'Too many requests. Wait an hour and try again, or email ' . LEGAL_SUPPORT_EMAIL . '.';
            } else {
                $user = astra_erasure_find_user_by_email($conn, $email_value);
                if ($user && !astra_erasure_blocker($conn, $user) && ($tok = astra_erasure_issue_token($conn, (int)$user['id']))) {
                    $link = APP_URL . 'privacy/delete-data?token=' . $tok;
                    try {
                        astra_send_mail($user['email'], 'Confirm erasing your Hastra account',
                            "Hi {$user['name']},\n\nSomeone (hopefully you) asked to erase the Hastra account for this address.\n\n" .
                            "To confirm, open this link within one hour and press the button on the page:\n$link\n\n" .
                            "Nothing has been deleted yet. If you did not ask for this, ignore this email and your account stays as it is.");
                    } catch (Throwable $e) { error_log('erasure mail failed: ' . $e->getMessage()); }
                } elseif ($user && ($why = astra_erasure_blocker($conn, $user))) {
                    try {
                        astra_send_mail($user['email'], 'About your Hastra erasure request',
                            "Hi {$user['name']},\n\nWe received a request to erase your Hastra account, but it can't be done yet:\n\n$why\n\n" .
                            "Nothing has been deleted. Reply to " . LEGAL_SUPPORT_EMAIL . " if you need help.");
                    } catch (Throwable $e) { error_log('erasure mail failed: ' . $e->getMessage()); }
                }
                // same answer either way: the form must not reveal who has an account
                $state = 'sent';
            }
        }
    } elseif ($action === 'confirm') {
        $req = astra_erasure_lookup_token($conn, $token);
        if (!$req) {
            $state = 'error';
            $notice = 'This link has expired or was already used. Request a new one below.';
        } else {
            $res = astra_erase_user($conn, (int)$req['user_id'], (int)$req['id']);
            if ($res['ok']) {
                $state = 'done';
                if ((int)($_SESSION['user_id'] ?? 0) === (int)$req['user_id']) { session_unset(); session_destroy(); }
                try {
                    astra_send_mail($res['email'], 'Your Hastra account has been erased',
                        "Your Hastra account and the personal data attached to it have been erased.\n\n" .
                        "Records your organization must keep (such as invoices and project history) remain with them, no longer linked to your name or contact details.\n\n" .
                        "This is the last email we will send to this address.");
                } catch (Throwable $e) { error_log('erasure mail failed: ' . $e->getMessage()); }
            } else {
                $state = 'error';
                $notice = $res['message'];
            }
        }
    }
} elseif ($token !== '') {
    $req = astra_erasure_lookup_token($conn, $token);
    if ($req) {
        $state = 'confirm';
        $st = mysqli_prepare($conn, "SELECT email FROM users WHERE id = ?");
        $rid = (int)$req['user_id'];
        mysqli_stmt_bind_param($st, "i", $rid);
        mysqli_stmt_execute($st);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
        astra_decrypt_user_row($row);
        $masked = dd_mask((string)($row['email'] ?? ''));
    } else {
        $state = 'error';
        $notice = 'This link has expired or was already used. Request a new one below.';
    }
}

$csrf = generate_csrf_token();
legal_page_start('delete-data', 'Delete my data', 'Ask Hastra to erase your account and the personal data attached to it. Confirmed by email, completed immediately, with no fee and no questions asked.');
?>
<style>
  .dd-form { display: grid; gap: 1.1rem; max-width: 34rem; margin-top: 1.5rem; }
  .dd-form label { font-weight: 600; }
  .dd-form input[type="email"] { width: 100%; min-height: 48px; padding: 0 14px; font: inherit; border-radius: 10px;
    border: 1px solid var(--border-dim); background: var(--input-bg); color: var(--text); }
  .dd-check { display: flex; gap: .65rem; align-items: flex-start; font-weight: 400 !important; }
  .dd-check input { width: 22px; height: 22px; margin-top: 2px; flex: none; accent-color: var(--accent); }
  .dd-actions { display: flex; flex-wrap: wrap; gap: 1rem; align-items: center; }
  .dd-btn { min-height: 48px; padding: 0 22px; border: 0; border-radius: 10px; font: 700 .95rem/1 var(--font-sans);
    background: var(--red); color: #fff; cursor: pointer; transition: transform .12s, filter .15s; }
  .dd-btn:hover { filter: brightness(1.08); }
  .dd-btn:active { transform: translateY(1px); }
  .dd-status { margin-top: 1.5rem; padding-left: 1rem; border-left: 3px solid var(--green); }
  .dd-status.is-error { border-left-color: var(--red); }
  .dd-status h2 { margin: 0 0 .35rem; font-size: 1.15rem; }
  .dd-list li { margin: .3rem 0; }
</style>

<?php if ($state === 'sent'): ?>
  <div class="dd-status" role="status" tabindex="-1" data-autofocus>
    <h2><span aria-hidden="true">✓</span> Check your email</h2>
    <p>If <strong><?= htmlspecialchars($email_value) ?></strong> belongs to a Hastra account, we have sent it a link to confirm the erasure. The link works once, for one hour. Nothing is deleted until you open it and confirm.</p>
    <p>No email after a few minutes? Check your spam folder, or write to <a href="mailto:<?= htmlspecialchars(LEGAL_SUPPORT_EMAIL) ?>"><?= htmlspecialchars(LEGAL_SUPPORT_EMAIL) ?></a>.</p>
  </div>

<?php elseif ($state === 'confirm'): ?>
  <p>You are about to erase the Hastra account for <strong><?= htmlspecialchars($masked) ?></strong>. This cannot be undone.</p>
  <form method="post" action="<?= $b ?>privacy/delete-data" class="dd-form"
        data-confirm="Erase this account and its personal data permanently? You will be signed out and cannot recover it."
        data-confirm-action="Erase permanently" data-confirm-danger>
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
    <input type="hidden" name="action" value="confirm">
    <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
    <div class="dd-actions">
      <button type="submit" class="dd-btn">Erase my account</button>
      <a href="<?= $b ?>">Keep my account</a>
    </div>
  </form>

<?php elseif ($state === 'done'): ?>
  <div class="dd-status" role="status" tabindex="-1" data-autofocus>
    <h2><span aria-hidden="true">✓</span> Your data has been erased</h2>
    <p>Your account is closed and every personal detail on it has been removed. You have been signed out on every device, and a final confirmation is on its way to your old address.</p>
    <p><a href="<?= $b ?>">Return to the home page</a></p>
  </div>

<?php else: ?>
  <?php if ($state === 'error'): ?>
    <div class="dd-status is-error" role="alert"><p><?= htmlspecialchars($notice) ?></p></div>
  <?php endif; ?>

  <p>You can ask us to erase your Hastra account and the personal data attached to it at any time. There is no fee and you do not need to give a reason.</p>
  <h2>What is erased</h2>
  <ul class="dd-list">
    <li>Your name, email address, phone number, gender and profile links</li>
    <li>Your password, sign-in codes, Google sign-in link and every active session</li>
    <li>Saved tour progress and any roster rows staged for you</li>
  </ul>
  <h2>What stays, without your name</h2>
  <ul class="dd-list">
    <li>Projects, tasks, invoices and sign-offs your organization must keep. They remain, linked to "Deleted user" instead of you.</li>
    <li>The tamper-evident security log, which records that an erasure happened but not your details.</li>
    <li>Anything stored only in your own browser by Hastra Labs. Clear it from the Labs pages or your browser settings.</li>
  </ul>

  <form method="post" action="<?= $b ?>privacy/delete-data" class="dd-form" novalidate data-validate>
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
    <input type="hidden" name="action" value="request">
    <div>
      <label for="dd-email">Account email <span aria-hidden="true">*</span></label>
      <input type="email" id="dd-email" name="email" required autocomplete="email" maxlength="190"
             value="<?= htmlspecialchars($email_value) ?>" aria-describedby="dd-email-help<?= isset($errors['email']) ? ' dd-email-err' : '' ?>"
             <?= isset($errors['email']) ? 'aria-invalid="true"' : '' ?>>
      <p class="field-hint" id="dd-email-help">We will email a confirmation link to this address.</p>
      <?php if (isset($errors['email'])): ?><p class="field-error" id="dd-email-err"><?= htmlspecialchars($errors['email']) ?></p><?php endif; ?>
    </div>
    <div>
      <label class="dd-check"><input type="checkbox" name="understand" value="1" required
             aria-describedby="<?= isset($errors['understand']) ? 'dd-und-err' : '' ?>" <?= isset($errors['understand']) ? 'aria-invalid="true"' : '' ?>>
        <span>I understand erasure is permanent and my account cannot be recovered.</span></label>
      <?php if (isset($errors['understand'])): ?><p class="field-error" id="dd-und-err"><?= htmlspecialchars($errors['understand']) ?></p><?php endif; ?>
    </div>
    <div class="dd-actions"><button type="submit" class="dd-btn">Send confirmation link</button></div>
  </form>
  <p>Prefer email? Write to <a href="mailto:<?= htmlspecialchars(LEGAL_SUPPORT_EMAIL) ?>?subject=Data%20deletion%20request"><?= htmlspecialchars(LEGAL_SUPPORT_EMAIL) ?></a> from the address on your account.</p>
<?php endif; ?>
<?php legal_page_end(); ?>
