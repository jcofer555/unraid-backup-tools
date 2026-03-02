# Unraid Backup Tools

An Unraid plugin that acts as a single unified entry point for:
- **Flash Backup** — backs up the Unraid flash drive locally and to remote (rclone) storage
- **VM Backup & Restore** — backs up and restores Unraid virtual machines

## How It Works

A **Plugin** dropdown at the top of the page lets you switch between Flash Backup and VM Backup & Restore. Your selection is saved to `/boot/config/plugins/unraid-backup-tools/which-plugin.cfg` and the page reloads to display the full UI for that plugin — including all fields, scheduling table, and log.

## Directory Layout

```
/usr/local/emhttp/plugins/unraid-backup-tools/
  unraid-backup-tools.page            # Main plugin page with selector
  icon.png
  helpers/
    save_which_plugin.php            # Saves plugin selection to cfg
  flash-backup/
    flash-backup-content.php         # Full Flash Backup UI (PHP include)
    helpers/                         # All Flash Backup helpers (path-adjusted)
  vm-backup-and-restore/
    vm-backup-and-restore-content.php  # Full VM Backup & Restore UI (PHP include)
    helpers/                           # All VM Backup & Restore helpers (path-adjusted)

/boot/config/plugins/unraid-backup-tools/
  which-plugin.cfg                   # Persists the active plugin selection
  flash-backup/
    settings.cfg
    settings_remote.cfg
  vm-backup-and-restore/
    settings.cfg
    settings_restore.cfg
```

## Path Adjustments

All helper files have been updated so:
- Config paths like `/boot/config/plugins/flash-backup/` become `/boot/config/plugins/unraid-backup-tools/flash-backup/`
- AJAX paths like `/plugins/flash-backup/helpers/` become `/plugins/unraid-backup-tools/flash-backup/helpers/`
- Log temp paths like `/tmp/flash-backup/` become `/tmp/unraid-backup-tools/flash-backup/`

The same applies to the VM Backup & Restore sub-plugin.

## Built With

- Unified Jonathan Weighted Skill (deterministic-first architecture)
- PHP, Bash, HTML, CSS, JavaScript — no frameworks, no hidden state
