<?php
// =============================================================================
// unraid-backup-tools — API: save-plugin.php
// Persists the user's selected backup tool to which-plugin.cfg.
// =============================================================================

header('Content-Type: application/json');

define('UBT_WHICH_CFG', '/boot/config/plugins/unraid-backup-tools/which-plugin.cfg');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$tool = $_POST['tool'] ?? '';
$allowed = ['flash-local', 'flash-remote', 'vm-backup', 'vm-restore'];

if (!in_array($tool, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid tool: ' . htmlspecialchars($tool)]);
    exit;
}

$dir = dirname(UBT_WHICH_CFG);
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
}

$written = file_put_contents(
    UBT_WHICH_CFG,
    'SELECTED_TOOL=' . $tool . "\n",
    LOCK_EX
);

if ($written === false) {
    echo json_encode(['error' => 'Failed to write which-plugin.cfg']);
    exit;
}

echo json_encode(['ok' => true, 'tool' => $tool]);
