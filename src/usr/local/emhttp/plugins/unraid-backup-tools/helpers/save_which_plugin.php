<?php
// save_which_plugin.php
// Deterministic, atomic save of the selected plugin to which-plugin.cfg

$pluginCfgDir  = '/boot/config/plugins/unraid-backup-tools';
$whichCfg      = $pluginCfgDir . '/which-plugin.cfg';
$allowedPlugins = ['flash-backup', 'vm-backup-and-restore'];

header('Content-Type: application/json');

$plugin = trim($_POST['plugin'] ?? ($_GET['plugin'] ?? ''));

if (!in_array($plugin, $allowedPlugins, true)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid plugin selection']);
    exit;
}

if (!is_dir($pluginCfgDir)) {
    @mkdir($pluginCfgDir, 0755, true);
}

$content = "WHICH_PLUGIN=\"{$plugin}\"\n";
$tmp     = $whichCfg . '.tmp.' . getmypid();

if (file_put_contents($tmp, $content) === false) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to write config']);
    exit;
}

if (!rename($tmp, $whichCfg)) {
    @unlink($tmp);
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to commit config']);
    exit;
}

echo json_encode(['status' => 'ok', 'plugin' => $plugin]);
