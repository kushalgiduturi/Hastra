<?php
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) { http_response_code(404); exit(); }
// Hastra — Google OAuth 2.0 / OpenID Connect sign-in.
//
// Authorization-code flow with PKCE (S256), a per-attempt `state` (CSRF) and
// `nonce` (replay), sealed in an encrypted cookie (see GOOGLE_ATTEMPT_COOKIE). The ID token returned by
// the token endpoint is then checked by Google's tokeninfo endpoint, which
// validates the signature, and its claims are re-checked here: audience,
// issuer, expiry, nonce and email_verified. Only then is anything trusted.
//
// Google's stable account id ("sub") is stored only as an HMAC blind index
// (users.google_id_bindex), the same scheme as email_bindex.

const GOOGLE_AUTH_ENDPOINT      = 'https://accounts.google.com/o/oauth2/v2/auth';
const GOOGLE_TOKEN_ENDPOINT     = 'https://oauth2.googleapis.com/token';
const GOOGLE_TOKENINFO_ENDPOINT = 'https://oauth2.googleapis.com/tokeninfo';
const GOOGLE_OAUTH_MAX_AGE      = 600; // a sign-in attempt must finish within 10 minutes

function astra_google_secret(): ?string {
    $env = getenv('ASTRA_GOOGLE_CLIENT_SECRET');
    if (is_string($env) && $env !== '') return $env;
    if (!is_file(GOOGLE_CLIENT_SECRET_FILE)) return null;
    $s = trim((string) file_get_contents(GOOGLE_CLIENT_SECRET_FILE));
    return $s === '' ? null : $s;
}

function astra_google_configured(): bool {
    return GOOGLE_CLIENT_ID !== '' && astra_google_secret() !== null;
}

function astra_google_schema_ready($conn): bool {
    static $ready = null;
    if ($ready === null) $ready = db_column_exists($conn, 'users', 'google_id_bindex');
    return $ready;
}

// The in-flight attempt can't live in the session: the session cookie is
// SameSite=Strict, so the browser withholds it on Google's cross-site redirect
// back. It rides in its own SameSite=Lax cookie instead, sealed with
// AES-256-GCM (confidential and tamper-evident), scoped to /auth/, 10 minutes.
const GOOGLE_ATTEMPT_COOKIE = 'hastra_goauth';

function astra_google_attempt_cookie(string $value, int $expires): void {
    setcookie(GOOGLE_ATTEMPT_COOKIE, $value, [
        'expires'  => $expires,
        'path'     => get_base_url() . 'auth/',
        'secure'   => astra_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// Starts an attempt and returns the URL to send the browser to. $action is
// 'login' or 'register'; $context carries validated registration details.
function astra_google_begin(string $action, array $context = []): string {
    $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    $state    = bin2hex(random_bytes(32));
    $nonce    = bin2hex(random_bytes(32));
    $attempt  = [
        'state' => $state, 'nonce' => $nonce, 'verifier' => $verifier,
        'action' => $action, 'context' => $context, 'started' => time(),
    ];
    astra_google_attempt_cookie(astra_encrypt(json_encode($attempt)), time() + GOOGLE_OAUTH_MAX_AGE);
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    return GOOGLE_AUTH_ENDPOINT . '?' . http_build_query([
        'client_id'             => GOOGLE_CLIENT_ID,
        'redirect_uri'          => GOOGLE_REDIRECT_URI,
        'response_type'         => 'code',
        'scope'                 => 'openid email profile',
        'state'                 => $state,
        'nonce'                 => $nonce,
        'code_challenge'        => $challenge,
        'code_challenge_method' => 'S256',
        'prompt'                => 'select_account',
    ]);
}

// Consumes the pending attempt (single use, whatever the outcome) and checks
// the returned state against it. Returns the attempt or null.
function astra_google_take_attempt(string $state): ?array {
    $sealed = (string) ($_COOKIE[GOOGLE_ATTEMPT_COOKIE] ?? '');
    astra_google_attempt_cookie('', time() - 3600);
    $a = astra_is_encrypted($sealed) ? json_decode((string) astra_decrypt($sealed), true) : null;
    if (!is_array($a) || $state === '' || !hash_equals((string) ($a['state'] ?? ''), $state)) return null;
    if (time() - (int) $a['started'] > GOOGLE_OAUTH_MAX_AGE) return null;
    return $a;
}

function astra_google_http(string $url, ?array $post = null): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code !== 200) {
        error_log("[hastra-google] HTTP $code from " . parse_url($url, PHP_URL_HOST));
        return null;
    }
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

// Exchanges the code and returns the verified profile:
// ['google_id', 'email', 'name', 'picture'], or null on any failure.
function astra_google_complete(array $attempt, string $code): ?array {
    $secret = astra_google_secret();
    if ($secret === null || $code === '') return null;

    $tok = astra_google_http(GOOGLE_TOKEN_ENDPOINT, [
        'code'          => $code,
        'client_id'     => GOOGLE_CLIENT_ID,
        'client_secret' => $secret,
        'redirect_uri'  => GOOGLE_REDIRECT_URI,
        'grant_type'    => 'authorization_code',
        'code_verifier' => $attempt['verifier'],
    ]);
    $id_token = $tok['id_token'] ?? '';
    if (!is_string($id_token) || $id_token === '') return null;

    $c = astra_google_http(GOOGLE_TOKENINFO_ENDPOINT . '?' . http_build_query(['id_token' => $id_token]));
    if (!$c) return null;

    $ok = hash_equals(GOOGLE_CLIENT_ID, (string) ($c['aud'] ?? ''))
       && in_array($c['iss'] ?? '', ['accounts.google.com', 'https://accounts.google.com'], true)
       && (int) ($c['exp'] ?? 0) > time()
       && hash_equals($attempt['nonce'], (string) ($c['nonce'] ?? ''))
       && in_array($c['email_verified'] ?? '', ['true', true], true)
       && ($c['sub'] ?? '') !== ''
       && filter_var($c['email'] ?? '', FILTER_VALIDATE_EMAIL);
    if (!$ok) {
        error_log('[hastra-google] ID token rejected: claim check failed');
        return null;
    }
    return [
        'google_id' => (string) $c['sub'],
        'email'     => strtolower(trim((string) $c['email'])),
        'name'      => trim((string) ($c['name'] ?? '')),
        'picture'   => (string) ($c['picture'] ?? ''),
    ];
}

function astra_role_home(string $role): string {
    $map = [
        'sysadmin' => 'workspace/sysadmin/',
        'admin'    => 'workspace/admin/',
        'employee' => 'workspace/employee/',
        'client'   => 'workspace/client/',
    ];
    return get_base_url() . ($map[$role] ?? 'workspace/user/newuser-portal');
}

// Finds the Hastra account for a verified Google profile: by the bound Google
// id first, then by email. Returns [user_row|null, error_code|null].
function astra_google_find_user($conn, array $profile): array {
    $gid = astra_blind_index($profile['google_id']);
    $s = mysqli_prepare($conn, "SELECT * FROM users WHERE google_id_bindex = ?");
    mysqli_stmt_bind_param($s, "s", $gid);
    mysqli_stmt_execute($s);
    $user = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    if ($user) { astra_decrypt_user_row($user); return [$user, null]; }

    $eb = astra_blind_index($profile['email']);
    $s = mysqli_prepare($conn, "SELECT * FROM users WHERE email_bindex = ?");
    mysqli_stmt_bind_param($s, "s", $eb);
    mysqli_stmt_execute($s);
    $user = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    if (!$user) return [null, null];
    astra_decrypt_user_row($user);
    // Already bound to a different Google account: never silently re-bind.
    if (!empty($user['google_id_bindex']) && !hash_equals($user['google_id_bindex'], $gid)) {
        return [null, 'google_mismatch'];
    }
    if (empty($user['google_id_bindex'])) {
        $b = mysqli_prepare($conn, "UPDATE users SET google_id_bindex = ? WHERE id = ? AND google_id_bindex IS NULL");
        mysqli_stmt_bind_param($b, "si", $gid, $user['id']);
        mysqli_stmt_execute($b);
        log_activity($conn, $user['id'], 'google_account_linked', $user['name']);
    }
    return [$user, null];
}

// The same session set-up auth/otp.php performs after password + OTP.
function astra_google_start_session($conn, array $user): void {
    $_SESSION['user_id']   = $user['id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_role'] = $user['role'];
    session_regenerate_id(true);
    astra_session_anchor($conn, (int) $user['id']);
    log_activity($conn, $user['id'], 'login_success_google', $user['name']);

    $ip  = astra_get_client_ip();
    $geo = astra_inspect_ip($ip, $conn);
    $_SESSION['login_ip']      = $ip;
    $_SESSION['login_city']    = $geo['city'];
    $_SESSION['login_country'] = $geo['country'];
}

// Records affirmative acceptance of the current Terms / Privacy Policy.
function astra_record_terms_acceptance($conn, int $user_id): void {
    if (!db_column_exists($conn, 'users', 'terms_accepted_at')) return;
    $v = LEGAL_TERMS_VERSION;
    $s = mysqli_prepare($conn, "UPDATE users SET terms_accepted_at = NOW(), terms_version = ? WHERE id = ?");
    mysqli_stmt_bind_param($s, "si", $v, $user_id);
    mysqli_stmt_execute($s);
}

// Finishes the callback with a same-site hop. The callback request arrived
// through a cross-site redirect chain, and browsers keep treating that chain
// as cross-site, so a 302 straight to a portal would arrive without the new
// SameSite=Strict session cookie. A tiny page that navigates itself is a new,
// same-site navigation, so the cookie is sent.
function astra_same_site_redirect(string $url): void {
    $u = htmlspecialchars($url, ENT_QUOTES);
    header("Cache-Control: no-store");
    echo "<!DOCTYPE html><html lang=\"en\"><head><meta charset=\"UTF-8\"><meta http-equiv=\"refresh\" content=\"0;url=$u\">"
       . "<title>Signing you in · Hastra</title></head><body><p><a href=\"$u\">Continue to Hastra</a></p></body></html>";
    exit();
}
// Creates the company and account for a verified Google identity signing up
// under a track (email already verified by Google, so no OTP round trip).
// Mirrors auth/verify_register.php. $ctx is the validated registration
// context from astra_google_begin(). Returns ['id','name','role'] or ['error'].
function astra_google_provision($conn, array $profile, array $ctx): array {
    $flow = $ctx['flow'] ?? '';
    $types = ['client_company' => 'client_org', 'client_individual' => 'client_individual',
              'enterprise_full' => 'full_org', 'enterprise_solo' => 'solo_enterprise'];
    if (!isset($types[$flow])) return ['error' => 'bad_track'];
    $role = in_array($flow, ['enterprise_full', 'enterprise_solo'], true) ? 'admin' : 'client';
    $name = $profile['name'] !== '' ? mb_substr($profile['name'], 0, 100) : strstr($profile['email'], '@', true);
    $company_name = ($ctx['company_name'] ?? '') !== '' ? $ctx['company_name'] : "$name (Individual)";

    $company = null;
    if (company_schema_ready($conn)) {
        if (find_company_by_name($conn, $company_name)) return ['error' => 'company_taken'];
        $extra = [
            'size_band'    => ($ctx['company_size'] ?? '') !== '' ? $ctx['company_size'] : null,
            'contract_ref' => ($ctx['contract_ref'] ?? '') !== '' ? $ctx['contract_ref'] : null,
            'account_type' => $types[$flow],
        ];
        if ($flow === 'enterprise_full') {
            $extra['leave_policy'] = [
                'leave_cycle'            => $ctx['leave_cycle'] ?? 'monthly',
                'monthly_general_leaves' => (int) ($ctx['monthly_general_leaves'] ?? 1),
                'monthly_sick_leaves'    => (int) ($ctx['monthly_sick_leaves'] ?? 1),
                'annual_leave_allowance' => (int) ($ctx['annual_leave_allowance'] ?? 18),
            ];
            $extra['generate_webhook_secret'] = true;
        }
        $company = create_company($conn, $company_name, null, $extra);
    }

    $new_user = [
        'name'         => $name,
        'email'        => $profile['email'],
        'phone_number' => null,
        // No usable password: the account signs in with Google until the
        // user sets one through "Forgot password".
        'password'     => password_hash(bin2hex(random_bytes(32)), PASSWORD_ARGON2ID),
        'role'         => $role,
    ];
    if (onboarding_schema_ready($conn) && $role === 'client') {
        $new_user['client_role'] = $company ? 'it_manager' : 'pm';
    }
    $new_id = insert_user_in_company($conn, $company, $new_user);
    if (!$new_id) return ['error' => 'create_failed'];

    if ($company) {
        $o = mysqli_prepare($conn, "UPDATE companies SET user_id = ? WHERE id = ?");
        mysqli_stmt_bind_param($o, "ii", $new_id, $company['id']);
        mysqli_stmt_execute($o);
        if (onboarding_schema_ready($conn) && $role === 'client') {
            $o = mysqli_prepare($conn, "UPDATE companies SET it_manager_id = ? WHERE id = ?");
            mysqli_stmt_bind_param($o, "ii", $new_id, $company['id']);
            mysqli_stmt_execute($o);
        }
    }
    $gid = astra_blind_index($profile['google_id']);
    $b = mysqli_prepare($conn, "UPDATE users SET google_id_bindex = ? WHERE id = ?");
    mysqli_stmt_bind_param($b, "si", $gid, $new_id);
    mysqli_stmt_execute($b);
    astra_record_terms_acceptance($conn, (int) $new_id);
    log_activity($conn, $new_id, 'register_google', $name);
    return ['id' => (int) $new_id, 'name' => $name, 'role' => $role];
}
