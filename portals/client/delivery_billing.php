<?php
// portals/client/delivery_billing.php
include __DIR__ . '/../../core/db.php';
secure_session_start();
verify_session($conn, "client");

$ctx           = client_context($conn, (int)$_SESSION["user_id"]);
$scope_ids     = id_list($ctx['member_ids']);
$can_pay       = client_can($ctx, 'pay_invoice');
$can_security  = client_can($ctx, 'security_summary') && access_schema_ready($conn);
if (!client_can($ctx, 'view_projects')) {
    header("Location: " . APP_URL . "workspace/client/docs");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    verify_csrf_token();
    $action = $_POST["action"] ?? "";

    if ($action === "pay_invoice") {
        header('Content-Type: application/json');
        $invoice_id = (int)($_POST["invoice_id"] ?? 0);
        $pay_method = trim($_POST["pay_method"] ?? "");

        $method_labels = [
            'upi'        => 'UPI',
            'card'       => 'Credit/Debit Card',
            'netbanking' => 'Net Banking',
        ];

        if (!$can_pay) {
            echo json_encode(['success' => false, 'message' => "Only your Project Manager or IT Manager can pay invoices."]);
            exit();
        }
        if (!$invoice_id || !array_key_exists($pay_method, $method_labels)) {
            echo json_encode(['success' => false, 'message' => 'Invalid request.']);
            exit();
        }

        // Verify this invoice belongs to this client
        $inv_check = mysqli_prepare($conn,
            "SELECT inv.* FROM invoices inv
             JOIN projects p ON inv.project_id = p.id
             JOIN requirements r ON p.requirement_id = r.id
             WHERE inv.id = ? AND r.user_id IN ($scope_ids)"
        );
        mysqli_stmt_bind_param($inv_check, "i", $invoice_id);
        mysqli_stmt_execute($inv_check);
        $inv_row = mysqli_fetch_assoc(mysqli_stmt_get_result($inv_check));

        if (!$inv_row) {
            echo json_encode(['success' => false, 'message' => 'Invoice not found.']);
            exit();
        }

        if (in_array($inv_row['status'], ['paid', 'waived'], true)) {
            echo json_encode(['success' => false, 'message' => 'Invoice is already paid.']);
            exit();
        }
        // Milestone escrow invoices release deliverables, so they settle only
        // when Hastra confirms the money arrived (admin clearance or the signed
        // payment webhook), never from this button (core/escrow.php).
        if (!empty($inv_row['milestone_id'])) {
            echo json_encode(['success' => false, 'message' => "This invoice is held in milestone escrow. It's marked paid once your payment is confirmed, and your handover unlocks automatically."]);
            exit();
        }

        $upd = mysqli_prepare($conn,
            "UPDATE invoices SET status = 'paid', paid_at = NOW() WHERE id = ?"
        );
        mysqli_stmt_bind_param($upd, "i", $invoice_id);

        if (mysqli_stmt_execute($upd)) {
            echo json_encode([
                'success'      => true,
                'method_label' => $method_labels[$pay_method],
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to record payment.']);
        }
        exit();
    }
}

// ── Fetch this client's invoices + deliveries ─────────────────────────────────
$my_invoices = mysqli_prepare($conn,
    "SELECT inv.*, p.project_code, p.title AS project_title
     FROM invoices inv
     JOIN projects p ON inv.project_id = p.id
     JOIN requirements r ON p.requirement_id = r.id
     WHERE r.user_id IN ($scope_ids)
     ORDER BY inv.created_at DESC"
);
mysqli_stmt_execute($my_invoices);
$my_invoice_rows = mysqli_stmt_get_result($my_invoices)->fetch_all(MYSQLI_ASSOC);

$my_deliveries = mysqli_prepare($conn,
    "SELECT d.*, p.project_code, p.title AS project_title
     FROM deliveries d
     JOIN projects p ON d.project_id = p.id
     JOIN requirements r ON p.requirement_id = r.id
     WHERE r.user_id IN ($scope_ids)
     ORDER BY d.delivered_at DESC"
);
mysqli_stmt_execute($my_deliveries);
$my_delivery_rows = mysqli_stmt_get_result($my_deliveries)->fetch_all(MYSQLI_ASSOC);
foreach ($my_delivery_rows as &$dr) $dr['has_doc'] = (bool)approved_doc($conn, (int)$dr['project_id']);
unset($dr);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<link rel="icon" type="image/svg+xml" href="<?= get_base_url() ?>assets/images/hastra-logo.svg">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Delivery &amp; Billing · Hastra</title>
<script src="<?= get_base_url() ?>core/theme.js?v=<?= ASSET_VERSION ?>"></script>
<link rel="stylesheet" href="<?= get_base_url() ?>core/theme.css?v=<?= ASSET_VERSION ?>"><link rel="stylesheet" href="<?= get_base_url() ?>assets/css/theme-contrast.css?v=<?= ASSET_VERSION ?>">
<link rel="stylesheet" href="<?= get_base_url() ?>assets/css/custom-dropdowns.css?v=<?= ASSET_VERSION ?>">
<script src="<?= get_base_url() ?>assets/js/custom-dropdowns.js?v=<?= ASSET_VERSION ?>"></script>
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= get_base_url() ?>core/client_pages.css">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    min-height: 100vh;
    background-color: var(--navy);
    background-image:
      linear-gradient(var(--grid-line) 1px, transparent 1px),
      linear-gradient(90deg, var(--grid-line) 1px, transparent 1px);
    background-size: 40px 40px;
    font-family: var(--font-sans);
    color: var(--text);
    transition: var(--transition);
  }

  .main { max-width: 1100px; margin: 0 auto; padding: 2rem; }

  .page-header { margin-bottom: 2rem; }
  .page-header h1 { font-size: 24px; font-weight: 600; color: var(--text); letter-spacing: -0.01em; margin-bottom: 4px; }
  .page-header p  { font-size: 13px; color: var(--text-dim); }

  .section {
    background: var(--navy-card);
    border: 1px solid var(--border-dim);
    border-radius: 4px;
    overflow: hidden;
    margin-bottom: 1.5rem;
  }

  .section-header {
    display: flex; align-items: center; gap: 8px;
    padding: 1rem 1.4rem;
    border-bottom: 1px solid var(--border-dim);
    background: var(--section-header-bg);
    font-size: 13px; font-weight: 600;
    color: var(--text);
    letter-spacing: 0.03em; text-transform: uppercase;
  }
  .section-header svg { width: 15px; height: 15px; fill: var(--accent-bright); }

  .section-body { padding: 1.4rem; }

  .req-id-badge {
    font-family: 'Share Tech Mono', monospace;
    font-size: 12px; font-weight: 600;
    color: var(--accent-bright);
    background: rgba(var(--accent-rgb),0.08);
    border: 1px solid rgba(var(--accent-rgb),0.2);
    padding: 2px 8px; border-radius: 2px;
    letter-spacing: 0.08em;
  }

  .badge {
    display: inline-block; padding: 2px 8px; border-radius: 2px;
    font-size: 11px; font-family: 'Share Tech Mono', monospace;
    letter-spacing: 0.05em; text-transform: uppercase; font-weight: 500;
  }
  .badge-approved             { background: rgba(34,197,94,0.1);    color: var(--green);       border: 1px solid rgba(34,197,94,0.2); }
  .badge-clarification_needed { background: rgba(245,158,11,0.1);   color: var(--yellow);      border: 1px solid rgba(245,158,11,0.2); }

  .empty-state {
    text-align: center; padding: 3rem 1rem;
    color: var(--text-dim); font-size: 13px;
  }
  .empty-state svg { width: 36px; height: 36px; fill: var(--border-dim); margin: 0 auto 0.8rem; display: block; }
  .empty-state p   { margin-bottom: 0.3rem; }
  .empty-state span { font-size: 12px; }

  /* ── PAYMENT MODAL ── */
  @keyframes spin { to { transform: rotate(360deg); } }
  .modal-overlay {
    display: flex; position: fixed; inset: 0;
    background: rgba(0,0,0,0.75); z-index: 200;
    align-items: center; justify-content: center;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.2s ease;
  }
  .modal-overlay.open {
    opacity: 1;
    pointer-events: auto;
  }

  .modal {
    background: var(--navy-card);
    border: 1px solid var(--border);
    border-radius: 4px;
    width: 100%; max-width: 380px;
    padding: 1.8rem; position: relative;
  }

  .modal::before {
    content: '';
    position: absolute; top: 0; left: 0; right: 0;
    height: 2px;
    background: linear-gradient(90deg, transparent, var(--red), transparent);
    border-radius: 4px 4px 0 0;
  }

  .modal h3 { font-size: 16px; font-weight: 600; color: var(--text); margin-bottom: 0.5rem; }
  .modal p  { font-size: 13px; color: var(--text-dim); margin-bottom: 1.4rem; line-height: 1.5; }
</style>
</head>
<body>

<?php $nav_current = 'projects'; include __DIR__ . '/_nav.php'; ?>

<div class="main">

  <div class="page-header">
    <h1>Delivery &amp; Billing</h1>
    <p>Completed projects, deliverables and invoices for your company.</p>
  </div>

  <div class="section">
    <div class="section-header">
      <svg viewBox="0 0 24 24"><path d="M20 8h-3V4H3c-1.1 0-2 .9-2 2v11h2c0 1.66 1.34 3 3 3s3-1.34 3-3h6c0 1.66 1.34 3 3 3s3-1.34 3-3h2v-5l-3-4z"/></svg>
      Completed Projects: Delivery &amp; Billing
    </div>
    <div class="section-body">
      <?php if (empty($my_delivery_rows) && empty($my_invoice_rows)): ?>
      <div class="empty-state">
        <svg viewBox="0 0 24 24"><path d="M20 8h-3V4H3c-1.1 0-2 .9-2 2v11h2c0 1.66 1.34 3 3 3s3-1.34 3-3h6c0 1.66 1.34 3 3 3s3-1.34 3-3h2v-5l-3-4z"/></svg>
        <p>Nothing here yet.</p>
        <span>Deliverables and invoices will show up once a project is completed.</span>
      </div>
      <?php endif; ?>
      <?php foreach ($my_delivery_rows as $d): ?>
      <div style="background:var(--navy);border:1px solid var(--border-dim);border-radius:3px;padding:1rem 1.2rem;margin-bottom:1rem;">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
          <span class="req-id-badge"><?= htmlspecialchars($d['project_code']) ?></span>
          <strong><?= htmlspecialchars($d['project_title']) ?></strong>
        </div>
        <div style="display:flex;gap:16px;flex-wrap:wrap;font-size:13px;">
          <?php if (safe_url($d['source_code_link'])): ?><a href="<?= htmlspecialchars(safe_url($d['source_code_link'])) ?>" target="_blank" rel="noopener noreferrer" style="color:var(--accent-bright);">Source Code</a><?php endif; ?>
          <?php if ($d['has_doc']): ?><a href="<?= get_base_url() ?>workspace/client/doc-view?project=<?= (int)$d['project_id'] ?>" style="color:var(--accent-bright);">Documentation</a>
          <?php elseif (safe_url($d['documentation_link'])): ?><a href="<?= htmlspecialchars(safe_url($d['documentation_link'])) ?>" target="_blank" rel="noopener noreferrer" style="color:var(--accent-bright);">Documentation</a><?php endif; ?>
          <?php if ($d['has_doc'] && safe_url($d['documentation_link'])): ?><a href="<?= htmlspecialchars(safe_url($d['documentation_link'])) ?>" target="_blank" rel="noopener noreferrer" style="color:var(--accent-bright);">External docs</a><?php endif; ?>
          <?php if (safe_url($d['deployment_link'])): ?><a href="<?= htmlspecialchars(safe_url($d['deployment_link'])) ?>" target="_blank" rel="noopener noreferrer" style="color:var(--accent-bright);">Live Deployment</a><?php endif; ?>
        </div>
        <?php if (access_schema_ready($conn)): ?>
        <div style="margin-top:10px;padding-top:10px;border-top:1px solid var(--border-dim);font-size:13px;color:var(--text-dim);display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
          <?php if (!empty($d['security_viewed_at'])): ?>
            Security summary viewed on <?= htmlspecialchars(date('d M Y, H:i', strtotime($d['security_viewed_at']))) ?>. It can't be opened again.
          <?php elseif ($can_security): ?>
            <span>Security summary and handover credentials: <b style="color:var(--yellow);">can be opened only once</b>.</span>
            <form method="POST" action="<?= get_base_url() ?>workspace/client/security-view" style="display:inline;"
                  onsubmit="return confirm('The security summary can be opened only once. Open it now?')">
              <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
              <input type="hidden" name="delivery_id" value="<?= (int)$d['id'] ?>">
              <button type="submit" style="background:rgba(245,158,11,0.1);border:1px solid rgba(245,158,11,0.35);color:var(--yellow);font-family:var(--font-sans);font-size:12px;font-weight:600;padding:5px 12px;border-radius:3px;cursor:pointer;">Open security summary</button>
            </form>
          <?php else: ?>
            Your Project Manager can open this project's one-time security summary.
          <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php
          // Find matching invoice
          $matched_invoice = null;
          foreach ($my_invoice_rows as $inv) {
            if ($inv['project_code'] === $d['project_code']) { $matched_invoice = $inv; break; }
          }
        ?>
        <?php if ($matched_invoice): ?>
        <div style="margin-top:10px;padding-top:10px;border-top:1px solid var(--border-dim);font-size:13px;color:var(--text-dim);">
          Invoice <strong style="color:var(--text);"><?= htmlspecialchars($matched_invoice['invoice_code']) ?></strong>:
          ₹<?= number_format($matched_invoice['total_amount'], 2) ?>
          <span class="badge badge-<?= $matched_invoice['status'] === 'paid' ? 'approved' : 'clarification_needed' ?>" style="margin-left:6px;"><?= htmlspecialchars($matched_invoice['status']) ?></span>
          <?php if ($matched_invoice['status'] !== 'paid' && $can_pay): ?>
          <button onclick="openPayModal(<?= $matched_invoice['id'] ?>, '<?= htmlspecialchars($matched_invoice['invoice_code']) ?>', '<?= number_format($matched_invoice['total_amount'], 2) ?>')"
            style="margin-left:10px;background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.3);color:var(--green);font-family:var(--font-sans);font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:0.04em;padding:5px 14px;border-radius:3px;cursor:pointer;">
            Pay Now
          </button>
          <?php else: ?>
          <span style="margin-left:10px;font-size:11px;color:var(--green);font-family:'Share Tech Mono',monospace;">✓ Paid on <?= $matched_invoice['paid_at'] ? date('d M Y', strtotime($matched_invoice['paid_at'])) : '' ?></span>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

</div>

<!-- ── PAYMENT MODAL ── -->
<div class="modal-overlay" id="payModal">
  <div class="modal">
    <h3>Pay Invoice</h3>
    <p id="payModalDesc" style="font-size:13px;color:var(--text-dim);margin-bottom:1.4rem;line-height:1.5;"></p>

    <div id="payStep1">
      <div style="background:var(--input-bg);border:1px solid var(--border-dim);border-radius:3px;padding:1rem 1.2rem;margin-bottom:1.2rem;">
        <div style="font-size:11px;font-family:'Share Tech Mono',monospace;letter-spacing:0.08em;text-transform:uppercase;color:var(--text-dim);margin-bottom:10px;">Select Payment Method</div>
        <div style="display:flex;flex-direction:column;gap:8px;">
          <label style="display:flex;align-items:center;gap:10px;padding:10px 12px;background:var(--input-bg);border:1px solid var(--border-dim);border-radius:3px;cursor:pointer;transition:border-color 0.2s;" id="method_upi_label">
            <input type="radio" name="pay_method" value="upi" onchange="selectMethod('upi')" style="accent-color:var(--accent-bright);">
            <div>
              <div style="font-size:13px;font-weight:600;">UPI</div>
              <div style="font-size:11px;color:var(--text-dim);">Pay via Google Pay, PhonePe, Paytm</div>
            </div>
          </label>
          <label style="display:flex;align-items:center;gap:10px;padding:10px 12px;background:var(--input-bg);border:1px solid var(--border-dim);border-radius:3px;cursor:pointer;transition:border-color 0.2s;" id="method_card_label">
            <input type="radio" name="pay_method" value="card" onchange="selectMethod('card')" style="accent-color:var(--accent-bright);">
            <div>
              <div style="font-size:13px;font-weight:600;">Credit / Debit Card</div>
              <div style="font-size:11px;color:var(--text-dim);">Visa, Mastercard, RuPay</div>
            </div>
          </label>
          <label style="display:flex;align-items:center;gap:10px;padding:10px 12px;background:var(--input-bg);border:1px solid var(--border-dim);border-radius:3px;cursor:pointer;transition:border-color 0.2s;" id="method_netbanking_label">
            <input type="radio" name="pay_method" value="netbanking" onchange="selectMethod('netbanking')" style="accent-color:var(--accent-bright);">
            <div>
              <div style="font-size:13px;font-weight:600;">Net Banking</div>
              <div style="font-size:11px;color:var(--text-dim);">All major banks supported</div>
            </div>
          </label>
        </div>
      </div>

      <!-- UPI fields -->
      <div id="upi_fields" style="display:none;margin-bottom:1.2rem;">
        <div style="margin-bottom:0.8rem;">
          <label style="display:block;font-size:11px;font-family:'Share Tech Mono',monospace;letter-spacing:0.07em;text-transform:uppercase;color:var(--text-dim);margin-bottom:6px;">UPI ID</label>
          <input type="text" id="upi_id" placeholder="yourname@upi" style="width:100%;background:var(--input-bg);border:1px solid var(--border-dim);border-radius:3px;color:var(--text);font-family:var(--font-sans);font-size:13px;padding:9px 12px;outline:none;">
        </div>
      </div>

      <!-- Card fields -->
      <div id="card_fields" style="display:none;margin-bottom:1.2rem;">
        <div style="margin-bottom:0.8rem;">
          <label style="display:block;font-size:11px;font-family:'Share Tech Mono',monospace;letter-spacing:0.07em;text-transform:uppercase;color:var(--text-dim);margin-bottom:6px;">Card Number</label>
          <input type="text" id="card_number" placeholder="1234 5678 9012 3456" maxlength="19" oninput="formatCard(this)" style="width:100%;background:var(--input-bg);border:1px solid var(--border-dim);border-radius:3px;color:var(--text);font-family:'Share Tech Mono',monospace;font-size:14px;padding:9px 12px;outline:none;letter-spacing:0.1em;">
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
          <div>
            <label style="display:block;font-size:11px;font-family:'Share Tech Mono',monospace;letter-spacing:0.07em;text-transform:uppercase;color:var(--text-dim);margin-bottom:6px;">Expiry (MM/YY)</label>
            <input type="text" id="card_expiry" placeholder="MM/YY" maxlength="5" oninput="formatExpiry(this)" style="width:100%;background:var(--input-bg);border:1px solid var(--border-dim);border-radius:3px;color:var(--text);font-family:'Share Tech Mono',monospace;font-size:13px;padding:9px 12px;outline:none;">
          </div>
          <div>
            <label style="display:block;font-size:11px;font-family:'Share Tech Mono',monospace;letter-spacing:0.07em;text-transform:uppercase;color:var(--text-dim);margin-bottom:6px;">CVV</label>
            <input type="text" id="card_cvv" placeholder="•••" maxlength="3" style="width:100%;background:var(--input-bg);border:1px solid var(--border-dim);border-radius:3px;color:var(--text);font-family:'Share Tech Mono',monospace;font-size:13px;padding:9px 12px;outline:none;">
          </div>
        </div>
        <div style="margin-top:0.8rem;">
          <label style="display:block;font-size:11px;font-family:'Share Tech Mono',monospace;letter-spacing:0.07em;text-transform:uppercase;color:var(--text-dim);margin-bottom:6px;">Name on Card</label>
          <input type="text" id="card_name" placeholder="John Doe" style="width:100%;background:var(--input-bg);border:1px solid var(--border-dim);border-radius:3px;color:var(--text);font-family:var(--font-sans);font-size:13px;padding:9px 12px;outline:none;">
        </div>
      </div>

      <!-- Net Banking fields -->
      <div id="netbanking_fields" style="display:none;margin-bottom:1.2rem;">
        <div>
          <label style="display:block;font-size:11px;font-family:'Share Tech Mono',monospace;letter-spacing:0.07em;text-transform:uppercase;color:var(--text-dim);margin-bottom:6px;">Select Bank</label>
          <select id="bank_select" style="width:100%;background:var(--input-bg);border:1px solid var(--border-dim);border-radius:3px;color:var(--text);font-family:var(--font-sans);font-size:13px;padding:9px 12px;outline:none;">
            <option value="">Select a bank</option>
            <option value="sbi">State Bank of India</option>
            <option value="hdfc">HDFC Bank</option>
            <option value="icici">ICICI Bank</option>
            <option value="axis">Axis Bank</option>
            <option value="kotak">Kotak Mahindra Bank</option>
            <option value="pnb">Punjab National Bank</option>
            <option value="bob">Bank of Baroda</option>
            <option value="other">Other</option>
          </select>
        </div>
      </div>

      <div id="payModalAlert" style="display:none;padding:8px 12px;border-radius:3px;font-size:12px;margin-bottom:1rem;border-left:3px solid var(--red);background:var(--red-bg);color:#fca5a5;"></div>

      <div style="display:flex;gap:8px;">
        <button onclick="processPayment()" id="btnPay"
          style="flex:1;background:var(--green);color:white;border:none;border-radius:3px;font-family:var(--font-sans);font-size:13px;font-weight:600;text-transform:uppercase;letter-spacing:0.04em;padding:11px;cursor:pointer;transition:background 0.2s;">
          Pay ₹<span id="payAmountBtn">0</span>
        </button>
        <button onclick="closePayModal()"
          style="flex:1;background:var(--input-bg);border:1px solid var(--border-dim);color:var(--text-dim);border-radius:3px;font-family:var(--font-sans);font-size:13px;font-weight:500;padding:11px;cursor:pointer;text-transform:uppercase;">
          Cancel
        </button>
      </div>
    </div>

    <!-- Processing step -->
    <div id="payStep2" style="display:none;text-align:center;padding:1.5rem 0;">
      <div style="width:48px;height:48px;border:3px solid var(--border-dim);border-top-color:var(--green);border-radius:50%;margin:0 auto 1rem;animation:spin 0.8s linear infinite;"></div>
      <div style="font-size:14px;font-weight:600;margin-bottom:4px;">Processing Payment</div>
      <div style="font-size:12px;color:var(--text-dim);">Please do not close this window…</div>
    </div>

    <!-- Success step -->
    <div id="payStep3" style="display:none;text-align:center;padding:1rem 0;">
      <div style="width:56px;height:56px;background:var(--green-bg);border:1px solid rgba(34,197,94,0.25);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1.2rem;">
        <svg style="width:28px;height:28px;fill:var(--green);" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
      </div>
      <div style="font-size:18px;font-weight:600;margin-bottom:4px;">Payment Successful!</div>
      <div style="font-size:13px;color:var(--text-dim);margin-bottom:1.4rem;" id="paySuccessDesc"></div>
      <button onclick="closePayModal()" style="background:var(--accent);color:white;border:none;border-radius:3px;font-family:var(--font-sans);font-size:13px;font-weight:600;text-transform:uppercase;padding:10px 24px;cursor:pointer;">Done</button>
    </div>

  </div>
</div>

<script>
let currentInvoiceId   = null;
let currentPayMethod   = null;

function openPayModal(invoiceId, invoiceCode, amount) {
  currentInvoiceId = invoiceId;
  document.getElementById('payModalDesc').textContent =
    'Invoice ' + invoiceCode + ' · Total ₹' + amount;
  document.getElementById('payAmountBtn').textContent = amount;
  document.getElementById('payStep1').style.display   = 'block';
  document.getElementById('payStep2').style.display   = 'none';
  document.getElementById('payStep3').style.display   = 'none';
  document.getElementById('payModalAlert').style.display = 'none';
  // reset
  document.querySelectorAll('input[name="pay_method"]').forEach(r => r.checked = false);
  ['upi_fields','card_fields','netbanking_fields'].forEach(id => document.getElementById(id).style.display = 'none');
  ['method_upi_label','method_card_label','method_netbanking_label'].forEach(id =>
    document.getElementById(id).style.borderColor = '');
  currentPayMethod = null;
  document.getElementById('payModal').classList.add('open');
}

function closePayModal() {
  document.getElementById('payModal').classList.remove('open');
  if (document.getElementById('payStep3').style.display === 'block') {
    location.reload();
  }
}

function selectMethod(method) {
  currentPayMethod = method;
  ['upi_fields','card_fields','netbanking_fields'].forEach(id =>
    document.getElementById(id).style.display = 'none');
  ['method_upi_label','method_card_label','method_netbanking_label'].forEach(id =>
    document.getElementById(id).style.borderColor = '');
  document.getElementById(method + '_fields').style.display = 'block';
  document.getElementById('method_' + method + '_label').style.borderColor = 'var(--accent-bright)';
  document.getElementById('payModalAlert').style.display = 'none';
}

function formatCard(input) {
  let v = input.value.replace(/\D/g, '').slice(0, 16);
  input.value = v.replace(/(.{4})/g, '$1 ').trim();
}

function formatExpiry(input) {
  let v = input.value.replace(/\D/g, '').slice(0, 4);
  if (v.length >= 3) v = v.slice(0,2) + '/' + v.slice(2);
  input.value = v;
}

function showPayAlert(msg) {
  const el = document.getElementById('payModalAlert');
  el.textContent     = msg;
  el.style.display   = 'block';
}

function processPayment() {
  document.getElementById('payModalAlert').style.display = 'none';

  if (!currentPayMethod) {
    showPayAlert('Please select a payment method.');
    return;
  }

  // Basic validation
  if (currentPayMethod === 'upi') {
    const upi = document.getElementById('upi_id').value.trim();
    if (!upi || !upi.includes('@')) { showPayAlert('Please enter a valid UPI ID.'); return; }
  } else if (currentPayMethod === 'card') {
    const num  = document.getElementById('card_number').value.replace(/\s/g,'');
    const exp  = document.getElementById('card_expiry').value.trim();
    const cvv  = document.getElementById('card_cvv').value.trim();
    const name = document.getElementById('card_name').value.trim();
    if (num.length < 16)  { showPayAlert('Please enter a valid 16-digit card number.'); return; }
    if (exp.length < 5)   { showPayAlert('Please enter a valid expiry date.'); return; }
    if (cvv.length < 3)   { showPayAlert('Please enter a valid CVV.'); return; }
    if (!name)            { showPayAlert('Please enter the name on card.'); return; }
  } else if (currentPayMethod === 'netbanking') {
    const bank = document.getElementById('bank_select').value;
    if (!bank) { showPayAlert('Please select a bank.'); return; }
  }

  // Show processing
  document.getElementById('payStep1').style.display = 'none';
  document.getElementById('payStep2').style.display = 'block';

  // Simulate processing delay then call server
  setTimeout(() => {
    const fd = new FormData();
    fd.append('csrf_token', document.querySelector('[name="csrf_token"]')?.value || '<?= generate_csrf_token() ?>');
    fd.append('action',     'pay_invoice');
    fd.append('invoice_id', currentInvoiceId);
    fd.append('pay_method', currentPayMethod);

    fetch('delivery-billing', {
      method: 'POST',
      body: fd
    })
    .then(r => r.json())
    .then(data => {
      document.getElementById('payStep2').style.display = 'none';
      if (data.success) {
        document.getElementById('payStep3').style.display    = 'block';
        document.getElementById('paySuccessDesc').textContent =
          'Your payment via ' + data.method_label + ' has been recorded. Invoice is now marked as paid.';
      } else {
        document.getElementById('payStep1').style.display = 'block';
        showPayAlert(data.message || 'Payment failed. Please try again.');
      }
    })
    .catch(() => {
      document.getElementById('payStep2').style.display = 'none';
      document.getElementById('payStep1').style.display = 'block';
      showPayAlert('Network error. Please try again.');
    });
  }, 2000);
}
</script>

</body>
</html>
