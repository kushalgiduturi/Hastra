<?php
// Astra — IP reputation / anti-VPN engine.
//
// astra_inspect_ip($ip) is the single entry point: it checks the ip_cache
// table for a result less than 24h old, and if there isn't one, queries an
// IP reputation provider, flags it, and caches the result. Callers should
// not talk to ip_cache or the provider directly.

require_once __DIR__ . '/network.php';

const IP_CACHE_TTL_SECONDS = 86400; // 24 hours

// Creates ip_cache on first use so this feature doesn't need a separate
// manual migration step before it works.
function astra_ensure_ip_cache_table($conn) {
    static $checked = false;
    if ($checked) return;
    $exists = mysqli_fetch_row(mysqli_query($conn,
        "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ip_cache'"));
    if (!$exists) {
        mysqli_query($conn, "CREATE TABLE ip_cache (
            ip VARCHAR(45) NOT NULL PRIMARY KEY,
            country VARCHAR(64) NULL,
            city VARCHAR(64) NULL,
            is_vpn TINYINT(1) NOT NULL DEFAULT 0,
            isp VARCHAR(128) NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    $checked = true;
}

function astra_ip_cache_lookup($conn, $ip) {
    $stmt = mysqli_prepare($conn,
        "SELECT ip, country, city, is_vpn, isp, updated_at FROM ip_cache
         WHERE ip = ? AND updated_at > (NOW() - INTERVAL " . IP_CACHE_TTL_SECONDS . " SECOND)");
    mysqli_stmt_bind_param($stmt, "s", $ip);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
}

function astra_ip_cache_store($conn, $ip, $country, $city, $is_vpn, $isp) {
    $stmt = mysqli_prepare($conn,
        "INSERT INTO ip_cache (ip, country, city, is_vpn, isp, updated_at) VALUES (?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE country = VALUES(country), city = VALUES(city),
                                  is_vpn = VALUES(is_vpn), isp = VALUES(isp), updated_at = NOW()");
    $is_vpn_int = $is_vpn ? 1 : 0;
    mysqli_stmt_bind_param($stmt, "sssis", $ip, $country, $city, $is_vpn_int, $isp);
    mysqli_stmt_execute($stmt);
}

// Datacenter/hosting ASNs commonly rented out as commodity VPN exit nodes.
// Belt-and-suspenders on top of the provider's own proxy/hosting flags —
// catches cases where a provider under-reports but the ASN is a known
// hosting company rather than a residential/mobile ISP.
const ASTRA_DATACENTER_ASN_KEYWORDS = [
    'digitalocean', 'amazon', 'aws', 'google cloud', 'microsoft azure',
    'ovh', 'hetzner', 'linode', 'vultr', 'choopa', 'm247', 'leaseweb',
    'nordvpn', 'expressvpn', 'surfshark', 'private internet access',
];

// Queries ip-api.com's free JSON endpoint (no key required) for geo +
// proxy/hosting flags. Returns null on any network/parse failure so the
// caller can fail open (never block a login just because the reputation
// provider is unreachable).
function astra_query_ip_provider($ip) {
    $url = "http://ip-api.com/json/" . rawurlencode($ip)
         . "?fields=status,message,country,city,isp,as,proxy,hosting,query";
    $context = stream_context_create(['http' => ['timeout' => 3]]);
    $response = @file_get_contents($url, false, $context);
    if (!$response) return null;
    $data = json_decode($response, true);
    if (!$data || ($data['status'] ?? '') !== 'success') return null;

    $asn_line   = strtolower($data['as'] ?? '');
    $isp_line   = strtolower($data['isp'] ?? '');
    $known_dc   = false;
    foreach (ASTRA_DATACENTER_ASN_KEYWORDS as $kw) {
        if (strpos($asn_line, $kw) !== false || strpos($isp_line, $kw) !== false) { $known_dc = true; break; }
    }

    return [
        'country' => $data['country'] ?? null,
        'city'    => $data['city'] ?? null,
        'isp'     => $data['isp'] ?? null,
        'is_vpn'  => (bool)($data['proxy'] ?? false) || (bool)($data['hosting'] ?? false) || $known_dc,
    ];
}

// Returns ['ip'=>, 'country'=>, 'city'=>, 'is_vpn'=>bool, 'isp'=>, 'cached'=>bool].
// Fails open: if the reputation provider can't be reached and nothing is
// cached, is_vpn comes back false rather than blocking every login.
function astra_inspect_ip(string $ip, $conn = null): array {
    $conn = $conn ?? ($GLOBALS['conn'] ?? null);
    if (!$conn) throw new RuntimeException('astra_inspect_ip(): no database connection available.');

    // Local development: astra_get_client_ip() had to invent $ip (there's no
    // real visitor), so there's nothing meaningful to reputation-check —
    // skip the provider call entirely rather than judging a made-up IP.
    if (astra_is_local_request()) {
        return ['ip' => $ip, 'country' => null, 'city' => null, 'is_vpn' => false, 'isp' => null, 'cached' => false];
    }

    astra_ensure_ip_cache_table($conn);

    $cached = astra_ip_cache_lookup($conn, $ip);
    if ($cached) {
        return [
            'ip'      => $ip,
            'country' => $cached['country'],
            'city'    => $cached['city'],
            'is_vpn'  => (bool)$cached['is_vpn'],
            'isp'     => $cached['isp'],
            'cached'  => true,
        ];
    }

    $result = astra_query_ip_provider($ip);
    if ($result === null) {
        return ['ip' => $ip, 'country' => null, 'city' => null, 'is_vpn' => false, 'isp' => null, 'cached' => false];
    }

    astra_ip_cache_store($conn, $ip, $result['country'], $result['city'], $result['is_vpn'], $result['isp']);

    return [
        'ip'      => $ip,
        'country' => $result['country'],
        'city'    => $result['city'],
        'is_vpn'  => $result['is_vpn'],
        'isp'     => $result['isp'],
        'cached'  => false,
    ];
}
