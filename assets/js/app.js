/**
 * unraid-backup-tools — Frontend Application
 * Author:  Jon C
 * Arch:    Explicit state machine, vanilla JS, no jQuery, no hidden side effects
 *
 * State machine:
 *   IDLE      → RUN_REQUESTED → RUNNING → IDLE
 *   RUNNING   → STOP_REQUESTED → STOPPING → IDLE
 *
 * All DOM mutations are driven by state transitions.
 * No implicit DOM state — everything reads from UBT.state.
 */

'use strict';

const UBT = (() => {

  // ── State ────────────────────────────────────────────────────────────────
  const state = {
    currentTool: 'flash-local',
    runState: 'IDLE',    // IDLE | RUNNING | STOPPING
    logInterval: null,
    logScrollLock: true,
    pendingRestoreMode: false,
    lastLogLine: 0,
  };

  // ── API endpoints (relative — same origin) ────────────────────────────────
  const API = {
    run:        '/plugins/unraid-backup-tools/api/run.php',
    stop:       '/plugins/unraid-backup-tools/api/stop.php',
    status:     '/plugins/unraid-backup-tools/api/status.php',
    log:        '/plugins/unraid-backup-tools/api/log.php',
    sshTest:    '/plugins/unraid-backup-tools/api/ssh-test.php',
    logList:    '/plugins/unraid-backup-tools/api/log-list.php',
    savePlugin: '/plugins/unraid-backup-tools/api/save-plugin.php',
  };

  // ── DOM refs (lazily cached) ──────────────────────────────────────────────
  const el = {
    toolSelect:     () => document.getElementById('ubt-tool-select'),
    panels:         () => document.querySelectorAll('.ubt-panel'),
    runningBadge:   () => document.getElementById('ubt-running-badge'),
    logArea:        () => document.getElementById('ubt-log-area'),
    logFileSelect:  () => document.getElementById('ubt-log-file-select'),
    logRefresh:     () => document.getElementById('ubt-log-refresh'),
    logClear:       () => document.getElementById('ubt-log-clear'),
    confirmModal:   () => document.getElementById('ubt-confirm-modal'),
    modalConfirm:   () => document.getElementById('modal-confirm'),
    modalCancel:    () => document.getElementById('modal-cancel'),
    modalBody:      () => document.getElementById('modal-body'),
    sshTestResult:  () => document.getElementById('ssh-test-result'),
    stopBtns:       () => document.querySelectorAll('.ubt-btn--stop'),
    runBtns:        () => document.querySelectorAll('.ubt-btn--run'),
    toast:          () => document.getElementById('ubt-toast'),
  };

  // ── Tool → panel map ──────────────────────────────────────────────────────
  const TOOL_PANELS = {
    'flash-local':  'panel-flash-local',
    'flash-remote': 'panel-flash-remote',
    'vm-backup':    'panel-vm-backup',
    'vm-restore':   'panel-vm-restore',
  };

  // ── State machine transition ──────────────────────────────────────────────
  function setRunState(newState) {
    state.runState = newState;
    applyRunStateToDom();
  }

  function applyRunStateToDom() {
    const isRunning = state.runState !== 'IDLE';
    const isStopping = state.runState === 'STOPPING';

    el.runBtns().forEach(btn => {
      btn.disabled = isRunning;
    });

    el.stopBtns().forEach(btn => {
      btn.disabled = !isRunning || isStopping;
      btn.textContent = isStopping ? 'Stopping…' : 'Stop';
    });

    const badge = el.runningBadge();
    if (!badge) return;
    if (isRunning) {
      badge.className = 'ubt-badge ubt-badge--running';
      badge.textContent = 'Running: ' + (state.currentTool || '');
    } else {
      badge.className = 'ubt-badge ubt-badge--idle';
      badge.textContent = 'Idle';
    }
  }

  // ── Panel switching ───────────────────────────────────────────────────────
  function switchPanel(tool) {
    if (!TOOL_PANELS[tool]) {
      console.error('UBT: unknown tool', tool);
      return;
    }
    state.currentTool = tool;

    el.panels().forEach(panel => {
      panel.classList.remove('ubt-panel--active');
    });

    const targetId = TOOL_PANELS[tool];
    const targetPanel = document.getElementById(targetId);
    if (targetPanel) {
      targetPanel.classList.add('ubt-panel--active');
    }

    persistToolSelection(tool);
    loadLogList(tool);
  }

  // ── Persist selected tool via AJAX (writes which-plugin.cfg) ─────────────
  function persistToolSelection(tool) {
    const body = new URLSearchParams({ tool });
    fetch(API.savePlugin, { method: 'POST', body })
      .then(r => r.json())
      .catch(() => {}); // Non-critical — silently ignore
  }

  // ── Run backup ────────────────────────────────────────────────────────────
  function triggerRun(mode) {
    if (state.runState !== 'IDLE') {
      showToast('A backup is already running.', 'err');
      return;
    }

    // VM Restore with overwrite checked → show confirmation modal
    if (mode === 'vm-restore') {
      const overwrite = document.querySelector('input[name="vm_restore_overwrite"]');
      if (overwrite && overwrite.checked) {
        showRestoreModal(() => executeRun(mode));
        return;
      }
    }

    executeRun(mode);
  }

  function executeRun(mode) {
    setRunState('RUNNING');
    state.lastLogLine = 0;
    clearLog();
    appendLog('Starting: ' + mode + ' …', 'info');
    startLogStream();

    const body = new URLSearchParams({ mode });
    fetch(API.run, { method: 'POST', body })
      .then(r => r.json())
      .then(data => {
        if (data.error) {
          appendLog('ERROR: ' + data.error, 'error');
          setRunState('IDLE');
          stopLogStream();
        }
        // Otherwise the run.php launches backup.sh async;
        // status polling and log streaming handle the rest
      })
      .catch(err => {
        appendLog('Request failed: ' + err.message, 'error');
        setRunState('IDLE');
        stopLogStream();
      });
  }

  // ── Stop ──────────────────────────────────────────────────────────────────
  function triggerStop() {
    if (state.runState !== 'RUNNING') return;
    setRunState('STOPPING');
    appendLog('Sending stop signal…', 'warn');

    fetch(API.stop, { method: 'POST' })
      .then(r => r.json())
      .then(() => {
        // Status poll will detect IDLE after the process exits
      })
      .catch(err => {
        appendLog('Stop request failed: ' + err.message, 'error');
      });
  }

  // ── Status polling (detects run completion) ───────────────────────────────
  function startStatusPoll() {
    const poller = setInterval(() => {
      if (state.runState === 'IDLE') {
        clearInterval(poller);
        return;
      }
      fetch(API.status)
        .then(r => r.json())
        .then(data => {
          if (!data.running) {
            appendLog('Backup process ended.', 'ok');
            setRunState('IDLE');
            stopLogStream();
            clearInterval(poller);
            loadLogList(state.currentTool);
          }
        })
        .catch(() => {});
    }, 2000);
  }

  // ── Log streaming ─────────────────────────────────────────────────────────
  function startLogStream() {
    startStatusPoll();
    state.logInterval = setInterval(fetchLiveLog, 1500);
  }

  function stopLogStream() {
    if (state.logInterval) {
      clearInterval(state.logInterval);
      state.logInterval = null;
    }
    // Final fetch to catch last lines
    fetchLiveLog();
  }

  function fetchLiveLog() {
    const params = new URLSearchParams({ offset: state.lastLogLine });
    fetch(API.log + '?' + params)
      .then(r => r.json())
      .then(data => {
        if (!data.lines || data.lines.length === 0) return;
        data.lines.forEach(line => appendLog(line, classifyLogLine(line)));
        state.lastLogLine = data.next_offset || state.lastLogLine;
      })
      .catch(() => {});
  }

  function fetchArchivedLog(filepath) {
    clearLog();
    const params = new URLSearchParams({ file: filepath, offset: 0 });
    fetch(API.log + '?' + params)
      .then(r => r.json())
      .then(data => {
        if (data.lines) {
          data.lines.forEach(line => appendLog(line, classifyLogLine(line)));
        }
      })
      .catch(() => {});
  }

  function classifyLogLine(line) {
    const l = line.toLowerCase();
    if (l.includes('[error]') || l.includes('error:'))   return 'error';
    if (l.includes('[warn]')  || l.includes('warning:')) return 'warn';
    if (l.includes('success') || l.includes('complete')) return 'ok';
    if (l.includes('[dry]'))                              return 'dry';
    return 'info';
  }

  function appendLog(text, type) {
    const area = el.logArea();
    if (!area) return;
    const span = document.createElement('span');
    if (type && type !== 'info') span.className = 'log--' + type;
    span.textContent = text + '\n';
    area.appendChild(span);
    if (state.logScrollLock) {
      area.scrollTop = area.scrollHeight;
    }
  }

  function clearLog() {
    const area = el.logArea();
    if (area) area.textContent = '';
  }

  // ── Load log file list ────────────────────────────────────────────────────
  function loadLogList(tool) {
    const sel = el.logFileSelect();
    if (!sel) return;

    const params = new URLSearchParams({ tool });
    fetch(API.logList + '?' + params)
      .then(r => r.json())
      .then(data => {
        // Clear existing options beyond the "live" placeholder
        while (sel.options.length > 1) sel.remove(1);
        if (data.files && data.files.length > 0) {
          data.files.forEach(f => {
            const opt = document.createElement('option');
            opt.value = f.path;
            opt.textContent = f.label;
            sel.appendChild(opt);
          });
        }
      })
      .catch(() => {});
  }

  // ── SSH connection test ───────────────────────────────────────────────────
  function triggerSSHTest() {
    const host   = document.getElementById('flash_remote_host')?.value?.trim()   || '';
    const user   = document.getElementById('flash_remote_user')?.value?.trim()   || 'root';
    const key    = document.getElementById('flash_remote_key')?.value?.trim()    || '';
    const result = el.sshTestResult();

    if (!host) {
      if (result) { result.className = 'ubt-test-result ubt-test-result--err'; result.textContent = 'Remote host is required.'; }
      return;
    }

    if (result) { result.className = 'ubt-test-result'; result.textContent = 'Testing…'; }

    const body = new URLSearchParams({ host, user, key });
    fetch(API.sshTest, { method: 'POST', body })
      .then(r => r.json())
      .then(data => {
        if (!result) return;
        if (data.ok) {
          result.className = 'ubt-test-result ubt-test-result--ok';
          result.textContent = '✓ Connection successful to ' + user + '@' + host;
        } else {
          result.className = 'ubt-test-result ubt-test-result--err';
          result.textContent = '✗ ' + (data.error || 'Connection failed');
        }
      })
      .catch(err => {
        if (result) {
          result.className = 'ubt-test-result ubt-test-result--err';
          result.textContent = 'Request error: ' + err.message;
        }
      });
  }

  // ── Restore modal ─────────────────────────────────────────────────────────
  function showRestoreModal(onConfirm) {
    const overlay = el.confirmModal();
    if (!overlay) { onConfirm(); return; }

    overlay.classList.add('ubt-modal-overlay--visible');
    overlay.setAttribute('aria-hidden', 'false');

    const confirmBtn = el.modalConfirm();
    const cancelBtn  = el.modalCancel();

    function cleanup() {
      overlay.classList.remove('ubt-modal-overlay--visible');
      overlay.setAttribute('aria-hidden', 'true');
      confirmBtn?.removeEventListener('click', handleConfirm);
      cancelBtn?.removeEventListener('click', handleCancel);
    }

    function handleConfirm() { cleanup(); onConfirm(); }
    function handleCancel()  { cleanup(); }

    confirmBtn?.addEventListener('click', handleConfirm);
    cancelBtn?.addEventListener('click', handleCancel);

    // Close on overlay click (outside modal)
    overlay.addEventListener('click', (e) => {
      if (e.target === overlay) handleCancel();
    }, { once: true });
  }

  // ── Toast ─────────────────────────────────────────────────────────────────
  function showToast(msg, type) {
    const existing = el.toast();
    const toast = existing || document.createElement('div');
    toast.id = 'ubt-toast';
    toast.className = 'ubt-toast ubt-toast--' + (type === 'err' ? 'err' : 'ok');
    toast.textContent = msg;
    if (!existing) {
      document.querySelector('.ubt-main')?.prepend(toast);
    }
    toast.style.display = 'block';
    clearTimeout(toast._timeout);
    toast._timeout = setTimeout(() => { toast.style.display = 'none'; }, 5000);
  }

  // ── Form save feedback ────────────────────────────────────────────────────
  function attachFormFeedback(formId, successMsg) {
    const form = document.getElementById(formId);
    if (!form) return;
    form.addEventListener('submit', () => {
      // Let the native POST happen; PHP returns the page with the toast.
      // This is intentional: forms use standard POST for config persistence.
    });
  }

  // ── Init ──────────────────────────────────────────────────────────────────
  function init() {
    // ── Tool selector ─────────────────────────────────────────────────────
    const toolSel = el.toolSelect();
    if (toolSel) {
      // Show panel for whatever tool is currently selected (set by PHP)
      switchPanel(toolSel.value);

      toolSel.addEventListener('change', () => {
        switchPanel(toolSel.value);
      });
    }

    // ── Run buttons ───────────────────────────────────────────────────────
    document.querySelectorAll('[data-mode]').forEach(btn => {
      btn.addEventListener('click', () => {
        triggerRun(btn.dataset.mode);
      });
    });

    // ── Stop buttons ──────────────────────────────────────────────────────
    el.stopBtns().forEach(btn => {
      btn.addEventListener('click', triggerStop);
    });

    // ── SSH test ──────────────────────────────────────────────────────────
    const sshBtn = document.getElementById('test-ssh-btn');
    if (sshBtn) {
      sshBtn.addEventListener('click', triggerSSHTest);
    }

    // ── Log file selector ─────────────────────────────────────────────────
    const logFileSel = el.logFileSelect();
    if (logFileSel) {
      logFileSel.addEventListener('change', () => {
        const val = logFileSel.value;
        if (val === 'live') {
          clearLog();
          appendLog('Idle — run a backup to see output here.', 'info');
        } else {
          fetchArchivedLog(val);
        }
      });
    }

    // ── Log controls ──────────────────────────────────────────────────────
    el.logRefresh()?.addEventListener('click', () => {
      const sel = el.logFileSelect();
      if (sel && sel.value !== 'live') {
        fetchArchivedLog(sel.value);
      }
    });

    el.logClear()?.addEventListener('click', clearLog);

    // ── Log scroll lock (disable auto-scroll if user scrolls up) ─────────
    el.logArea()?.addEventListener('scroll', function () {
      const area = this;
      state.logScrollLock = area.scrollTop + area.clientHeight >= area.scrollHeight - 20;
    });

    // ── Check for an already-running process on page load ─────────────────
    checkInitialStatus();
  }

  function checkInitialStatus() {
    fetch(API.status)
      .then(r => r.json())
      .then(data => {
        if (data.running) {
          state.currentTool = data.mode || state.currentTool;
          setRunState('RUNNING');
          startLogStream();
          appendLog('Resuming log for running backup: ' + data.mode, 'info');
        }
      })
      .catch(() => {});
  }

  // ── Public surface ────────────────────────────────────────────────────────
  return { init, state };

})();

document.addEventListener('DOMContentLoaded', UBT.init);
