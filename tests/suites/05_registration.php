<?php
// Registration: auth/register.php -> emailed code -> auth/verify_register.php
T::group('Registration');

const REG_TRACKS = [
    // flow               => expected companies.account_type, role
    'client_company'    => ['client_org',        'client'],
    'client_individual' => ['client_individual', 'client'],
    'enterprise_full'   => ['full_org',          'admin'],
    'enterprise_solo'   => ['solo_enterprise',   'admin'],
];

foreach (REG_TRACKS as $flow => [$account_type, $role]) {
    t("track $flow: pending row, emailed code, verified account", function () use ($flow, $account_type, $role) {
        $email = "reg.$flow." . F::$suffix . '@example.test';
        $org   = "Audit $flow " . F::$suffix;
        $s = astra_test_session([]);
        mail_clear();
        $r = cgi('POST', 'auth/register.php', ['session' => $s, 'post' => [
            'csrf_token' => csrf_of($s), 'accept_terms' => '1', 'flow' =>$flow, 'name' => "Reg $flow", 'email' => $email,
            'phone_number' => '+919876543' . random_int(100, 999), 'password' => FIX_PASSWORD, 'confirm' => FIX_PASSWORD,
            'country' => 'India', 'company_name' => $org, 'company_size' => '11-50',
            'leave_cycle' => 'monthly', 'monthly_general_leaves' => 2, 'monthly_sick_leaves' => 1, 'annual_leave_allowance' => 18]]);
        expect_clean($r, 'register');
        expect(str_contains((string)$r['location'], 'verify-email'), 'no redirect to verification: ' . substr(trim(visible_text($r['body'])), 0, 200));

        $pending = q1("SELECT * FROM pending_registrations WHERE email = ? OR email_bindex = ?", [$email, astra_blind_index($email)]);
        expect($pending !== null, 'no pending_registrations row');
        expect(!str_contains(implode('|', array_map('strval', $pending)), $email), 'pending row stores the email in plaintext');
        expect(!str_contains((string)$pending['phone_number'], '+91'), 'pending row stores the phone in plaintext');
        expect(password_get_info($pending['password'])['algo'] !== null, 'password not hashed');

        $code = mail_code_to($email);
        expect($code !== null, 'no verification email');
        $v = cgi('POST', 'auth/verify_register.php', ['session' => $s, 'post' => ['csrf_token' => csrf_of($s), 'action' => 'verify_otp', 'otp' => $code]]);
        expect_clean($v, 'verify_register');

        $u = q1("SELECT * FROM users WHERE email_bindex = ?", [astra_blind_index($email)]);
        expect($u !== null, 'no users row after verification');
        expect(str_starts_with($u['email'], 'hastra:v1:') && str_starts_with($u['name'], 'hastra:v1:') && str_starts_with((string)$u['phone_number'], 'hastra:v1:'),
               'users PII not encrypted');
        expect_eq(astra_db_decrypt($u['email']), $email, 'decrypted email');
        expect_eq($u['role'], $role, 'role');
        expect($u['phone_bindex'] !== null && strlen($u['phone_bindex']) === 64, 'no phone blind index');

        $c = q1("SELECT * FROM companies WHERE id = ?", [$u['company_id']]);
        expect($c !== null, 'no company linked');
        expect_eq($c['account_type'], $account_type, 'companies.account_type');
        expect(str_starts_with((string)$c['email_domain'], 'hastra:v1:'), 'company domain not encrypted');
        expect($c['domain_bindex'] !== null, 'no domain blind index');
        expect(!q1("SELECT 1 FROM pending_registrations WHERE id = ?", [$pending['id']]), 'pending row not removed');
        if ($flow === 'enterprise_full') expect(!empty($c['attendance_webhook_secret']), 'no attendance webhook secret generated');
        return "account_type=$account_type role=$role";
    });
}

t('duplicate email is refused', function () {
    $s = astra_test_session([]);
    $r = cgi('POST', 'auth/register.php', ['session' => $s, 'post' => [
        'csrf_token' => csrf_of($s), 'accept_terms' => '1', 'flow' =>'client_individual', 'name' => 'Dup', 'email' => F::$u['admin']['email'],
        'phone_number' => '+919876500001', 'password' => FIX_PASSWORD, 'confirm' => FIX_PASSWORD, 'country' => 'India']]);
    expect_clean($r);
    expect(!str_contains((string)$r['location'], 'verify-email'), 'duplicate accepted');
});
t('country must come from the list: typed or tampered values are refused', function () {
    require_once ASTRA_ROOT . '/core/countries.php';
    foreach (['ardtfjytyk', '', 'india', '<script>'] as $bad) {
        $s = astra_test_session([]);
        $r = cgi('POST', 'auth/register.php', ['session' => $s, 'post' => [
            'csrf_token' => csrf_of($s), 'accept_terms' => '1', 'flow' => 'client_individual', 'name' => 'Ctry', 'email' => 'ctry.' . F::$suffix . '@example.test',
            'phone_number' => '+919876500003', 'password' => FIX_PASSWORD, 'confirm' => FIX_PASSWORD, 'country' => $bad]]);
        expect_clean($r);
        expect(!str_contains((string)$r['location'], 'verify-email') && str_contains($r['body'], 'Choose your country'), "country '$bad' accepted");
    }
    expect(astra_country_valid('India') && astra_country_valid('United States') && !astra_country_valid('Atlantis'), 'country list drifted');
});
t('a user can be created under strict SQL mode (as on the production MySQL)', function () {
    $conn = $GLOBALS['conn'];
    $old = mysqli_fetch_row(mysqli_query($conn, "SELECT @@SESSION.sql_mode"))[0];
    mysqli_query($conn, "SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE'");
    $email = 'strict.' . F::$suffix . '@example.test';
    $id = null; $err = null;
    try {
        $id = insert_user_in_company($conn, null, ['name' => 'Strict Mode', 'email' => $email, 'phone_number' => null,
            'password' => password_hash('x', PASSWORD_DEFAULT), 'role' => 'client'], $err);
    } finally {
        mysqli_query($conn, "SET SESSION sql_mode = '" . mysqli_real_escape_string($conn, $old) . "'");
    }
    expect($id !== null, 'the insert failed under strict mode: ' . ($err ?? 'no error'));
    if ($id) mysqli_query($conn, "DELETE FROM users WHERE id = " . (int)$id);
});
t('weak password is refused with a specific message', function () {
    $s = astra_test_session([]);
    $r = cgi('POST', 'auth/register.php', ['session' => $s, 'post' => [
        'csrf_token' => csrf_of($s), 'accept_terms' => '1', 'flow' =>'client_individual', 'name' => 'Weak', 'email' => 'weak.' . F::$suffix . '@example.test',
        'phone_number' => '+919876500002', 'password' => 'alllowercase1!', 'confirm' => 'alllowercase1!', 'country' => 'India']]);
    expect_clean($r);
    expect(str_contains($r['body'], 'uppercase'), 'no uppercase rule message');
});
