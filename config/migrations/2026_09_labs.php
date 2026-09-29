<?php
// Hastra Labs — free community tools and student hub (Sep 2026)
//
// Run it either way:
//   • Sysadmin portal → "Database migration" page (portals/sysadmin/migrate.php)
//   • C:\xampp\php\php.exe C:\xampp\htdocs\Hastra\config\migrations\2026_09_labs.php
//
// labs_profiles      A community (non-enterprise) profile. There is no email
//                    and no password: the student holds a random recovery code
//                    and only its HMAC blind index is stored, so the table
//                    alone can never be used to sign in. Display name and
//                    study progress go through the column-encryption envelope.
// labs_devices       Optional "remember this device" tokens (blind-indexed).
// labs_video_cache   Ranked YouTube results per topic query, so a syllabus
//                    re-opened by a hundred students costs one API call.
// labs_rate          Fixed-window request counters for the public endpoints
//                    (keyed by blind index, never by a raw IP address).
// labs_video_votes   Community like/dislike counts per (topic, video), so the
//                    video other students found most useful for a topic rises
//                    to the top for everyone, not just the device that liked it.

$__astra_labs_cli = PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__;
if (!$__astra_labs_cli && !defined('ASTRA_MIGRATION_INCLUDE')) { http_response_code(404); exit(); }

function astra_migrate_labs($conn, callable $out) {
    $out("");
    $out("== hastra labs: community profiles, video cache, rate limits");

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS labs_profiles (
        id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        code_bindex   CHAR(64)     NOT NULL,
        display_name  TEXT         NULL,
        progress      MEDIUMTEXT   NULL,
        created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at    DATETIME     NULL,
        last_seen_at  DATETIME     NULL,
        UNIQUE KEY uniq_labs_code (code_bindex)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $out("   labs_profiles ready");

    // "Remember this device": an HttpOnly cookie carries a random token; only
    // its blind index is stored, and it expires on its own.
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS labs_devices (
        token_bindex  CHAR(64)     NOT NULL PRIMARY KEY,
        profile_id    INT UNSIGNED NOT NULL,
        expires_at    DATETIME     NOT NULL,
        KEY idx_labs_devices_profile (profile_id),
        CONSTRAINT fk_labs_devices_profile FOREIGN KEY (profile_id) REFERENCES labs_profiles (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $out("   labs_devices ready");

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS labs_video_cache (
        query_hash  CHAR(64)    NOT NULL PRIMARY KEY,
        query_text  VARCHAR(200) NOT NULL,
        payload     MEDIUMTEXT  NOT NULL,
        fetched_at  DATETIME    NOT NULL,
        KEY idx_labs_video_fetched (fetched_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $out("   labs_video_cache ready");

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS labs_rate (
        bucket        CHAR(64)     NOT NULL PRIMARY KEY,
        window_start  INT UNSIGNED NOT NULL,
        hits          INT UNSIGNED NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $out("   labs_rate ready");

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS labs_video_votes (
        topic_hash   CHAR(64)     NOT NULL,
        video_id     VARCHAR(20)  NOT NULL,
        topic_text   VARCHAR(200) NOT NULL,
        video_title  VARCHAR(300) NOT NULL,
        likes        INT UNSIGNED NOT NULL DEFAULT 0,
        dislikes     INT UNSIGNED NOT NULL DEFAULT 0,
        updated_at   DATETIME     NOT NULL,
        PRIMARY KEY (topic_hash, video_id),
        KEY idx_labs_video_votes_topic_score (topic_hash, likes)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $out("   labs_video_votes ready");
    $out("   done");
}

if ($__astra_labs_cli) {
    require __DIR__ . '/../config.php';
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    astra_migrate_labs($conn, function ($line) { echo $line, "\n"; });
    echo "\nDone.\n";
}
