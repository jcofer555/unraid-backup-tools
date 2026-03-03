<?php
// =============================================================================
// unraid-backup-tools — API: status.php
// Returns whether a backup is currently running and which mode.
// =============================================================================

header('Content-Type: application/json');

define('UBT_LOCK', '/tmp/unraid-backup-tools.lock');

$running = false;
$mode    = '';

if (file_exists(UBT_LOCK)) {
    $running = true;
    $mode    = trim(file_get_contents(UBT_LOCK));
}

echo json_encode([
    'running' => $running,
    'mode'    => $mode,
]);
