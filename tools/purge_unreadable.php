<?php
// Removes accounts whose encrypted fields can no longer be read (after lost
// encryption keys), plus what hangs off them. Dry run unless --apply.
//   php tools/purge_unreadable.php           show what would be removed
//   php tools/purge_unreadable.php --apply   remove it
// In the container this runs at start when HASTRA_PURGE_UNREADABLE is set
// ("dry" to preview, "1" to apply); see docker/entrypoint.sh.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(); }
define('ASTRA_MIGRATION_INCLUDE', true);
require __DIR__ . '/../core/db.php';
require __DIR__ . '/../core/data_deletion.php';   // db_table_exists_safe()
require __DIR__ . '/../core/purge_unreadable.php';
$ok = astra_purge_unreadable($conn, in_array('--apply', $argv, true), function ($l) { echo 'hastra-purge: ', $l, "\n"; });
exit($ok ? 0 : 1);
