<?php
// tests/lib/fixtures.php
// Known accounts inside the throwaway test database only. The real database
// is never touched: the harness refuses any DB_NAME that doesn't end in _test.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(); }

const FIX_PASSWORD = 'Test#Passw0rd!2026';

final class F {
    public static array $u = [];     // role key => ['id','email','name','role']
    public static int $internal_company;
    public static int $project;      // a project whose client is F::$u['client']
    public static string $suffix;
}

function fix_make_user(string $key, string $role, ?int $company_id, array $extra = []): array {
    $email = "hastra.test.$key." . F::$suffix . '@example.test';
    $name  = 'Test ' . ucfirst($key);
    $gender = array_key_exists('gender', $extra) ? $extra['gender'] : 'male';
    q("INSERT INTO users (name, email, email_bindex, password, reset_token, token_expiry, role, company_id, client_role,
                          gender, profile_updated, login_attempts)
       VALUES (?, ?, ?, ?, '', '', ?, ?, ?, ?, ?, 0)", [
        astra_db_encrypt($name), astra_db_encrypt($email), astra_blind_index($email),
        password_hash(FIX_PASSWORD, PASSWORD_DEFAULT), $role, $company_id, $extra['client_role'] ?? null,
        $gender === null ? null : astra_db_encrypt($gender), $gender === null ? 0 : 1,
    ]);
    $id = (int)mysqli_insert_id($GLOBALS['conn']);
    return F::$u[$key] = ['id' => $id, 'email' => $email, 'name' => $name, 'role' => $role];
}

// Re-keys an existing cloned account with a known email/password so tests
// can sign in as it (only inside the clone).
function fix_adopt_user(string $key, int $id): array {
    $row = q1("SELECT role FROM users WHERE id = ?", [$id]);
    if (!$row) throw new RuntimeException("fixture: user $id missing from clone");
    $email = "hastra.test.$key." . F::$suffix . '@example.test';
    $name  = 'Test ' . ucfirst($key);
    q("UPDATE users SET name = ?, email = ?, email_bindex = ?, password = ?, gender = ?, profile_updated = 1,
                        login_attempts = 0, locked_until = NULL
       WHERE id = ?", [astra_db_encrypt($name), astra_db_encrypt($email), astra_blind_index($email),
                       password_hash(FIX_PASSWORD, PASSWORD_DEFAULT), astra_db_encrypt('female'), $id]);
    return F::$u[$key] = ['id' => $id, 'email' => $email, 'name' => $name, 'role' => $row['role']];
}

function fix_setup(): void {
    F::$suffix = bin2hex(random_bytes(3));
    F::$internal_company = (int)qv("SELECT id FROM companies WHERE is_internal = 1 LIMIT 1");
    $p = q1("SELECT p.id, r.user_id AS client FROM projects p JOIN requirements r ON r.id = p.requirement_id
             JOIN users u ON u.id = r.user_id WHERE u.role = 'client' ORDER BY p.id DESC LIMIT 1");
    if (!$p) throw new RuntimeException('fixture: the clone has no client project to test with');
    F::$project = (int)$p['id'];

    fix_make_user('admin', 'admin', F::$internal_company);
    fix_make_user('sysadmin', 'sysadmin', F::$internal_company);
    fix_make_user('employee', 'employee', F::$internal_company);
    fix_make_user('nogender', 'employee', F::$internal_company, ['gender' => null]);
    fix_adopt_user('client', (int)$p['client']);
    q("UPDATE users SET client_role = 'pm' WHERE id = ?", [F::$u['client']['id']]);
    // An IT Manager in a client company (team management is theirs only).
    $itm = qv("SELECT u.id FROM users u JOIN companies c ON c.id = u.company_id
               WHERE u.role = 'client' AND u.client_role = 'it_manager' AND c.is_internal = 0 AND u.id <> ? LIMIT 1", [$p['client']]);
    if ($itm) fix_adopt_user('itmanager', (int)$itm);
    // The primary sysadmin keeps its real address (migrations are locked to
    // it); only its password changes, and only inside the clone.
    $prim = qv("SELECT id FROM users WHERE email_bindex = ?", [astra_blind_index(PRIMARY_SYSADMIN_EMAIL)]);
    if ($prim) {
        q("UPDATE users SET password = ?, login_attempts = 0, locked_until = NULL WHERE id = ?", [password_hash(FIX_PASSWORD, PASSWORD_DEFAULT), $prim]);
        $row = q1("SELECT role, name FROM users WHERE id = ?", [$prim]);
        F::$u['primary'] = ['id' => (int)$prim, 'email' => PRIMARY_SYSADMIN_EMAIL, 'name' => astra_db_decrypt($row['name']), 'role' => $row['role']];
    }
    q("INSERT INTO project_members (project_id, user_id, project_role, assigned_by) VALUES (?, ?, 'team_lead', ?)",
      [F::$project, F::$u['employee']['id'], F::$u['admin']['id']]);
}

// Signed-in session for a fixture account (fingerprinted for the default
// test client), ready to pass as cgi(..., ['session' => ...]).
function as_user(string $key, array $extra = []): string {
    $u = F::$u[$key];
    return astra_test_session(['user_id' => $u['id'], 'user_name' => $u['name'], 'user_role' => $u['role']] + $extra);
}
