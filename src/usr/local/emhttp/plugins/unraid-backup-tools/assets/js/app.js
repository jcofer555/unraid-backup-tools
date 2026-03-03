/**
 * unraid-backup-tools — Frontend Application
 * Author:  Jon C
 *
 * All AJAX calls: /plugins/unraid-backup-tools/include/ajax.php?action=<action>
 * State machine:  IDLE → RUNNING → STOPPING → IDLE
 */

'use strict';

var UBT = (function () {

  var AJAX = '/plugins/unraid-backup-tools/include/ajax.php';

  var state = {
    currentTool:   'flash-local',
    runState:      'IDLE',
    logInterval:   null,
    logScrollLock: true,
    lastLogOffset: 0
  };

  var PANELS = {
    'flash-local':  'panel-flash-local',
    'flash-remote': 'panel-flash-remote',
    'vm-backup':    'panel-vm-backup',
    'vm-restore':   'panel-vm-restore'
  };

  // ── Fetch helpers — parse JSON safely, expose raw text on failure ──────────
  function safeJSON(response) {
    return response.text().then(function (text) {
      if (!text || text.trim() === '') {
        throw new Error('Empty response from server. Check PHP errors on Unraid.');
      }
      try {
        return JSON.parse(text);
      } catch (e) {
        // Show the raw response in the log area so the user can see PHP errors
        appendLog('--- RAW SERVER RESPONSE (PHP error?) ---', 'error');
        appendLog(text.substring(0, 500), 'error');
        appendLog('----------------------------------------', 'error');
        throw new Error('Invalid JSON from server: ' + text.substring(0, 120));
      }
    });
  }

  function get(action, params) {
    params = params || {};
    params.action = action;
    var qs = new URLSearchParams(params);
    return fetch(AJAX + '?' + qs).then(safeJSON);
  }

  function post(action, body) {
    body = body || {};
    var qs   = new URLSearchParams({ action: action });
    var form = new URLSearchParams(body);
    return fetch(AJAX + '?' + qs, { method: 'POST', body: form }).then(safeJSON);
  }

  // ── DOM ───────────────────────────────────────────────────────────────────
  function byId(id) { return document.getElementById(id); }
  function all(sel) { return document.querySelectorAll(sel); }

  // ── State machine ──────────────────────────────────────────────────────────
  function setRunState(next) {
    state.runState = next;
    var isRunning  = next !== 'IDLE';
    var isStopping = next === 'STOPPING';

    all('[data-mode]').forEach(function (btn) { btn.disabled = isRunning; });
    all('.ubt-btn--stop').forEach(function (btn) {
      btn.disabled    = !isRunning || isStopping;
      btn.textContent = isStopping ? 'Stopping\u2026' : 'Stop';
    });

    var badge = byId('ubt-running-badge');
    if (!badge) return;
    if (isRunning) {
      badge.className   = 'ubt-badge ubt-badge--running';
      badge.textContent = 'Running: ' + state.currentTool;
    } else {
      badge.className   = 'ubt-badge ubt-badge--idle';
      badge.textContent = 'Idle';
    }
  }

  // ── Panel switching ────────────────────────────────────────────────────────
  function switchPanel(tool) {
    if (!PANELS[tool]) return;
    state.currentTool = tool;
    all('.ubt-panel').forEach(function (p) { p.classList.remove('ubt-panel--active'); });
    var t = document.getElementById(PANELS[tool]);
    if (t) t.classList.add('ubt-panel--active');
    post('save_tool', { tool: tool }).catch(function () {});
    loadLogList(tool);
  }

  // ── Run ────────────────────────────────────────────────────────────────────
  function triggerRun(mode) {
    if (state.runState !== 'IDLE') { showToast('A backup is already running.', 'err'); return; }
    if (mode === 'vm-restore') {
      var ow = document.querySelector('input[name="vm_restore_overwrite"]');
      if (ow && ow.checked) { showRestoreModal(function () { executeRun(mode); }); return; }
    }
    executeRun(mode);
  }

  function executeRun(mode) {
    setRunState('RUNNING');
    state.lastLogOffset = 0;
    clearLog();
    appendLog('Starting: ' + mode + '\u2026', 'info');
    startLogStream();

    post('run', { mode: mode })
      .then(function (data) {
        if (data.error) {
          appendLog('ERROR: ' + data.error, 'error');
          setRunState('IDLE');
          stopLogStream();
        }
      })
      .catch(function (err) {
        appendLog('Run failed: ' + err.message, 'error');
        setRunState('IDLE');
        stopLogStream();
      });
  }

  // ── Stop ───────────────────────────────────────────────────────────────────
  function triggerStop() {
    if (state.runState !== 'RUNNING') return;
    setRunState('STOPPING');
    appendLog('Sending stop signal\u2026', 'warn');
    post('stop').catch(function (err) { appendLog('Stop failed: ' + err.message, 'error'); });
  }

  // ── Status polling ─────────────────────────────────────────────────────────
  function startStatusPoll() {
    var timer = setInterval(function () {
      if (state.runState === 'IDLE') { clearInterval(timer); return; }
      get('status')
        .then(function (data) {
          if (!data.running) {
            appendLog('Backup process ended.', 'ok');
            setRunState('IDLE');
            stopLogStream();
            clearInterval(timer);
            loadLogList(state.currentTool);
          }
        })
        .catch(function () {});
    }, 2000);
  }

  // ── Log streaming ──────────────────────────────────────────────────────────
  function startLogStream() {
    startStatusPoll();
    state.logInterval = setInterval(fetchLiveLog, 1500);
  }

  function stopLogStream() {
    if (state.logInterval) { clearInterval(state.logInterval); state.logInterval = null; }
    fetchLiveLog();
  }

  function fetchLiveLog() {
    get('log', { file: 'live', offset: state.lastLogOffset })
      .then(function (data) {
        if (!data.lines || !data.lines.length) return;
        data.lines.forEach(function (line) { appendLog(line, classifyLine(line)); });
        state.lastLogOffset = data.next_offset || state.lastLogOffset;
      })
      .catch(function () {});
  }

  function fetchArchivedLog(path) {
    clearLog();
    get('log', { file: path, offset: 0 })
      .then(function (data) {
        if (data.lines) data.lines.forEach(function (l) { appendLog(l, classifyLine(l)); });
      })
      .catch(function () {});
  }

  function classifyLine(line) {
    var l = line.toLowerCase();
    if (l.indexOf('[error]') !== -1 || l.indexOf('error:') !== -1)   return 'error';
    if (l.indexOf('[warn]')  !== -1 || l.indexOf('warning:') !== -1) return 'warn';
    if (l.indexOf('success') !== -1 || l.indexOf('complete') !== -1) return 'ok';
    if (l.indexOf('[dry]')   !== -1)                                  return 'dry';
    return 'info';
  }

  function appendLog(text, type) {
    var area = byId('ubt-log-area');
    if (!area) return;
    var span = document.createElement('span');
    if (type && type !== 'info') span.className = 'log--' + type;
    span.textContent = text + '\n';
    area.appendChild(span);
    if (state.logScrollLock) area.scrollTop = area.scrollHeight;
  }

  function clearLog() {
    var area = byId('ubt-log-area');
    if (area) area.textContent = '';
  }

  // ── Log file list ──────────────────────────────────────────────────────────
  function loadLogList(tool) {
    var sel = byId('ubt-log-file-select');
    if (!sel) return;
    get('log_list', { tool: tool })
      .then(function (data) {
        while (sel.options.length > 1) sel.remove(1);
        (data.files || []).forEach(function (f) {
          var opt = document.createElement('option');
          opt.value = f.path;
          opt.textContent = f.label;
          sel.appendChild(opt);
        });
      })
      .catch(function () {});
  }

  // ── SSH test ───────────────────────────────────────────────────────────────
  function triggerSSHTest() {
    var hostEl = document.getElementById('flash_remote_host');
    var userEl = document.getElementById('flash_remote_user');
    var keyEl  = document.getElementById('flash_remote_key');
    var result = byId('ssh-test-result');

    var host = hostEl ? hostEl.value.trim() : '';
    var user = userEl ? userEl.value.trim() : 'root';
    var key  = keyEl  ? keyEl.value.trim()  : '';

    if (!host) {
      if (result) { result.className = 'ubt-test-result ubt-test-result--err'; result.textContent = 'Remote host is required.'; }
      return;
    }
    if (result) { result.className = 'ubt-test-result'; result.textContent = 'Testing\u2026'; }

    post('ssh_test', { host: host, user: user, key: key })
      .then(function (data) {
        if (!result) return;
        if (data.ok) {
          result.className   = 'ubt-test-result ubt-test-result--ok';
          result.textContent = '\u2713 Connected to ' + user + '@' + host;
        } else {
          result.className   = 'ubt-test-result ubt-test-result--err';
          result.textContent = '\u2717 ' + (data.error || 'Connection failed');
        }
      })
      .catch(function (err) {
        if (result) { result.className = 'ubt-test-result ubt-test-result--err'; result.textContent = err.message; }
      });
  }

  // ── Restore modal ──────────────────────────────────────────────────────────
  function showRestoreModal(onConfirm) {
    var overlay = byId('ubt-confirm-modal');
    if (!overlay) { onConfirm(); return; }
    overlay.classList.add('ubt-modal-overlay--visible');
    overlay.setAttribute('aria-hidden', 'false');

    var confirmBtn = byId('modal-confirm');
    var cancelBtn  = byId('modal-cancel');

    function cleanup() {
      overlay.classList.remove('ubt-modal-overlay--visible');
      overlay.setAttribute('aria-hidden', 'true');
      if (confirmBtn) confirmBtn.removeEventListener('click', handleConfirm);
      if (cancelBtn)  cancelBtn.removeEventListener('click', handleCancel);
    }
    function handleConfirm() { cleanup(); onConfirm(); }
    function handleCancel()  { cleanup(); }

    if (confirmBtn) confirmBtn.addEventListener('click', handleConfirm);
    if (cancelBtn)  cancelBtn.addEventListener('click', handleCancel);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) handleCancel(); }, { once: true });
  }

  // ── Toast ──────────────────────────────────────────────────────────────────
  function showToast(msg, type) {
    var toast = byId('ubt-toast');
    if (!toast) {
      toast = document.createElement('div');
      toast.id = 'ubt-toast';
      var main = document.querySelector('.ubt-main');
      if (main) main.prepend(toast);
    }
    toast.className     = 'ubt-toast ubt-toast--' + (type === 'err' ? 'err' : 'ok');
    toast.textContent   = msg;
    toast.style.display = 'block';
    clearTimeout(toast._t);
    toast._t = setTimeout(function () { toast.style.display = 'none'; }, 5000);
  }

  // ── Init ───────────────────────────────────────────────────────────────────
  function init() {
    var toolSel = byId('ubt-tool-select');
    if (toolSel) {
      switchPanel(toolSel.value);
      toolSel.addEventListener('change', function () { switchPanel(toolSel.value); });
    }

    all('[data-mode]').forEach(function (btn) {
      btn.addEventListener('click', function () { triggerRun(btn.dataset.mode); });
    });

    all('.ubt-btn--stop').forEach(function (btn) {
      btn.addEventListener('click', triggerStop);
    });

    var sshBtn = byId('test-ssh-btn');
    if (sshBtn) sshBtn.addEventListener('click', triggerSSHTest);

    var logSel = byId('ubt-log-file-select');
    if (logSel) {
      logSel.addEventListener('change', function () {
        var v = logSel.value;
        if (v === 'live') { clearLog(); appendLog('Idle \u2014 run a backup to see output here.', 'info'); }
        else fetchArchivedLog(v);
      });
    }

    var refreshBtn = byId('ubt-log-refresh');
    if (refreshBtn) {
      refreshBtn.addEventListener('click', function () {
        var sel = byId('ubt-log-file-select');
        if (sel && sel.value !== 'live') fetchArchivedLog(sel.value);
      });
    }

    var clearBtn = byId('ubt-log-clear');
    if (clearBtn) clearBtn.addEventListener('click', clearLog);

    var logArea = byId('ubt-log-area');
    if (logArea) {
      logArea.addEventListener('scroll', function () {
        state.logScrollLock = logArea.scrollTop + logArea.clientHeight >= logArea.scrollHeight - 20;
      });
    }

    // Check if already running on page load
    get('status')
      .then(function (data) {
        if (data.running) {
          state.currentTool = data.mode || state.currentTool;
          setRunState('RUNNING');
          startLogStream();
          appendLog('Resuming log for: ' + data.mode, 'info');
        }
      })
      .catch(function (err) {
        appendLog('Status check failed: ' + err.message, 'warn');
      });
  }

  return { init: init };

}());

document.addEventListener('DOMContentLoaded', UBT.init);
