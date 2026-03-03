<?php
// =============================================================================
// unraid-backup-tools — AJAX Handler
// Called via: POST /plugins/unraid-backup-tools/include/ajax.php
// All actions routed through a single endpoint.
// =============================================================================

header('Content-Type: application/json');

define('UBT_LOCK',      '/tmp/unraid-backup-tools.lock');
define('UBT_STOP_FLAG', '/tmp/unraid-backup-tools.stop');
define('UBT_LOG_DIR',   '/boot/logs/unraid-backup-tools');
define('UBT_SCRIPT',    '/usr/local/emhttp/plugins/unraid-backup-tools/scripts/backup.sh');
define('UBT_STOP_SCR',  '/usr/local/emhttp/plugins/unraid-backup-tools/scripts/stop.sh');
define('UBT_WHICH_CFG', '/boot/config/plugins/unraid-backup-tools/which-plugin.cfg');
define('UBT_LOG_LINES', 2000);

$action = $_REQUEST['action'] ?? '';

switch ($action) {

    // ── Run a backup mode ─────────────────────────────────────────────────
    case 'run':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POST required']); exit; }

        $mode = $_POST['mode'] ?? '';
        $allowed = ['flash-local', 'flash-remote', 'vm-backup', 'vm-restore'];
        if (!in_array($mode, $allowed, true)) {
            echo json_encode(['error' => 'Invalid mode: ' . htmlspecialchars($mode)]);
            exit;
        }
        if (file_exists(UBT_LOCK)) {
            $running = trim(file_get_contents(UBT_LOCK));
            echo json_encode(['error' => 'Already running: ' . htmlspecialchars($running)]);
            exit;
        }
        if (!is_executable(UBT_SCRIPT)) {
            echo json_encode(['error' => 'Backup script not found or not executable']);
            exit;
        }

        // Write the live log path so it can be streamed
        $live_log = UBT_LOG_DIR . '/live.log';
        @file_put_contents($live_log, '');

        $cmd = escapeshellcmd(UBT_SCRIPT)
             . ' --mode ' . escapeshellarg($mode)
             . ' >> ' . escapeshellarg($live_log)
             . ' 2>&1 &';
        exec($cmd);
        echo json_encode(['ok' => true, 'mode' => $mode]);
        break;

    // ── Stop running backup ───────────────────────────────────────────────
    case 'stop':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POST required']); exit; }

        if (!file_exists(UBT_LOCK)) {
            echo json_encode(['ok' => true, 'message' => 'Nothing running']);
            exit;
        }
        if (!is_executable(UBT_STOP_SCR)) {
            echo json_encode(['error' => 'Stop script not found']);
            exit;
        }
        exec(escapeshellcmd(UBT_STOP_SCR) . ' > /dev/null 2>&1 &');
        echo json_encode(['ok' => true]);
        break;

    // ── Status check ──────────────────────────────────────────────────────
    case 'status':
        $running = file_exists(UBT_LOCK);
        $mode    = $running ? trim(file_get_contents(UBT_LOCK)) : '';
        echo json_encode(['running' => $running, 'mode' => $mode]);
        break;

    // ── Stream log lines from offset ──────────────────────────────────────
    case 'log':
        $offset   = max(0, (int)($_GET['offset'] ?? 0));
        $req_file = $_GET['file'] ?? 'live';

        if ($req_file === 'live') {
            $log_path = UBT_LOG_DIR . '/live.log';
        } else {
            $real_req  = realpath($req_file);
            $real_base = realpath(UBT_LOG_DIR);
            if (!$real_req || !$real_base || strpos($real_req, $real_base) !== 0) {
                echo json_encode(['error' => 'Invalid log path']);
                exit;
            }
            $log_path = $real_req;
        }

        if (!file_exists($log_path)) {
            echo json_encode(['lines' => [], 'next_offset' => 0]);
            exit;
        }

        $handle = fopen($log_path, 'r');
        if (!$handle) { echo json_encode(['error' => 'Cannot open log']); exit; }
        if ($offset > 0) fseek($handle, $offset);

        $lines = [];
        $count = 0;
        while (!feof($handle) && $count < UBT_LOG_LINES) {
            $line = fgets($handle);
            if ($line !== false) { $lines[] = rtrim($line); $count++; }
        }
        $next_offset = ftell($handle);
        fclose($handle);
        $lines = array_values(array_filter($lines, fn($l) => $l !== ''));
        echo json_encode(['lines' => $lines, 'next_offset' => $next_offset]);
        break;

    // ── List archived log files ───────────────────────────────────────────
    case 'log_list':
        $tool = $_GET['tool'] ?? '';
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
        $files = glob(UBT_LOG_DIR . '/' . $prefix_map[$tool] . '_*.log') ?: [];
        rsort($files);
        $result = [];
        foreach (array_slice($files, 0, 10) as $file) {
            $name = basename($file);
            if (preg_match('/_(\d{8})_(\d{6})\.log$/', $name, $m)) {
                $date  = substr($m[1],0,4).'-'.substr($m[1],4,2).'-'.substr($m[1],6,2);
                $time  = substr($m[2],0,2).':'.substr($m[2],2,2).':'.substr($m[2],4,2);
                $label = $date . ' ' . $time;
            } else {
                $label = $name;
            }
            $result[] = ['path' => $file, 'label' => $label];
        }
        echo json_encode(['files' => $result]);
        break;

    // ── Test SSH connection ───────────────────────────────────────────────
    case 'ssh_test':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POST required']); exit; }

        $host = trim($_POST['host'] ?? '');
        $user = trim($_POST['user'] ?? 'root');
        $key  = trim($_POST['key']  ?? '');

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
            echo json_encode(['ok' => false, 'error' => 'Key file not found']);
            exit;
        }

        $opts = '-o StrictHostKeyChecking=no -o BatchMode=yes -o ConnectTimeout=10';
        if ($key !== '') $opts .= ' -i ' . escapeshellarg($key);
        $cmd = 'ssh ' . $opts . ' ' . escapeshellarg($user . '@' . $host) . ' "echo ubt-ok" 2>&1';

        $out = []; $rc = 0;
        exec($cmd, $out, $rc);
        $str = implode(' ', $out);

        if ($rc === 0 && strpos($str, 'ubt-ok') !== false) {
            echo json_encode(['ok' => true]);
        } else {
            $san = preg_replace('/([0-9a-fA-F]{2}:){5,}[0-9a-fA-F]{2}/', '[fingerprint]', $str);
            echo json_encode(['ok' => false, 'error' => $san ?: 'SSH connection failed']);
        }
        break;

    // ── Save selected tool to which-plugin.cfg ────────────────────────────
    case 'save_tool':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POST required']); exit; }

        $tool    = $_POST['tool'] ?? '';
        $allowed = ['flash-local', 'flash-remote', 'vm-backup', 'vm-restore'];
        if (!in_array($tool, $allowed, true)) {
            echo json_encode(['error' => 'Invalid tool']);
            exit;
        }
        $dir = dirname(UBT_WHICH_CFG);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        file_put_contents(UBT_WHICH_CFG, 'SELECTED_TOOL=' . $tool . "\n", LOCK_EX);
        echo json_encode(['ok' => true]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action: ' . htmlspecialchars($action)]);
        break;
}
