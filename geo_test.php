<?php
// geo_test.php — fixed version
include_once 'db.php'; // use include_once instead of include

// Test 1 — check allow_url_fopen
echo "<b>allow_url_fopen:</b> " . ini_get('allow_url_fopen') . "<br><br>";

// Test 2 — check geo column
$col_check = mysqli_query($conn, "SHOW COLUMNS FROM logs LIKE 'geo'");
echo "<b>geo column:</b> " . (mysqli_num_rows($col_check) > 0 ? "EXISTS ✓" : "MISSING ✗") . "<br><br>";

// Test 3 — last 3 logs
$recent = mysqli_query($conn, "SELECT id, ip_address, geo FROM logs ORDER BY id DESC LIMIT 3");
echo "<b>Last 3 log rows:</b><br>";
while ($row = mysqli_fetch_assoc($recent)) {
    echo "ID: " . $row['id'] . " | IP: " . $row['ip_address'] . " | geo: " . ($row['geo'] ?? 'NULL') . "<br>";
}
echo "<br>";

// Test 4 — ip-api call
$test_ip  = '8.8.8.8';
$url      = "http://ip-api.com/json/" . $test_ip . "?fields=status,city,regionName,country,lat,lon,isp";
$response = @file_get_contents($url);
echo "<b>ip-api raw response for 8.8.8.8:</b><br>";
if (!$response) {
    echo "FAILED<br><br>";
} else {
    echo $response . "<br><br>";
    $data = json_decode($response, true);
    if ($data && $data['status'] === 'success') {
        echo "<b>Parsed geo:</b> " . $data['city'] . ", " . $data['regionName'] . ", " . $data['country'] . "<br>";
        echo "<b>Coords:</b> " . $data['lat'] . ", " . $data['lon'] . "<br>";
        echo "<b>ISP:</b> " . $data['isp'] . "<br><br>";
    }
}

// Test 5 — current IP
echo "<b>Your current IP as seen by PHP:</b> " . $_SERVER['REMOTE_ADDR'] . "<br>";

// Test 6 — fake a real IP and log it
$_SERVER['REMOTE_ADDR'] = '8.8.8.8';
log_activity($conn, 1, 'geo_test');
$result = mysqli_query($conn, "SELECT id, ip_address, geo FROM logs ORDER BY id DESC LIMIT 1");
$row    = mysqli_fetch_assoc($result);
echo "<br><b>Test log entry:</b><br>";
echo "IP stored: " . $row['ip_address'] . "<br>";
echo "Geo stored: " . ($row['geo'] ?? 'NULL') . "<br>";
?>