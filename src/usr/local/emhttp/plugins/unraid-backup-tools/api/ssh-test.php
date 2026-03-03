<?php
// =============================================================================
// unraid-backup-tools — API: ssh-test.php
// Tests SSH connectivity to the configured remote host.
// Returns JSON { ok: bool, error?: string }
// =============================================================================

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// ── Input validation ─────────────────────────────────────────────────────────
$host = trim($_POST['host'] ?? '');
$user = trim($_POST['user'] ?? 'root');
$key  = trim($_POST['key']  ?? '');

// Strict allowlists to prevent command injection
if (!preg_match('/^[a-zA-Z0-9.\-]+$/', $host)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid host format']);
    exit;
}

if (!preg_match('/^[a-zA-Z0-9_\-.]+$/', $user)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid user format']);
    exit;
}

if ($key !== '' && !preg_match('/^[a-zA-Z0-9\/\-_.]+$/', $key)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid key path format']);
    exit;
}

if ($key !== '' && !file_exists($key)) {
    echo json_encode(['ok' => false, 'error' => 'Key file not found: ' . $key]);
    exit;
}

// ── Build SSH test command ────────────────────────────────────────────────────
$ssh_opts = [
    '-o StrictHostKeyChecking=no',
    '-o BatchMode=yes',
    '-o ConnectTimeout=10',
];

if ($key !== '') {
    $ssh_opts[] = '-i ' . escapeshellarg($key);
}

$cmd = 'ssh ' . implode(' ', $ssh_opts)
     . ' ' . escapeshellarg($user . '@' . $host)
     . ' "echo ubt-ok" 2>&1';

$output = [];
$rc     = 0;
exec($cmd, $output, $rc);

$output_str = implode(' ', $output);

if ($rc === 0 && strpos($output_str, 'ubt-ok') !== false) {
    echo json_encode(['ok' => true]);
} else {
    // Mask anything that looks like a key fingerprint or sensitive info
    $sanitized = preg_replace('/([0-9a-fA-F]{2}:){5,}[0-9a-fA-F]{2}/', '[fingerprint]', $output_str);
    echo json_encode(['ok' => false, 'error' => $sanitized ?: 'SSH connection failed']);
}
