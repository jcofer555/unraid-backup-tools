<?php
// =============================================================================
// unraid-backup-tools — API: run.php
// Launches backup.sh asynchronously and returns immediately.
// =============================================================================

header('Content-Type: application/json');

define('UBT_SCRIPT',   '/usr/local/emhttp/plugins/unraid-backup-tools/scripts/backup.sh');
define('UBT_LOCK',     '/tmp/unraid-backup-tools.lock');
define('UBT_LOG_DIR',  '/boot/logs/unraid-backup-tools');
define('UBT_FLASH_CFG','/boot/config/plugins/unraid-backup-tools/flash-backup/flash-backup.cfg');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$mode = $_POST['mode'] ?? '';
$allowed_modes = ['flash-local', 'flash-remote', 'vm-backup', 'vm-restore'];

if (!in_array($mode, $allowed_modes, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid mode: ' . htmlspecialchars($mode)]);
    exit;
}

if (file_exists(UBT_LOCK)) {
    $running = trim(file_get_contents(UBT_LOCK));
    echo json_encode(['error' => 'Already running: ' . $running]);
    exit;
}

if (!is_executable(UBT_SCRIPT)) {
    echo json_encode(['error' => 'Backup script not found or not executable: ' . UBT_SCRIPT]);
    exit;
}

// Build safe command — escape mode (already validated against allowlist above)
$safe_mode = escapeshellarg($mode);
$log_pipe   = escapeshellarg(UBT_LOG_DIR . '/live.log');
$cmd = UBT_SCRIPT . ' --mode ' . $safe_mode . ' > ' . $log_pipe . ' 2>&1 &';

// Redirect output; detach from web process
exec($cmd);

echo json_encode(['ok' => true, 'mode' => $mode]);
