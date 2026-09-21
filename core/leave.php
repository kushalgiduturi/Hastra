<?php
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'leave.php') { http_response_code(404); exit(); }
// Astra — leave quota enforcement.
//
// 'maternity' and 'unpaid' are unlimited (maternity is gated separately by
// users.maternity_leave_eligible; unpaid has no cap by definition) — both
// return null from astra_leave_balance().
//
// 'general' and 'sick' are capped by the company's leave policy
// (portals/admin/leave_management.php):
//   - monthly cycle: monthly_general_leaves / monthly_sick_leaves, reset each
//     calendar month, tracked separately per type.
//   - yearly_rollover cycle: annual_leave_allowance is a single pool shared
//     by general + sick for the calendar year (the schema only has one
//     yearly number, so both types draw from it).
//
// "Used" counts pending + approved requests (not rejected), so a pending
// request already reserves its days against the quota.

function astra_leave_balance($conn, int $user_id, array $company, string $leave_type) {
    if (!in_array($leave_type, ['general', 'sick'], true)) {
        return null; // maternity, unpaid: unlimited
    }

    $cycle = $company['leave_cycle'] ?? 'monthly';

    if ($cycle === 'yearly_rollover') {
        $limit = (int)($company['annual_leave_allowance'] ?? 0);
        $stmt = mysqli_prepare($conn,
            "SELECT COALESCE(SUM(total_days), 0) FROM leave_requests
             WHERE user_id = ? AND leave_type IN ('general','sick') AND status IN ('pending','approved')
               AND YEAR(start_date) = YEAR(CURDATE())"
        );
        mysqli_stmt_bind_param($stmt, "i", $user_id);
    } else {
        $limit = (int)($leave_type === 'sick' ? ($company['monthly_sick_leaves'] ?? 0) : ($company['monthly_general_leaves'] ?? 0));
        $stmt = mysqli_prepare($conn,
            "SELECT COALESCE(SUM(total_days), 0) FROM leave_requests
             WHERE user_id = ? AND leave_type = ? AND status IN ('pending','approved')
               AND YEAR(start_date) = YEAR(CURDATE()) AND MONTH(start_date) = MONTH(CURDATE())"
        );
        mysqli_stmt_bind_param($stmt, "is", $user_id, $leave_type);
    }

    mysqli_stmt_execute($stmt);
    $used = (float)mysqli_fetch_row(mysqli_stmt_get_result($stmt))[0];

    return ['limit' => $limit, 'used' => $used, 'remaining' => max(0, $limit - $used)];
}
