<?php
// save_which_plugin.php
// Deterministic, atomic save of the selected plugin to which-plugin.cfg
// Compliant with unified-jonathan-weighted-skill: no silent failures, explicit paths.

header('Content-Type: application/json');

$pluginCfgDir   = '/boot/config/plugins/unraid-backup-tools';
$whichCfg       = $pluginCfgDir . '/which-plugin.cfg';
$allowedPlugins = ['flash-backup', 'vm-backup-and-restore'];

// ── 1. Validate input ─────────────────────────────────────
$plugin = trim($_POST['plugin'] ?? '');

if (!in_array($plugin, $allowedPlugins, true)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid plugin selection: ' . htmlspecialchars($plugin)]);
    exit;
}

// ── 2. Ensure config directory tree exists ─────────────────
foreach ([
    $pluginCfgDir,
    $pluginCfgDir . '/flash-backup',
    $pluginCfgDir . '/vm-backup-and-restore',
] as $dir) {
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0755, true)) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Could not create config directory: ' . $dir]);
            exit;
        }
    }
}

// ── 3. Atomic write via tmp → rename ──────────────────────
$content = "WHICH_PLUGIN=\"{$plugin}\"\n";
// Keep tmp on same filesystem as destination so rename() is truly atomic
$tmp = $pluginCfgDir . '/which-plugin.cfg.tmp.' . getmypid();

if (file_put_contents($tmp, $content) === false) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to write temporary config file']);
    exit;
}

if (!rename($tmp, $whichCfg)) {
    @unlink($tmp);
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to commit config (rename failed)']);
    exit;
}

// ── 4. Verify the write ───────────────────────────────────
$verify = @file_get_contents($whichCfg);
if ($verify === false || strpos($verify, $plugin) === false) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Config written but verification failed']);
    exit;
}

echo json_encode(['status' => 'ok', 'plugin' => $plugin]);
