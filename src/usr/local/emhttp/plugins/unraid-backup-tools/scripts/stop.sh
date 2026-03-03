#!/bin/bash
# =============================================================================
# unraid-backup-tools — Stop Handler
# Author: Jon C
#
# Writes the stop flag so the running backup.sh main loop detects it and
# exits cleanly on its next check_stop() call.
# =============================================================================

set -uo pipefail

PLUGIN="unraid-backup-tools"
LOCK_FILE="/tmp/${PLUGIN}.lock"
STOP_FLAG="/tmp/${PLUGIN}.stop"
LOG_DIR="/boot/logs/${PLUGIN}"

ts() { date '+%Y-%m-%d %H:%M:%S'; }

log() {
    local level="$1"
    local msg="$2"
    echo "[$(ts)] [${level}] ${msg}"
}

# ── Check if anything is running ─────────────────────────────────────────────
if [[ ! -f "${LOCK_FILE}" ]]; then
    log "INFO" "No active backup process detected (no lock file found)"
    exit 0
fi

RUNNING_MODE=$(cat "${LOCK_FILE}" 2>/dev/null || echo "unknown")
log "INFO" "Active backup mode: ${RUNNING_MODE}"
log "INFO" "Writing stop flag: ${STOP_FLAG}"

touch "${STOP_FLAG}"

# ── Wait for process to acknowledge ──────────────────────────────────────────
log "INFO" "Waiting for backup process to stop (max 30s)..."
WAITED=0
while [[ -f "${LOCK_FILE}" ]]; do
    if (( WAITED >= 30 )); then
        log "WARN" "Backup did not stop within 30s — stop flag remains in place"
        exit 1
    fi
    sleep 1
    (( WAITED++ ))
done

log "INFO" "Backup process stopped cleanly"

# Clean up any orphaned flag
rm -f "${STOP_FLAG}"

exit 0
