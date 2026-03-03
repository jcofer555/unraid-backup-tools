<?php
// =============================================================================
// unraid-backup-tools — API: log-list.php
// Returns available log files for the given tool mode.
// =============================================================================

header('Content-Type: application/json');

define('UBT_LOG_DIR', '/boot/logs/unraid-backup-tools');

$tool = $_GET['tool'] ?? '';

// Map frontend tool names to log file prefixes
$prefix_map = [
    'flash-local'  => 'flash-local',
    'flash-remote' => 'flash-remote',
    'vm-backup'    => 'vm-backup',
    'vm-restore'   => 'vm-restore',
];

if (!array_key_exists($tool, $prefix_map)) {
    echo json_encode(['files' => []]);
    exit;
}

$prefix = $prefix_map[$tool];
$pattern = UBT_LOG_DIR . '/' . $prefix . '_*.log';
$files   = glob($pattern) ?: [];
rsort($files);  // Newest first

$result = [];
foreach (array_slice($files, 0, 10) as $file) {
    $name = basename($file);
    // Extract timestamp from filename: mode_YYYYMMDD_HHMMSS.log
    if (preg_match('/_(\d{8})_(\d{6})\.log$/', $name, $m)) {
        $date = substr($m[1], 0, 4) . '-' . substr($m[1], 4, 2) . '-' . substr($m[1], 6, 2);
        $time = substr($m[2], 0, 2) . ':' . substr($m[2], 2, 2) . ':' . substr($m[2], 4, 2);
        $label = $date . ' ' . $time;
    } else {
        $label = $name;
    }
    $result[] = [
        'path'  => $file,
        'label' => $label,
    ];
}

echo json_encode(['files' => $result]);
