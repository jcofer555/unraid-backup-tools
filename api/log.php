<?php
// =============================================================================
// unraid-backup-tools — API: log.php
// Returns log lines from the live log or a specified archived log file.
// Supports offset-based streaming to avoid resending already-seen lines.
// =============================================================================

header('Content-Type: application/json');

define('UBT_LOG_DIR', '/boot/logs/unraid-backup-tools');
define('UBT_LOG_MAX_LINES', 2000);  // Safety cap per response

// ── Input validation ─────────────────────────────────────────────────────────
$offset   = max(0, (int)($_GET['offset'] ?? 0));
$req_file = $_GET['file'] ?? 'live';

// Determine which log file to read
if ($req_file === 'live') {
    $log_path = UBT_LOG_DIR . '/live.log';
} else {
    // Sanitize: only allow files within the log directory; no path traversal
    $real_req  = realpath($req_file);
    $real_base = realpath(UBT_LOG_DIR);

    if ($real_req === false || $real_base === false || strpos($real_req, $real_base) !== 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid log path']);
        exit;
    }
    $log_path = $real_req;
}

if (!file_exists($log_path)) {
    echo json_encode(['lines' => [], 'next_offset' => 0]);
    exit;
}

// ── Read lines from offset ───────────────────────────────────────────────────
$handle = fopen($log_path, 'r');
if (!$handle) {
    echo json_encode(['error' => 'Cannot open log file']);
    exit;
}

// Seek to byte offset
if ($offset > 0) {
    fseek($handle, $offset);
}

$lines = [];
$count = 0;
while (!feof($handle) && $count < UBT_LOG_MAX_LINES) {
    $line = fgets($handle);
    if ($line !== false) {
        $lines[] = rtrim($line);
        $count++;
    }
}

$next_offset = ftell($handle);
fclose($handle);

// Filter empty lines
$lines = array_values(array_filter($lines, fn($l) => $l !== ''));

echo json_encode([
    'lines'       => $lines,
    'next_offset' => $next_offset,
]);
