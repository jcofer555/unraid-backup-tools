<?php
// =============================================================================
// unraid-backup-tools — AJAX Handler
// URL: /plugins/unraid-backup-tools/include/ajax.php?action=<action>
//
// Output buffering is started immediately so any stray PHP warnings/notices
// cannot corrupt the JSON response. The buffer is discarded before output.
// =============================================================================

// Buffer everything — discard any warnings/notices before we echo JSON
ob_start();

// Suppress notices and warnings from polluting JSON output
error_reporting(0);
ini_set('display_errors', '0');

// ── Constants ─────────────────────────────────────────────────────────────────
define('UBT_LOCK',      '/tmp/unraid-backup-tools.lock');
define('UBT_STOP_FLAG', '/tmp/unraid-backup-tools.stop');
define('UBT_LOG_DIR',   '/boot/logs/unraid-backup-tools');
define('UBT_SCRIPT',    '/usr/local/emhttp/plugins/unraid-backup-tools/scripts/backup.sh');
define('UBT_STOP_SCR',  '/usr/local/emhttp/plugins/unraid-backup-tools/scripts/stop.sh');
define('UBT_WHICH_CFG', '/boot/config/plugins/unraid-backup-tools/which-plugin.cfg');
define('UBT_LOG_LINES', 2000);

// ── Helper: send JSON and exit cleanly ────────────────────────────────────────
function ubt_json($data) {
    ob_clean(); // Discard any buffered output (warnings, notices, etc.)
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// ── Route action ──────────────────────────────────────────────────────────────
$action = isset($_REQUEST['action']) ? (string)$_REQUEST['action'] : '';

switch ($action) {

    // ── Run a backup mode ─────────────────────────────────────────────────────
    case 'run':
        $mode    = isset($_POST['mode']) ? (string)$_POST['mode'] : '';
        $allowed = array('flash-local', 'flash-remote', 'vm-backup', 'vm-restore');

        if (!in_array($mode, $allowed, true)) {
            ubt_json(array('error' => 'Invalid mode: ' . $mode));
        }
        if (file_exists(UBT_LOCK)) {
            $running = trim(file_get_contents(UBT_LOCK));
            ubt_json(array('error' => 'Already running: ' . $running));
        }
        if (!file_exists(UBT_SCRIPT)) {
            ubt_json(array('error' => 'Backup script not found at ' . UBT_SCRIPT));
        }
        if (!is_executable(UBT_SCRIPT)) {
            ubt_json(array('error' => 'Backup script not executable. Run: chmod +x ' . UBT_SCRIPT));
        }

        // Ensure log directory and live log file exist
        if (!is_dir(UBT_LOG_DIR)) {
            @mkdir(UBT_LOG_DIR, 0755, true);
        }
        $live_log = UBT_LOG_DIR . '/live.log';
        @file_put_contents($live_log, '');

        $cmd = escapeshellcmd(UBT_SCRIPT)
             . ' --mode ' . escapeshellarg($mode)
             . ' >> ' . escapeshellarg($live_log)
             . ' 2>&1 &';
        exec($cmd);
        ubt_json(array('ok' => true, 'mode' => $mode));
        break;

    // ── Stop running backup ───────────────────────────────────────────────────
    case 'stop':
        if (!file_exists(UBT_LOCK)) {
            ubt_json(array('ok' => true, 'message' => 'Nothing running'));
        }
        if (!file_exists(UBT_STOP_SCR)) {
            ubt_json(array('error' => 'Stop script not found at ' . UBT_STOP_SCR));
        }
        exec(escapeshellcmd(UBT_STOP_SCR) . ' > /dev/null 2>&1 &');
        ubt_json(array('ok' => true));
        break;

    // ── Status check ──────────────────────────────────────────────────────────
    case 'status':
        $running = file_exists(UBT_LOCK);
        $mode    = $running ? trim(@file_get_contents(UBT_LOCK)) : '';
        ubt_json(array('running' => $running, 'mode' => $mode));
        break;

    // ── Stream log lines from byte offset ────────────────────────────────────
    case 'log':
        $offset   = max(0, (int)(isset($_GET['offset']) ? $_GET['offset'] : 0));
        $req_file = isset($_GET['file']) ? (string)$_GET['file'] : 'live';

        if ($req_file === 'live') {
            $log_path = UBT_LOG_DIR . '/live.log';
        } else {
            $real_req  = realpath($req_file);
            $real_base = realpath(UBT_LOG_DIR);
            if (!$real_req || !$real_base || strpos($real_req, $real_base) !== 0) {
                ubt_json(array('error' => 'Invalid log path'));
            }
            $log_path = $real_req;
        }

        if (!file_exists($log_path)) {
            ubt_json(array('lines' => array(), 'next_offset' => 0));
        }

        $handle = @fopen($log_path, 'r');
        if (!$handle) {
            ubt_json(array('error' => 'Cannot open log file'));
        }

        if ($offset > 0) fseek($handle, $offset);

        $lines = array();
        $count = 0;
        while (!feof($handle) && $count < UBT_LOG_LINES) {
            $line = fgets($handle);
            if ($line !== false) {
                $lines[] = rtrim($line);
                $count++;
            }
        }
        $next_offset = ftell($handle);
        fclose($handle);

        $lines = array_values(array_filter($lines, function($l) { return $l !== ''; }));
        ubt_json(array('lines' => $lines, 'next_offset' => $next_offset));
        break;

    // ── List archived log files ───────────────────────────────────────────────
    case 'log_list':
        $tool = isset($_GET['tool']) ? (string)$_GET['tool'] : '';
        $prefix_map = array(
            'flash-local'  => 'flash-local',
            'flash-remote' => 'flash-remote',
            'vm-backup'    => 'vm-backup',
            'vm-restore'   => 'vm-restore',
        );

        if (!array_key_exists($tool, $prefix_map)) {
            ubt_json(array('files' => array()));
        }

        $files = glob(UBT_LOG_DIR . '/' . $prefix_map[$tool] . '_*.log');
        if (!$files) $files = array();
        rsort($files);
        $result = array();

        foreach (array_slice($files, 0, 10) as $file) {
            $name = basename($file);
            if (preg_match('/_(\d{8})_(\d{6})\.log$/', $name, $m)) {
                $date  = substr($m[1],0,4).'-'.substr($m[1],4,2).'-'.substr($m[1],6,2);
                $time  = substr($m[2],0,2).':'.substr($m[2],2,2).':'.substr($m[2],4,2);
                $label = $date . ' ' . $time;
            } else {
                $label = $name;
            }
            $result[] = array('path' => $file, 'label' => $label);
        }
        ubt_json(array('files' => $result));
        break;

    // ── Test SSH connection ───────────────────────────────────────────────────
    case 'ssh_test':
        $host = isset($_POST['host']) ? trim((string)$_POST['host']) : '';
        $user = isset($_POST['user']) ? trim((string)$_POST['user']) : 'root';
        $key  = isset($_POST['key'])  ? trim((string)$_POST['key'])  : '';

        if (!preg_match('/^[a-zA-Z0-9.\-]+$/', $host)) {
            ubt_json(array('ok' => false, 'error' => 'Invalid host format'));
        }
        if (!preg_match('/^[a-zA-Z0-9_\-.]+$/', $user)) {
            ubt_json(array('ok' => false, 'error' => 'Invalid user format'));
        }
        if ($key !== '' && !preg_match('/^[a-zA-Z0-9\/\-_.]+$/', $key)) {
            ubt_json(array('ok' => false, 'error' => 'Invalid key path format'));
        }
        if ($key !== '' && !file_exists($key)) {
            ubt_json(array('ok' => false, 'error' => 'Key file not found: ' . $key));
        }

        $opts = '-o StrictHostKeyChecking=no -o BatchMode=yes -o ConnectTimeout=10';
        if ($key !== '') $opts .= ' -i ' . escapeshellarg($key);
        $cmd = 'ssh ' . $opts . ' ' . escapeshellarg($user . '@' . $host) . ' "echo ubt-ok" 2>&1';

        $out = array(); $rc = 0;
        exec($cmd, $out, $rc);
        $str = implode(' ', $out);

        if ($rc === 0 && strpos($str, 'ubt-ok') !== false) {
            ubt_json(array('ok' => true));
        } else {
            $san = preg_replace('/([0-9a-fA-F]{2}:){5,}[0-9a-fA-F]{2}/', '[fingerprint]', $str);
            ubt_json(array('ok' => false, 'error' => $san ?: 'SSH connection failed'));
        }
        break;

    // ── Save selected tool ────────────────────────────────────────────────────
    case 'save_tool':
        $tool    = isset($_POST['tool']) ? (string)$_POST['tool'] : '';
        $allowed = array('flash-local', 'flash-remote', 'vm-backup', 'vm-restore');

        if (!in_array($tool, $allowed, true)) {
            ubt_json(array('error' => 'Invalid tool: ' . $tool));
        }

        $dir = dirname(UBT_WHICH_CFG);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        @file_put_contents(UBT_WHICH_CFG, 'SELECTED_TOOL=' . $tool . "\n", LOCK_EX);
        ubt_json(array('ok' => true));
        break;

    // ── Unknown action ────────────────────────────────────────────────────────
    default:
        ubt_json(array('error' => 'Unknown action: ' . $action));
        break;
}
