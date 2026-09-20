<?php
// core/onboarding.php
// Enterprise onboarding: client roles, roster parsing, staging validation,
// activation invites. Loaded by core/db.php.

const CLIENT_ROLES = [
    'it_manager' => 'IT Manager',
    'pm'         => 'Project Manager',
    'teammate'   => 'Teammate',
];
const COMPANY_SIZES = [
    '1-10'     => '1–10 people',
    '11-50'    => '11–50 people',
    '51-200'   => '51–200 people',
    '201-1000' => '201–1,000 people',
    '1000+'    => 'More than 1,000 people',
];
const ROSTER_MAX_BYTES   = 2 * 1024 * 1024;
const ROSTER_MAX_ROWS    = 2000;
const INVITE_VALID_HOURS = 24;

function onboarding_schema_ready($conn) {
    static $ready = null;
    if ($ready === null) {
        $ready = company_schema_ready($conn)
              && db_column_exists($conn, 'users', 'client_role')
              && db_column_exists($conn, 'roster_staging', 'import_id');
    }
    return $ready;
}

function client_role_label($role) {
    return CLIENT_ROLES[$role] ?? 'Client';
}

// ── Mail ──────────────────────────────────────────────────────────────────────
function astra_send_mail($to, $subject, $body) {
    require_once __DIR__ . '/../PHPMailer/PHPMailer.php';
    require_once __DIR__ . '/../PHPMailer/SMTP.php';
    require_once __DIR__ . '/../PHPMailer/Exception.php';
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host        = MAIL_HOST;
    $mail->SMTPAuth    = MAIL_AUTH;
    $mail->Port        = MAIL_PORT;
    $mail->SMTPSecure  = MAIL_SECURE;
    $mail->SMTPAutoTLS = false;
    $mail->CharSet     = 'UTF-8';
    $mail->setFrom(MAIL_FROM, MAIL_NAME);
    $mail->addAddress($to);
    $mail->Subject = $subject;
    $mail->Body    = $body;
    $mail->send();
}

// ── Activation invites (reuse password_set_tokens) ───────────────────────────
// Returns the set-password link; $sent tells whether the email went out.
function send_activation_invite($conn, $user_id, $name, $email, $company_name, &$sent = null) {
    $old = mysqli_prepare($conn, "UPDATE password_set_tokens SET is_used = 1 WHERE user_id = ? AND is_used = 0");
    mysqli_stmt_bind_param($old, "i", $user_id);
    mysqli_stmt_execute($old);

    $token = bin2hex(random_bytes(32));
    $ins   = mysqli_prepare($conn, "INSERT INTO password_set_tokens (user_id, token) VALUES (?, ?)");
    mysqli_stmt_bind_param($ins, "is", $user_id, $token);
    mysqli_stmt_execute($ins);

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $link   = $scheme . "://" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . get_base_url() . "auth/set_password.php?token=" . $token;

    try {
        astra_send_mail($email, "You've been invited to Astra",
            "Hi $name,\n\n" .
            "$company_name has added you to Astra, where you can follow your projects with us.\n\n" .
            "Activate your account by choosing a password:\n$link\n\n" .
            "This link expires in " . INVITE_VALID_HOURS . " hours. If it expires, ask your IT Manager to resend it.\n\n" .
            "Regards,\nAstra Team");
        $sent = true;
    } catch (Throwable $e) {
        error_log("Activation email to $email failed: " . $e->getMessage());
        $sent = false;
    }
    return $link;
}

// active | pending | expired. Expiry is worked out by MySQL so PHP's time zone doesn't matter.
function activation_state($has_open_token, $open_token_expired) {
    if (!$has_open_token) return 'active';   // activated, or self-registered and never invited
    return $open_token_expired ? 'expired' : 'pending';
}

function company_members_with_activation($conn, $company_id) {
    $stmt = mysqli_prepare($conn,
        "SELECT u.id, u.name, u.email, u.phone_number, u.client_role,
                MAX(t.created_at)                                AS invited_at,
                MAX(CASE WHEN t.is_used = 0 THEN 1 ELSE 0 END)   AS has_open,
                MAX(CASE WHEN t.is_used = 0 AND t.created_at < NOW() - INTERVAL " . INVITE_VALID_HOURS . " HOUR
                         THEN 1 ELSE 0 END)                      AS open_expired
         FROM users u
         LEFT JOIN password_set_tokens t ON t.user_id = u.id
         WHERE u.company_id = ? AND u.role = 'client'
         GROUP BY u.id
         ORDER BY FIELD(u.client_role, 'it_manager', 'pm', 'teammate'), u.name");
    mysqli_stmt_bind_param($stmt, "i", $company_id);
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    foreach ($rows as &$r) {
        // Resending retires older links, so at most one link is open per person.
        $r['state']        = activation_state((bool)$r['has_open'], (bool)$r['open_expired']);
        $r['phone_number'] = astra_db_decrypt($r['phone_number']);
    }
    return $rows;
}

// ── Roster parsing ────────────────────────────────────────────────────────────
// Python parser first (tools/roster_parser), built-in PHP parser as a fallback.
function parse_roster_file($path, $ext) {
    $warnings = [];
    $py = run_python_roster_parser($path, $ext, $warnings);
    if ($py !== null) return $py;

    $res = php_parse_roster($path, $ext);
    $res['warnings'] = array_merge($warnings, $res['warnings'] ?? []);
    return $res;
}

function run_python_roster_parser($path, $ext, array &$warnings) {
    if (!defined('PYTHON_BIN') || PYTHON_BIN === '' || !function_exists('proc_open')) return null;
    $script = realpath(__DIR__ . '/../tools/roster_parser/parse_roster.py');
    if (!$script) return null;

    // The parser picks the format from the extension, so give it a copy with one.
    $copy = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'astra_roster_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!copy($path, $copy)) return null;

    $cmd  = escapeshellarg(PYTHON_BIN) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($copy);
    if (defined('ROSTER_USE_AI') && ROSTER_USE_AI) $cmd .= ' --llm';
    $proc = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) { @unlink($copy); return null; }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    proc_close($proc);
    @unlink($copy);

    $data = json_decode((string)$stdout, true);
    if (!is_array($data)) {
        if (trim($stderr) !== '') error_log("Roster parser: " . trim($stderr));
        return null;   // Python missing or crashed → PHP fallback
    }
    if (empty($data['ok']) && strpos((string)($data['error'] ?? ''), 'openpyxl') !== false) {
        $warnings[] = "Python is missing openpyxl, so the built-in reader was used.";
        return null;
    }
    return $data;
}

function php_parse_roster($path, $ext) {
    try {
        $rows = $ext === 'csv' ? php_read_csv($path) : php_read_xlsx($path);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => "Couldn't read the file: " . $e->getMessage()];
    }
    $numbered = [];
    foreach ($rows as $i => $r) {
        if (array_filter($r, fn($c) => trim((string)$c) !== '')) $numbered[] = [$i + 1, $r];
    }
    if (!$numbered) return ['ok' => false, 'error' => 'The file is empty.'];

    // Header = the first row (of the first 15) that maps the most fields.
    $best = -1; $h = 0; $map = [];
    foreach (array_slice($numbered, 0, 15) as $i => [, $r]) {
        $m = php_map_headers($r);
        $score = count($m) + (isset($m['email']) ? 2 : 0);
        if ($score > $best) { $best = $score; $h = $i; $map = $m; }
    }
    if (!isset($map['email'])) {
        return ['ok' => false, 'error' => 'Couldn\'t find an email column. Add a header such as "Email".'];
    }

    $warnings = [];
    if (!isset($map['name']) && !isset($map['first_name'])) $warnings[] = "No name column found — names will need to be filled in.";

    $out = [];
    foreach (array_slice($numbered, $h + 1) as [$line, $r]) {
        $cell  = fn($f) => isset($map[$f]) ? trim((string)($r[$map[$f]] ?? '')) : '';
        $name  = $cell('name') ?: trim($cell('first_name') . ' ' . $cell('last_name'));
        $email = strtolower($cell('email'));
        if ($name === '' && $email === '') continue;
        [$role, $role_conf] = normalize_client_role($cell('role'));
        $phone = preg_replace('/\.0$/', '', $cell('phone'));
        $out[] = [
            'row'        => $line,
            'name'       => mb_substr(preg_replace('/\s+/', ' ', $name), 0, 100),
            'email'      => mb_substr($email, 0, 100),
            'phone'      => substr(preg_replace('/[^\d+]/', '', $phone), 0, 15),
            'role'       => $role,
            'role_raw'   => mb_substr($cell('role'), 0, 60),
            'confidence' => min($role_conf, filter_var($email, FILTER_VALIDATE_EMAIL) ? 1.0 : 0.3, $name !== '' ? 1.0 : 0.4),
        ];
        if (count($out) >= ROSTER_MAX_ROWS) { $warnings[] = "Only the first " . ROSTER_MAX_ROWS . " rows were read."; break; }
    }
    return ['ok' => true, 'parser' => 'php', 'header_row' => $numbered[$h][0], 'rows' => $out, 'warnings' => $warnings];
}

function php_map_headers(array $header) {
    $syn = [
        'name'       => ['name', 'full name', 'employee name', 'staff name', 'member', 'employee'],
        'first_name' => ['first name', 'firstname', 'given name', 'forename'],
        'last_name'  => ['last name', 'lastname', 'surname', 'family name'],
        'email'      => ['email', 'e mail', 'email address', 'mail', 'work email', 'official email', 'email id'],
        'phone'      => ['phone', 'phone number', 'mobile', 'mobile number', 'contact', 'contact number', 'telephone'],
        'role'       => ['role', 'designation', 'title', 'job title', 'position', 'access level'],
    ];
    $map = [];
    foreach ($header as $idx => $cell) {
        $h = trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower((string)$cell)));
        if ($h === '') continue;
        foreach ($syn as $field => $words) {
            if (isset($map[$field])) continue;
            if (in_array($h, $words, true)) { $map[$field] = $idx; continue 2; }
        }
        foreach ($syn as $field => $words) {
            if (isset($map[$field])) continue;
            foreach ($words as $w) {
                if (strlen($w) > 3 && strpos($h, $w) !== false) { $map[$field] = $idx; continue 3; }
            }
        }
    }
    return $map;
}

function normalize_client_role($raw) {
    $r = trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower((string)$raw)));
    if ($r === '') return ['teammate', 0.6];
    $rules = [
        'it_manager' => ['it manager', 'it admin', 'it administrator', 'it head', 'sysadmin', 'system admin', 'it lead'],
        'pm'         => ['project manager', 'product manager', 'program manager', 'pm', 'delivery manager', 'project lead', 'scrum master'],
    ];
    foreach ($rules as $role => $words) {
        if (in_array($r, $words, true)) return [$role, 1.0];
        foreach ($words as $w) {
            if (preg_match('/\b' . preg_quote($w, '/') . '\b/', $r)) return [$role, 0.85];
        }
    }
    return ['teammate', 0.9];
}

function php_read_csv($path) {
    $fh = fopen($path, 'r');
    $first = fgets($fh);
    rewind($fh);
    $delims = [',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t")];
    arsort($delims);
    $delim = array_key_first($delims);
    $rows = [];
    while (($r = fgetcsv($fh, 0, $delim, '"', '\\')) !== false) {
        if ($rows === [] && isset($r[0])) $r[0] = preg_replace('/^\xEF\xBB\xBF/', '', $r[0]);
        $rows[] = $r;
        if (count($rows) > ROSTER_MAX_ROWS + 20) break;
    }
    fclose($fh);
    return $rows;
}

// Minimal .xlsx reader: first worksheet, shared + inline strings.
function php_read_xlsx($path) {
    if (!class_exists('ZipArchive')) throw new RuntimeException("PHP's zip extension is off — upload a CSV instead.");
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException("not a valid .xlsx file");

    $shared = [];
    if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
        $sst = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
        foreach ($sst->si as $si) {
            $text = (string)$si->t;
            if ($text === '' && isset($si->r)) foreach ($si->r as $run) $text .= (string)$run->t;
            $shared[] = $text;
        }
    }

    $sheet_path = 'xl/worksheets/sheet1.xml';
    if (($wb = $zip->getFromName('xl/workbook.xml')) !== false && ($rels = $zip->getFromName('xl/_rels/workbook.xml.rels')) !== false) {
        $wbx  = simplexml_load_string($wb, 'SimpleXMLElement', LIBXML_NONET);
        $relx = simplexml_load_string($rels, 'SimpleXMLElement', LIBXML_NONET);
        $first = $wbx->sheets->sheet[0] ?? null;
        if ($first) {
            $rid = (string)$first->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            foreach ($relx->Relationship as $rel) {
                if ((string)$rel['Id'] === $rid) {
                    $target = ltrim((string)$rel['Target'], '/');
                    $sheet_path = strpos($target, 'xl/') === 0 ? $target : 'xl/' . $target;
                }
            }
        }
    }
    $xml = $zip->getFromName($sheet_path);
    $zip->close();
    if ($xml === false) throw new RuntimeException("the first worksheet is missing");

    $sheet = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
    $rows = [];
    foreach ($sheet->sheetData->row as $row) {
        $r = [];
        foreach ($row->c as $c) {
            preg_match('/^([A-Z]+)/', (string)$c['r'], $m);
            $col = 0;
            foreach (str_split($m[1] ?? 'A') as $ch) $col = $col * 26 + (ord($ch) - 64);
            $type = (string)$c['t'];
            if ($type === 's')             $val = $shared[(int)$c->v] ?? '';
            elseif ($type === 'inlineStr') $val = (string)$c->is->t;
            else                           $val = (string)$c->v;
            $r[$col - 1] = $val;
        }
        if ($r) {
            $filled = array_fill(0, max(array_keys($r)) + 1, '');
            foreach ($r as $k => $v) $filled[$k] = $v;
            $r = $filled;
        }
        $line = (int)$row['r'];
        $rows[$line > 0 ? $line - 1 : count($rows)] = $r;
        if (count($rows) > ROSTER_MAX_ROWS + 20) break;
    }
    if (!$rows) return [];
    $dense = array_fill(0, max(array_keys($rows)) + 1, []);
    foreach ($rows as $k => $v) $dense[$k] = $v;
    return $dense;
}

// ── Staging validation ────────────────────────────────────────────────────────
// Adds 'problems' (blocking) and 'notes' (warnings) to each staging row.
function validate_staging_rows($conn, array $rows, array $company) {
    $domain = company_email_domain($company);
    $seen   = [];
    $check  = mysqli_prepare($conn, "SELECT id FROM users WHERE email = ?");
    foreach ($rows as &$r) {
        $r['problems'] = [];
        $r['notes']    = [];
        if ($r['status'] !== 'pending') continue;
        $email = strtolower(trim($r['email']));
        if (trim($r['full_name']) === '')                  $r['problems'][] = 'Name is missing';
        if ($email === '')                                 $r['problems'][] = 'Email is missing';
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $r['problems'][] = 'Email looks invalid';
        else {
            mysqli_stmt_bind_param($check, "s", $email);
            mysqli_stmt_execute($check);
            mysqli_stmt_store_result($check);
            if (mysqli_stmt_num_rows($check) > 0) $r['problems'][] = 'Already has an Astra account';
            if (isset($seen[$email]))            $r['problems'][] = 'Duplicate of row ' . $seen[$email];
            $seen[$email] = $r['source_row'] ?: '#' . $r['id'];
            if (!email_matches_domain($email, $domain)) $r['notes'][] = "Not a $domain address";
        }
        if ($r['phone_number'] !== '' && !preg_match('/^\+?[0-9]{7,15}$/', $r['phone_number'])) {
            $r['problems'][] = 'Phone number looks invalid';
        }
        if (!isset(CLIENT_ROLES[$r['client_role']])) $r['problems'][] = 'Unknown role';
        if ($r['confidence'] !== null && (float)$r['confidence'] < 0.8 && !$r['problems']) {
            $r['notes'][] = 'Check this row — the parser was unsure';
        }
    }
    return $rows;
}
