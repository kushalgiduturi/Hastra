<?php
// portals/deliveries/terminal.php
// Live handover terminal for ephemeral dossiers (core/ephemeral_dossier.php).
//
//   1. The recipient pastes the single-use access code (or arrives with it in
//      the URL fragment, #t=..., which the browser never sends to the server).
//   2. The server runs every gate for real: token lookup by blind index, the
//      PM + client dual-key signatures, invoice settlement (core/escrow.php),
//      then decrypts. The console streams those actual results; a failed gate
//      prints a failure line and stops before anything is decrypted or counted.
//   3. The moment the payload is delivered, the stored ciphertext is shredded
//      (overwritten with random bytes). The terminal is single-use. The
//      recipient copies or downloads the text, then wipes it from the screen.
//   4. Re-opening a shredded dossier shows its destruction receipt.
include __DIR__ . '/../../core/db.php';
secure_session_start();
verify_session($conn); // any signed-in role; the access code is the capability

$user_id = (int)$_SESSION["user_id"];

// ── JSON API ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    verify_csrf_token();

    $token  = strtolower(trim($_POST['token'] ?? ''));
    $action = $_POST['action'] ?? '';
    if ($token !== '') astra_canary_check($conn, 'canary_doc', $token);

    $steps = [];
    $step  = function (string $text, bool $ok, ?string $detail = null) use (&$steps) {
        $steps[] = ['text' => $text, 'ok' => $ok, 'detail' => $detail];
        return $ok;
    };
    $respond = function (array $extra = []) use (&$steps) {
        echo json_encode(['steps' => $steps] + $extra);
        exit();
    };

    if ($action === 'handshake') {
        $step('ASTRA CRYPTOGRAPHIC SUBSYSTEM INITIALIZED', true);
        astra_dossier_sweep_expired($conn);
        $peek = astra_dossier_peek($conn, $token, $err);

        if ($peek && (int)$peek['is_shredded'] === 1) {
            $step('Validating single-use HMAC token against blind index...', true, 'MATCH');
            $step('Dossier state: DESTROYED', false, 'This dossier was already opened and shredded.');
            $respond(['receipt' => [
                'dossier_id'   => (int)$peek['id'],
                'project'      => $peek['project_code'] . ': ' . $peek['project_title'],
                'shredded_at'  => $peek['shredded_at'],
            ]]);
        }
        if (!$step('Validating single-use HMAC token against blind index...', (bool)$peek, $peek ? 'MATCH' : ($err ?: 'No dossier matches this code.'))) {
            $respond();
        }

        $gate = astra_escrow_gate($conn, $peek);
        $step('Verifying PM and Client dual-key signature hashes...', $gate['signatures_valid'],
              $gate['signatures_valid'] ? 'BOTH VALID' : 'Signatures missing or invalid.');
        if (!$gate['signatures_valid']) $respond();

        $inv = $gate['invoice'];
        $settled = $inv && in_array($inv['status'], ['paid', 'waived'], true);
        $step($settled
                ? 'Settlement verified: Invoice ' . $inv['invoice_code'] . ($inv['status'] === 'waived' ? ' waived (no charge).' : ' settled.')
                : 'Settlement check: ' . ($inv ? 'Invoice ' . $inv['invoice_code'] . ' is unpaid.' : 'no invoice issued.'),
              $settled, $settled ? null : 'The handover unlocks once payment is confirmed.');
        if (!$gate['ok']) {
            if ($settled) $step('Release check', false, $gate['reason']);
            $respond();
        }

        $opened = astra_dossier_consume($conn, $token, $err);
        if (!$step('Decrypting AES-256-GCM envelope payload...', (bool)$opened, $opened ? 'SUCCESS' : ($err ?: 'Decryption failed.'))) {
            $respond();
        }
        // Single-use: shred the stored copy now that the payload is on its way.
        astra_dossier_shred($conn, $opened['id']);
        $block = astra_log_chained('DOSSIER_OPENED_AND_SHREDDED',
            "Dossier #{$opened['id']} for {$opened['project_code']} opened in the handover terminal and shredded",
            $user_id, $conn);
        $sq = mysqli_prepare($conn, "SELECT shredded_at FROM ephemeral_dossiers WHERE id = ?");
        mysqli_stmt_bind_param($sq, "i", $opened['id']);
        mysqli_stmt_execute($sq);
        $shredded_at = mysqli_fetch_row(mysqli_stmt_get_result($sq))[0] ?? null;

        $respond([
            'payload' => $opened['payload'],
            'project' => $opened['project_code'] . ': ' . $opened['project_title'],
            'receipt' => [
                'dossier_id'  => $opened['id'],
                'project'     => $opened['project_code'] . ': ' . $opened['project_title'],
                'shredded_at' => $shredded_at,
                'ledger_block'=> $block['chain_index'] ?? null,
                'ledger_hash' => $block['current_hash'] ?? null,
            ],
        ]);
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action.']);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="referrer" content="no-referrer">
<title>Handover Terminal · Astra</title>
<script src="<?= get_base_url() ?>core/theme.js?v=<?= ASSET_VERSION ?>"></script>
<link rel="stylesheet" href="<?= get_base_url() ?>core/theme.css?v=<?= ASSET_VERSION ?>">
<link rel="stylesheet" href="<?= get_base_url() ?>assets/css/theme-authkit.css?v=<?= ASSET_VERSION ?>">
<link rel="stylesheet" href="<?= get_base_url() ?>assets/css/handover-terminal.css?v=<?= ASSET_VERSION ?>">
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&display=swap" rel="stylesheet">
</head>
<body class="ht-body">
<main class="ht-main">
  <a class="ht-back" href="<?= get_base_url() ?>portals/<?= ($_SESSION['user_role'] ?? '') === 'client' ? 'client/client_portal' : (($_SESSION['user_role'] ?? '') === 'employee' ? 'emlpoyee/employee_portal' : 'admin/admin_portal') ?>">&larr; Back</a>

  <section class="ht-terminal" aria-labelledby="htTitle">
    <div class="ht-wash" aria-hidden="true"></div>
    <header class="ht-head">
      <span class="ht-dots" aria-hidden="true"><i></i><i></i><i></i></span>
      <h1 id="htTitle">Astra secure handover</h1>
      <span class="ht-state" id="htState">IDLE</span>
    </header>

    <form class="ht-form" id="htForm" autocomplete="off">
      <label for="htCode">Single-use access code</label>
      <div class="ht-row">
        <input id="htCode" name="token" type="password" inputmode="text" spellcheck="false"
               pattern="[a-fA-F0-9]{64}" required placeholder="Paste the 64-character code">
        <button type="submit" class="ht-btn" id="htGo">Decrypt</button>
      </div>
      <p class="ht-hint">The contents can be viewed once. They are destroyed from storage the moment they are shown.</p>
    </form>

    <div class="ht-console" id="htConsole" role="log" aria-live="polite" hidden></div>

    <div class="ht-payload" id="htPayload" hidden>
      <div class="ht-payload-bar">
        <span id="htProject"></span>
        <span class="ht-actions">
          <button type="button" class="ht-btn ht-btn-ghost" id="htCopy">Copy</button>
          <button type="button" class="ht-btn ht-btn-ghost" id="htDownload">Download .txt</button>
          <button type="button" class="ht-btn ht-btn-danger" id="htWipe">I've saved it: wipe from screen</button>
        </span>
      </div>
      <pre id="htText"></pre>
      <p class="ht-warn">This is the only copy. It no longer exists on Astra's servers.</p>
    </div>

    <div class="ht-receipt" id="htReceipt" hidden>
      <div class="ht-receipt-title">DOSSIER SECURELY DESTROYED</div>
      <dl>
        <dt>Dossier</dt><dd id="rcDossier"></dd>
        <dt>Project</dt><dd id="rcProject"></dd>
        <dt>Shredded at</dt><dd id="rcAt"></dd>
        <dt>Ledger block</dt><dd id="rcBlock"></dd>
      </dl>
      <p>The encrypted payload was overwritten with random bytes and cannot be recovered. The destruction is recorded in Astra's tamper-evident audit ledger.</p>
    </div>
  </section>
</main>

<script>
  window.ASTRA_TERMINAL = { csrf: <?= json_encode(generate_csrf_token()) ?>, endpoint: 'terminal' };
</script>
<script src="<?= get_base_url() ?>assets/js/handover-terminal.js?v=<?= ASSET_VERSION ?>"></script>
</body>
</html>
