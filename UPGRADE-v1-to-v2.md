# Upgrade from HomeLab Griller v1 to v2

v2 is a major release. **Back up the original SQLite database and test on a copy.**

## Before upgrading

1. Confirm your current host data directory is mounted at `/var/www/html/data`.
2. Stop the old container: `docker compose down`.
3. Backup the directory: `cp -a data data-backup-before-v2`.
4. Save your old project source in a Git tag/branch (e.g. `legacy-v1`).

## Upgrade using Docker

1. Replace the Compose file with the v2 example from `README.md` (port `8091:80`).
2. Remove `GRILLER_ADMIN_PASSWORD`; v2 uses first-run setup and hashes stored in SQLite.
3. Run `docker compose pull && docker compose up -d`.
4. Retrieve the one-time token with `docker compose logs --tail=30 homelab-griller`.
5. Open the web UI and set up your admin account.

Events, products, categories and order history stay in the database. Reusable ingredients and settings are migrated on application startup.

## Proxmox

The new installer is for **fresh installations only**. Existing v1 LXC users should back up `/opt/homelab-griller/data` and run the Proxmox updater or update Docker Compose inside their existing LXC. Do not run a fresh installer on top of a current CT.

## Update ZIP

Update ZIPs are source-code-only deltas, never an SQLite dump. **Do not overwrite or delete `data/`.**

If a previous version includes untracked files, review deletions separately; the release archive does not remove files automatically.
