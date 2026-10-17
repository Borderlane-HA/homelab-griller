# Upgrade to HomeLab Griller v2

**Back up your existing SQLite database first, and test the upgrade on a copy.**

1. Save the source of your existing version with a Git tag or `legacy-v1` branch.
2. In your current Compose directory, run `docker compose down` and copy `./data` to a backup.
3. Replace the Compose configuration with the example in the new README. Keep the **same `./data` mount**.
4. v2 no longer uses `GRILLER_ADMIN_PASSWORD` for login. Remove this variable from Compose.
5. Run `docker compose pull && docker compose up -d`.
6. Open the application locally and create your admin username and password (minimum 6 characters). **There is no setup token.** Complete setup immediately, before giving others network access.

The application migrates existing SQLite tables/settings and seeds reusable ingredients without deleting previous events, products, guests or order history. Run this migration against a **copy of production data** first.

## Proxmox

The Proxmox installation script is for **new LXCs only**. For an existing LXC, run the update script after backing up `/opt/homelab-griller/data`; do **not** overwrite an existing CT.

## Update ZIP

Update ZIPs contain updated source files only, not SQLite data. They do not automatically delete old files. Docker/Proxmox image-based installations should **not** use source ZIPs for updates.
