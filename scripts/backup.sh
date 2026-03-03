#!/bin/bash
# =============================================================================
# unraid-backup-tools — Unified Backup Script
# Author:  Jon C
# Arch:    Deterministic, atomic, auditable (unified-jonathan-weighted-skill)
#
# Usage:
#   backup.sh --mode <MODE> [OPTIONS]
#
# Modes:
#   flash-local    Back up flash drive to a local path
#   flash-remote   Sync flash drive to a remote host via rsync/SSH
#   vm-backup      Back up one or more VMs (stop/snapshot/live)
#   vm-restore     Restore a VM from a backup archive
#
# Common flags:
#   --dry-run      Simulate all operations; no writes
#   --config PATH  Override default config file
# =============================================================================

set -uo pipefail
# Note: set -e is intentionally omitted — individual functions manage exit
# codes explicitly to allow structured cleanup without early exit trapping
# all non-fatal conditions (e.g. rsync exit code 24 = vanished source file).

# ── Plugin identity ───────────────────────────────────────────────────────────
PLUGIN="unraid-backup-tools"
VERSION="2025.01.01"

# ── Paths ─────────────────────────────────────────────────────────────────────
CONFIG_BASE="/boot/config/plugins/${PLUGIN}"
FLASH_CFG="${CONFIG_BASE}/flash-backup/flash-backup.cfg"
VM_CFG="${CONFIG_BASE}/vm-backup-and-restore/vm-backup.cfg"
LOG_DIR="/boot/logs/${PLUGIN}"
LOCK_FILE="/tmp/${PLUGIN}.lock"
STOP_FLAG="/tmp/${PLUGIN}.stop"
LOG_RETAIN=10        # Maximum number of log files to keep per mode

# ── Runtime state ─────────────────────────────────────────────────────────────
MODE=""
DRY_RUN="no"
CONFIG_OVERRIDE=""
LOG_FILE=""
RUN_START=""

# =============================================================================
# LOGGING
# =============================================================================

# log_init MODE
#   Opens a timestamped log file for the given mode.
log_init() {
    local mode="$1"
    mkdir -p "${LOG_DIR}"
    local ts; ts=$(date '+%Y%m%d_%H%M%S')
    LOG_FILE="${LOG_DIR}/${mode}_${ts}.log"
    touch "${LOG_FILE}"
    RUN_START=$(date '+%s')
    log "INFO" "============================================================"
    log "INFO" "${PLUGIN} v${VERSION}"
    log "INFO" "Mode    : ${mode}"
    log "INFO" "Started : $(date '+%Y-%m-%d %H:%M:%S')"
    [[ "${DRY_RUN}" == "yes" ]] && log "INFO" "DRY RUN : enabled — no files will be written"
    log "INFO" "============================================================"
}

# log LEVEL MESSAGE
#   Writes a timestamped, leveled entry to stdout and log file.
log() {
    local level="$1"
    local msg="$2"
    local ts; ts=$(date '+%Y-%m-%d %H:%M:%S')
    local line="[${ts}] [${level}] ${msg}"
    echo "${line}"
    [[ -n "${LOG_FILE}" ]] && echo "${line}" >> "${LOG_FILE}"
}

# log_mask KEY VALUE
#   Logs a config entry with sensitive values masked.
log_mask() {
    local key="$1"
    local val="$2"
    case "${key,,}" in
        *pass*|*secret*|*key*|*token*|*webhook*)
            log "INFO" "Config: ${key} = [MASKED]"
            ;;
        *)
            log "INFO" "Config: ${key} = ${val}"
            ;;
    esac
}

# log_summary EXIT_CODE
#   Writes a final duration + outcome summary.
log_summary() {
    local code="$1"
    local end; end=$(date '+%s')
    local dur=$(( end - RUN_START ))
    local hms; hms=$(printf '%02d:%02d:%02d' $((dur/3600)) $((dur%3600/60)) $((dur%60)))
    log "INFO" "============================================================"
    if [[ "${code}" -eq 0 ]]; then
        log "INFO" "Result   : SUCCESS"
    else
        log "ERROR" "Result   : FAILED (exit ${code})"
    fi
    log "INFO" "Duration : ${hms}"
    log "INFO" "Log      : ${LOG_FILE}"
    log "INFO" "============================================================"
}

# rotate_logs MODE
#   Keeps only the newest LOG_RETAIN log files for a given mode.
rotate_logs() {
    local mode="$1"
    local -a files
    mapfile -t files < <(ls -1t "${LOG_DIR}/${mode}_"*.log 2>/dev/null)
    local count="${#files[@]}"
    if (( count > LOG_RETAIN )); then
        local to_remove=(  "${files[@]:${LOG_RETAIN}}" )
        for f in "${to_remove[@]}"; do
            log "INFO" "Rotating log: $(basename "${f}")"
            rm -f "${f}"
        done
    fi
}

# =============================================================================
# LOCK / STOP / TRAP
# =============================================================================

acquire_lock() {
    if [[ -f "${LOCK_FILE}" ]]; then
        log "ERROR" "Lock file exists: ${LOCK_FILE}"
        log "ERROR" "Another backup may be running. Aborting."
        exit 2
    fi
    echo "${MODE}" > "${LOCK_FILE}"
    log "INFO" "Lock acquired: ${LOCK_FILE}"
}

release_lock() {
    rm -f "${LOCK_FILE}"
    rm -f "${STOP_FLAG}"
    log "INFO" "Lock and stop flag released"
}

check_stop() {
    if [[ -f "${STOP_FLAG}" ]]; then
        log "INFO" "Stop flag detected — aborting cleanly"
        return 1
    fi
    return 0
}

cleanup() {
    local code="${1:-1}"
    log_summary "${code}"
    rotate_logs "${MODE}"
    release_lock
    exit "${code}"
}

trap 'log "INFO" "Caught INT/TERM — stopping"; cleanup 130' INT TERM

# =============================================================================
# CONFIG PARSING
# =============================================================================

# parse_cfg PATH VAR_PREFIX
#   Sources a KEY=VALUE config file into the current shell with key names
#   prefixed by VAR_PREFIX to avoid namespace collisions.
#   NOTE: Values are re-exported in UBT_* namespace.
declare -A CFG

parse_cfg() {
    local path="$1"
    if [[ ! -f "${path}" ]]; then
        log "WARN" "Config not found: ${path}"
        return 0
    fi
    while IFS='=' read -r key val; do
        key="${key//[[:space:]]/}"
        val="${val//[[:space:]]/}"
        [[ -z "${key}" || "${key}" == \#* ]] && continue
        CFG["${key}"]="${val}"
        log_mask "${key}" "${val}"
    done < "${path}"
}

cfg() {
    echo "${CFG[${1}]:-${2:-}}"
}

# =============================================================================
# VALIDATION HELPERS
# =============================================================================

validate_path() {
    local label="$1"
    local path="$2"
    if [[ -z "${path}" ]]; then
        log "ERROR" "Validation failed: ${label} is empty"
        return 1
    fi
    # Prevent path traversal
    local real; real=$(realpath -m "${path}" 2>/dev/null || echo "")
    if [[ -z "${real}" ]]; then
        log "ERROR" "Validation failed: ${label} '${path}' cannot be resolved"
        return 1
    fi
    log "INFO" "Path validated: ${label} = ${real}"
    echo "${real}"
}

validate_dir_writable() {
    local label="$1"
    local path="$2"
    if [[ "${DRY_RUN}" == "yes" ]]; then
        log "INFO" "[DRY] Skipping writable check for ${label}: ${path}"
        return 0
    fi
    if [[ ! -d "${path}" ]]; then
        mkdir -p "${path}" || { log "ERROR" "Cannot create directory: ${path}"; return 1; }
    fi
    if [[ ! -w "${path}" ]]; then
        log "ERROR" "Directory not writable: ${path}"
        return 1
    fi
}

check_disk_space() {
    local dest="$1"
    local required_kb="${2:-512000}"  # default 500 MB
    local dest_dir="${dest}"
    [[ ! -d "${dest_dir}" ]] && dest_dir=$(dirname "${dest_dir}")
    local avail_kb; avail_kb=$(df -k "${dest_dir}" 2>/dev/null | awk 'NR==2{print $4}')
    if [[ -z "${avail_kb}" ]] || (( avail_kb < required_kb )); then
        log "ERROR" "Insufficient disk space at ${dest_dir}: ${avail_kb}KB available, ${required_kb}KB required"
        return 1
    fi
    log "INFO" "Disk space OK at ${dest_dir}: ${avail_kb}KB available"
}

validate_ssh() {
    local host="$1"
    local user="$2"
    local key="$3"
    log "INFO" "Testing SSH connectivity to ${user}@${host} ..."
    if [[ "${DRY_RUN}" == "yes" ]]; then
        log "INFO" "[DRY] Skipping SSH test"
        return 0
    fi
    local opts=(-o StrictHostKeyChecking=no -o ConnectTimeout=10 -o BatchMode=yes)
    [[ -n "${key}" ]] && opts+=(-i "${key}")
    if ! ssh "${opts[@]}" "${user}@${host}" "echo ubt-ok" 2>&1 | grep -q "ubt-ok"; then
        log "ERROR" "SSH test failed to ${user}@${host}"
        return 1
    fi
    log "INFO" "SSH connectivity confirmed"
}

# =============================================================================
# RETENTION PRUNING
# =============================================================================

# prune_local_retention DEST_DIR PATTERN KEEP_COUNT
#   Removes oldest backups beyond KEEP_COUNT.
#   Never removes the newest verified backup.
prune_local_retention() {
    local dest="$1"
    local pattern="$2"
    local keep="${3:-5}"

    log "INFO" "Pruning: keeping newest ${keep} backups matching '${pattern}' in ${dest}"

    local -a entries
    mapfile -t entries < <(ls -1dt "${dest}"/${pattern} 2>/dev/null)
    local count="${#entries[@]}"

    if (( count <= keep )); then
        log "INFO" "Prune skipped: only ${count} backup(s) present (<= ${keep})"
        return 0
    fi

    # The newest backup (index 0) is always protected
    local newest="${entries[0]}"
    log "INFO" "Newest backup protected: $(basename "${newest}")"

    local -a to_remove=( "${entries[@]:${keep}}" )
    for entry in "${to_remove[@]}"; do
        if [[ "${entry}" == "${newest}" ]]; then
            log "WARN" "Prune skipped newest backup — retention logic protected it"
            continue
        fi
        if [[ "${DRY_RUN}" == "yes" ]]; then
            log "INFO" "[DRY] Would prune: $(basename "${entry}")"
        else
            log "INFO" "Pruning: $(basename "${entry}")"
            rm -rf "${entry}"
        fi
    done
}

# =============================================================================
# DISCORD NOTIFICATION
# =============================================================================

notify_discord() {
    local webhook="$1"
    local status="$2"
    local message="$3"
    [[ -z "${webhook}" ]] && return 0
    local payload
    payload=$(printf '{"content": "**%s** — %s\\n%s"}' "${PLUGIN}" "${status}" "${message}")
    if [[ "${DRY_RUN}" == "yes" ]]; then
        log "INFO" "[DRY] Discord notify skipped"
        return 0
    fi
    curl -s -X POST -H "Content-Type: application/json" -d "${payload}" "${webhook}" &>/dev/null || true
    log "INFO" "Discord notification sent (${status})"
}

# =============================================================================
# MODE: flash-local
# =============================================================================

run_flash_local() {
    log "INFO" "--- Flash Backup: Local ---"
    parse_cfg "${FLASH_CFG}"

    local dest;    dest=$(validate_path "FLASH_LOCAL_DEST"    "$(cfg FLASH_LOCAL_DEST)")    || return 1
    local retain;  retain=$(cfg FLASH_LOCAL_RETENTION "5")
    local compress; compress=$(cfg FLASH_LOCAL_COMPRESS "yes")
    local include;  include=$(cfg FLASH_LOCAL_INCLUDE "")
    local exclude;  exclude=$(cfg FLASH_LOCAL_EXCLUDE "")
    local discord;  discord=$(cfg FLASH_LOCAL_DISCORD "")

    check_stop || return 130
    check_disk_space "${dest}" 512000 || return 1
    validate_dir_writable "Destination" "${dest}" || return 1

    local ts; ts=$(date '+%Y%m%d_%H%M%S')
    local tmp_dir; tmp_dir=$(mktemp -d "${dest}/.ubt_tmp_XXXXXX")
    local archive_name="flash_${ts}"
    local archive_path="${dest}/${archive_name}"

    log "INFO" "Temporary staging: ${tmp_dir}"

    # Build rsync exclude args
    local -a rsync_excludes=( --exclude='*.lock' --exclude='lost+found' )
    if [[ -n "${exclude}" ]]; then
        IFS=',' read -ra exc_list <<< "${exclude}"
        for exc in "${exc_list[@]}"; do
            exc="${exc// /}"
            [[ -n "${exc}" ]] && rsync_excludes+=( --exclude="${exc}" )
        done
    fi

    # Build rsync include args
    local -a rsync_includes=()
    if [[ -n "${include}" ]]; then
        IFS=',' read -ra inc_list <<< "${include}"
        for inc in "${inc_list[@]}"; do
            inc="${inc// /}"
            [[ -n "${inc}" ]] && rsync_includes+=( --include="${inc}" )
        done
    fi

    local dry_flag=()
    [[ "${DRY_RUN}" == "yes" ]] && dry_flag=( --dry-run )

    check_stop || { rm -rf "${tmp_dir}"; return 130; }

    log "INFO" "Copying flash drive to staging..."
    rsync -aAX "${dry_flag[@]}" "${rsync_includes[@]}" "${rsync_excludes[@]}" \
          /boot/ "${tmp_dir}/" 2>&1 | while IFS= read -r line; do
        log "INFO" "  rsync: ${line}"
    done

    check_stop || { rm -rf "${tmp_dir}"; return 130; }

    if [[ "${compress}" == "yes" && "${DRY_RUN}" != "yes" ]]; then
        log "INFO" "Compressing to ${archive_name}.tar.gz ..."
        tar -czf "${archive_path}.tar.gz" -C "${dest}" "$(basename "${tmp_dir}")" 2>&1 | \
            while IFS= read -r line; do log "INFO" "  tar: ${line}"; done
        rm -rf "${tmp_dir}"
        local size; size=$(du -sh "${archive_path}.tar.gz" | cut -f1)
        log "INFO" "Archive created: ${archive_path}.tar.gz (${size})"
    elif [[ "${DRY_RUN}" != "yes" ]]; then
        mv "${tmp_dir}" "${archive_path}"
        local size; size=$(du -sh "${archive_path}" | cut -f1)
        log "INFO" "Backup directory created: ${archive_path} (${size})"
    else
        log "INFO" "[DRY] Would create archive/directory at ${archive_path}"
        rm -rf "${tmp_dir}"
    fi

    check_stop || return 130

    prune_local_retention "${dest}" "flash_*" "${retain}"

    notify_discord "${discord}" "SUCCESS" "Flash local backup completed — ${ts}"
    log "INFO" "Flash local backup complete"
    return 0
}

# =============================================================================
# MODE: flash-remote
# =============================================================================

run_flash_remote() {
    log "INFO" "--- Flash Backup: Remote ---"
    parse_cfg "${FLASH_CFG}"

    local host;    host=$(cfg FLASH_REMOTE_HOST "")
    local user;    user=$(cfg FLASH_REMOTE_USER "root")
    local key;     key=$(cfg FLASH_REMOTE_KEY "")
    local rdest;   rdest=$(cfg FLASH_REMOTE_DEST "")
    local bwlimit; bwlimit=$(cfg FLASH_REMOTE_BW_LIMIT "0")
    local retain;  retain=$(cfg FLASH_REMOTE_RETENTION "5")

    if [[ -z "${host}" || -z "${rdest}" ]]; then
        log "ERROR" "Remote host and destination path are required"
        return 1
    fi

    validate_ssh "${host}" "${user}" "${key}" || return 1
    check_stop || return 130

    local ssh_opts="-o StrictHostKeyChecking=no -o BatchMode=yes"
    [[ -n "${key}" ]] && ssh_opts="${ssh_opts} -i ${key}"

    local dry_flag=()
    [[ "${DRY_RUN}" == "yes" ]] && dry_flag=( --dry-run )

    local bw_flag=()
    (( bwlimit > 0 )) && bw_flag=( --bwlimit="${bwlimit}" )

    log "INFO" "Syncing /boot/ to ${user}@${host}:${rdest} ..."
    rsync -aAXz "${dry_flag[@]}" "${bw_flag[@]}" \
          -e "ssh ${ssh_opts}" \
          --exclude='*.lock' --exclude='lost+found' \
          /boot/ "${user}@${host}:${rdest}/" 2>&1 | while IFS= read -r line; do
        log "INFO" "  rsync: ${line}"
    done
    local rsync_rc="${PIPESTATUS[0]}"

    # rsync exit 24 = some files vanished during transfer — non-fatal
    if [[ "${rsync_rc}" -ne 0 && "${rsync_rc}" -ne 24 ]]; then
        log "ERROR" "rsync failed with exit code ${rsync_rc}"
        return "${rsync_rc}"
    fi

    check_stop || return 130

    # Remote retention pruning via SSH
    if [[ "${DRY_RUN}" != "yes" ]]; then
        log "INFO" "Remote retention pruning on ${host}:${rdest} (keeping ${retain})..."
        # shellcheck disable=SC2029
        ssh -o StrictHostKeyChecking=no -o BatchMode=yes \
            ${key:+-i "${key}"} "${user}@${host}" \
            "ls -1dt '${rdest}'/flash_* 2>/dev/null | tail -n +$((retain+1)) | xargs rm -rf --" \
            2>&1 | while IFS= read -r line; do log "INFO" "  ssh: ${line}"; done || true
    else
        log "INFO" "[DRY] Remote pruning skipped"
    fi

    log "INFO" "Flash remote backup complete"
    return 0
}

# =============================================================================
# MODE: vm-backup
# =============================================================================

run_vm_backup() {
    log "INFO" "--- VM Backup ---"
    parse_cfg "${VM_CFG}"

    local vms_raw; vms_raw=$(cfg VM_BACKUP_VMS "")
    local dest;    dest=$(validate_path "VM_BACKUP_DEST" "$(cfg VM_BACKUP_DEST)") || return 1
    local bmode;   bmode=$(cfg VM_BACKUP_MODE "stop")
    local compress; compress=$(cfg VM_BACKUP_COMPRESS "yes")
    local retain;  retain=$(cfg VM_BACKUP_RETENTION "5")
    local inc_xml; inc_xml=$(cfg VM_BACKUP_INCLUDE_LIBVIRT "yes")
    local autostart; autostart=$(cfg VM_BACKUP_AUTOSTART "no")

    if [[ -z "${vms_raw}" ]]; then
        log "ERROR" "No VMs selected for backup"
        return 1
    fi

    IFS=',' read -ra VM_LIST <<< "${vms_raw}"

    check_disk_space "${dest}" 2048000 || return 1
    validate_dir_writable "VM Destination" "${dest}" || return 1

    local ts; ts=$(date '+%Y%m%d_%H%M%S')

    for vm in "${VM_LIST[@]}"; do
        vm="${vm// /}"
        [[ -z "${vm}" ]] && continue
        check_stop || return 130

        log "INFO" "Backing up VM: ${vm} (mode=${bmode})"

        local vm_dest="${dest}/${vm}_${ts}"
        local tmp_vm_dest; tmp_vm_dest=$(mktemp -d "${dest}/.ubt_vm_XXXXXX")
        log "INFO" "Staging to: ${tmp_vm_dest}"

        # Get VM disk paths
        local -a disk_paths=()
        while IFS= read -r disk; do
            [[ -n "${disk}" ]] && disk_paths+=( "${disk}" )
        done < <(virsh domblklist "${vm}" --details 2>/dev/null | awk '/disk/{print $4}')

        if [[ ${#disk_paths[@]} -eq 0 ]]; then
            log "WARN" "No disk images found for VM: ${vm}"
        fi

        # Quiesce VM
        local was_running="no"
        local vm_state; vm_state=$(virsh domstate "${vm}" 2>/dev/null | tr -d '[:space:]')

        if [[ "${vm_state}" == "running" ]]; then
            was_running="yes"
            case "${bmode}" in
                stop)
                    log "INFO" "Shutting down VM: ${vm}"
                    [[ "${DRY_RUN}" != "yes" ]] && virsh shutdown "${vm}" 2>&1 | \
                        while IFS= read -r l; do log "INFO" "  virsh: ${l}"; done
                    # Wait for shutdown (max 120s)
                    local waited=0
                    while [[ "${DRY_RUN}" != "yes" ]]; do
                        local state; state=$(virsh domstate "${vm}" 2>/dev/null | tr -d '[:space:]')
                        [[ "${state}" == "shut off" ]] && break
                        (( waited >= 120 )) && { log "ERROR" "VM ${vm} did not shut down in 120s"; rm -rf "${tmp_vm_dest}"; return 1; }
                        sleep 2; (( waited += 2 ))
                    done
                    ;;
                snapshot)
                    log "INFO" "Creating snapshot for: ${vm}"
                    [[ "${DRY_RUN}" != "yes" ]] && virsh snapshot-create-as "${vm}" \
                        "ubt_snap_${ts}" "unraid-backup-tools snapshot" \
                        --disk-only --atomic 2>&1 | \
                        while IFS= read -r l; do log "INFO" "  virsh: ${l}"; done
                    ;;
                live)
                    log "WARN" "Live backup — potential inconsistency risk for VM: ${vm}"
                    ;;
            esac
        fi

        check_stop || { rm -rf "${tmp_vm_dest}"; return 130; }

        # Copy disk images
        for disk in "${disk_paths[@]}"; do
            if [[ ! -f "${disk}" ]]; then
                log "WARN" "Disk image not found: ${disk} — skipping"
                continue
            fi
            local dname; dname=$(basename "${disk}")
            log "INFO" "Copying disk: ${dname} ..."
            if [[ "${DRY_RUN}" == "yes" ]]; then
                log "INFO" "[DRY] Would copy: ${disk} -> ${tmp_vm_dest}/${dname}"
            else
                cp --sparse=always "${disk}" "${tmp_vm_dest}/${dname}" 2>&1 | \
                    while IFS= read -r l; do log "INFO" "  cp: ${l}"; done
            fi
        done

        # Export XML config
        if [[ "${inc_xml}" == "yes" ]]; then
            log "INFO" "Exporting libvirt XML for: ${vm}"
            if [[ "${DRY_RUN}" != "yes" ]]; then
                virsh dumpxml "${vm}" > "${tmp_vm_dest}/${vm}.xml" 2>&1 || \
                    log "WARN" "Could not export XML for ${vm}"
            else
                log "INFO" "[DRY] Would export XML to ${tmp_vm_dest}/${vm}.xml"
            fi
        fi

        # Restore VM state
        if [[ "${was_running}" == "yes" ]]; then
            case "${bmode}" in
                stop)
                    if [[ "${autostart}" == "yes" ]]; then
                        log "INFO" "Restarting VM: ${vm}"
                        [[ "${DRY_RUN}" != "yes" ]] && virsh start "${vm}" 2>&1 | \
                            while IFS= read -r l; do log "INFO" "  virsh: ${l}"; done
                    fi
                    ;;
                snapshot)
                    log "INFO" "Deleting snapshot for: ${vm}"
                    [[ "${DRY_RUN}" != "yes" ]] && virsh snapshot-delete "${vm}" \
                        "ubt_snap_${ts}" 2>&1 | \
                        while IFS= read -r l; do log "INFO" "  virsh: ${l}"; done || true
                    ;;
            esac
        fi

        # Compress or move staging dir
        if [[ "${compress}" == "yes" && "${DRY_RUN}" != "yes" ]]; then
            log "INFO" "Compressing ${vm} backup..."
            tar -czf "${vm_dest}.tar.gz" -C "${dest}" "$(basename "${tmp_vm_dest}")" 2>&1 | \
                while IFS= read -r l; do log "INFO" "  tar: ${l}"; done
            rm -rf "${tmp_vm_dest}"
            local size; size=$(du -sh "${vm_dest}.tar.gz" | cut -f1)
            log "INFO" "VM archive: ${vm_dest}.tar.gz (${size})"
        elif [[ "${DRY_RUN}" != "yes" ]]; then
            mv "${tmp_vm_dest}" "${vm_dest}"
            local size; size=$(du -sh "${vm_dest}" | cut -f1)
            log "INFO" "VM directory: ${vm_dest} (${size})"
        else
            log "INFO" "[DRY] Would finalize backup for ${vm}"
            rm -rf "${tmp_vm_dest}"
        fi

        prune_local_retention "${dest}" "${vm}_*" "${retain}"
        log "INFO" "VM backup complete: ${vm}"
    done

    log "INFO" "All VM backups complete"
    return 0
}

# =============================================================================
# MODE: vm-restore
# =============================================================================

run_vm_restore() {
    log "INFO" "--- VM Restore ---"
    parse_cfg "${VM_CFG}"

    local archive;   archive=$(validate_path "VM_RESTORE_FILE"  "$(cfg VM_RESTORE_FILE)")   || return 1
    local target;    target=$(cfg VM_RESTORE_TARGET "")
    local overwrite; overwrite=$(cfg VM_RESTORE_OVERWRITE "no")
    local do_disks;  do_disks=$(cfg VM_RESTORE_DISKS "yes")
    local do_config; do_config=$(cfg VM_RESTORE_CONFIG "yes")

    if [[ ! -f "${archive}" ]]; then
        log "ERROR" "Archive not found: ${archive}"
        return 1
    fi

    # Derive VM name from archive if not specified
    if [[ -z "${target}" ]]; then
        target=$(basename "${archive}" | sed 's/_[0-9]\{8\}_[0-9]\{6\}.*//')
        log "INFO" "Target VM name derived: ${target}"
    fi

    local vm_state; vm_state=$(virsh domstate "${target}" 2>/dev/null | tr -d '[:space:]')

    if [[ "${overwrite}" != "yes" && -n "${vm_state}" ]]; then
        log "ERROR" "VM '${target}' already exists. Set overwrite=yes to proceed."
        return 1
    fi

    if [[ "${vm_state}" == "running" ]]; then
        log "INFO" "Shutting down existing VM: ${target}"
        [[ "${DRY_RUN}" != "yes" ]] && virsh destroy "${target}" 2>&1 || true
    fi

    local tmp_restore; tmp_restore=$(mktemp -d /tmp/ubt_restore_XXXXXX)
    log "INFO" "Staging restore to: ${tmp_restore}"

    check_stop || { rm -rf "${tmp_restore}"; return 130; }

    if [[ "${DRY_RUN}" == "yes" ]]; then
        log "INFO" "[DRY] Would extract: ${archive} to ${tmp_restore}"
    else
        log "INFO" "Extracting archive: $(basename "${archive}") ..."
        tar -xzf "${archive}" -C "${tmp_restore}" 2>&1 | \
            while IFS= read -r l; do log "INFO" "  tar: ${l}"; done
    fi

    check_stop || { rm -rf "${tmp_restore}"; return 130; }

    # Restore XML
    if [[ "${do_config}" == "yes" ]]; then
        local xml_file; xml_file=$(find "${tmp_restore}" -name "*.xml" 2>/dev/null | head -1)
        if [[ -n "${xml_file}" && "${DRY_RUN}" != "yes" ]]; then
            log "INFO" "Restoring VM definition from: $(basename "${xml_file}")"
            virsh undefine "${target}" 2>/dev/null || true
            virsh define "${xml_file}" 2>&1 | while IFS= read -r l; do log "INFO" "  virsh: ${l}"; done
        elif [[ "${DRY_RUN}" == "yes" ]]; then
            log "INFO" "[DRY] Would restore XML config"
        else
            log "WARN" "No XML config found in archive"
        fi
    fi

    # Restore disks
    if [[ "${do_disks}" == "yes" ]]; then
        local -a disk_images
        mapfile -t disk_images < <(find "${tmp_restore}" -name "*.qcow2" -o -name "*.img" -o -name "*.raw" 2>/dev/null)
        if [[ ${#disk_images[@]} -eq 0 ]]; then
            log "WARN" "No disk images found in archive"
        fi
        for disk in "${disk_images[@]}"; do
            local dname; dname=$(basename "${disk}")
            # Determine destination from live VM config
            local vm_disk_path; vm_disk_path=$(virsh domblklist "${target}" --details 2>/dev/null | \
                awk '/disk/{print $4}' | head -1)
            if [[ -z "${vm_disk_path}" ]]; then
                vm_disk_path="/var/lib/libvirt/images/${dname}"
                log "WARN" "Could not determine disk path from VM — defaulting to ${vm_disk_path}"
            fi
            log "INFO" "Restoring disk: ${dname} -> ${vm_disk_path}"
            if [[ "${DRY_RUN}" != "yes" ]]; then
                cp --sparse=always "${disk}" "${vm_disk_path}" 2>&1 | \
                    while IFS= read -r l; do log "INFO" "  cp: ${l}"; done
            else
                log "INFO" "[DRY] Would copy disk to ${vm_disk_path}"
            fi
        done
    fi

    rm -rf "${tmp_restore}"
    log "INFO" "VM restore complete: ${target}"
    return 0
}

# =============================================================================
# ARGUMENT PARSING
# =============================================================================

parse_args() {
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --mode)
                MODE="$2"
                shift 2
                ;;
            --dry-run)
                DRY_RUN="yes"
                shift
                ;;
            --config)
                CONFIG_OVERRIDE="$2"
                shift 2
                ;;
            *)
                echo "Unknown argument: $1" >&2
                exit 64
                ;;
        esac
    done

    case "${MODE}" in
        flash-local|flash-remote|vm-backup|vm-restore)
            ;;
        "")
            echo "ERROR: --mode is required" >&2
            echo "Usage: backup.sh --mode <flash-local|flash-remote|vm-backup|vm-restore> [--dry-run]" >&2
            exit 64
            ;;
        *)
            echo "ERROR: Unknown mode '${MODE}'" >&2
            exit 64
            ;;
    esac

    # Override config path if requested
    if [[ -n "${CONFIG_OVERRIDE}" ]]; then
        FLASH_CFG="${CONFIG_OVERRIDE}"
        VM_CFG="${CONFIG_OVERRIDE}"
    fi
}

# =============================================================================
# MAIN
# =============================================================================

main() {
    parse_args "$@"
    log_init "${MODE}"
    acquire_lock

    local exit_code=0

    case "${MODE}" in
        flash-local)
            run_flash_local  || exit_code=$?
            ;;
        flash-remote)
            run_flash_remote || exit_code=$?
            ;;
        vm-backup)
            run_vm_backup    || exit_code=$?
            ;;
        vm-restore)
            run_vm_restore   || exit_code=$?
            ;;
    esac

    cleanup "${exit_code}"
}

main "$@"
