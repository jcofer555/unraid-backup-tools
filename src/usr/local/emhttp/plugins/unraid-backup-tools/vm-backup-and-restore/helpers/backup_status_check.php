<?php
header('Content-Type: application/json');

$status_file = '/tmp/unraid-backup-tools/vm-backup-and-restore/backup_status.txt';

$status = 'No Backup Running';

if (file_exists($status_file)) {
    $raw = trim(file_get_contents($status_file));
    if ($raw !== '') {
        $status = $raw;
    }
}

$running = ($status !== 'No Backup Running');

echo json_encode([
    'status' => $status,
    'running' => $running
]);
