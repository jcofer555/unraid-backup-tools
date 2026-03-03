<?php
// =============================================================================
// unraid-backup-tools — API: stop.php
// Delegates to stop.sh to write the stop flag cleanly.
// =============================================================================

header('Content-Type: application/json');

define('UBT_STOP_SCRIPT', '/usr/local/emhttp/plugins/unraid-backup-tools/scripts/stop.sh');
define('UBT_LOCK',        '/tmp/unraid-backup-tools.lock');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (!file_exists(UBT_LOCK)) {
    echo json_encode(['ok' => true, 'message' => 'No active backup to stop']);
    exit;
}

if (!is_executable(UBT_STOP_SCRIPT)) {
    echo json_encode(['error' => 'Stop script not found: ' . UBT_STOP_SCRIPT]);
    exit;
}

exec(escapeshellcmd(UBT_STOP_SCRIPT) . ' > /dev/null 2>&1 &');

echo json_encode(['ok' => true]);
