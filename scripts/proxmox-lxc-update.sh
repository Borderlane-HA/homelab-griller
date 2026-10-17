#!/usr/bin/env bash
set -Eeuo pipefail
if [[ $EUID -ne 0 ]] || ! command -v pct >/dev/null 2>&1; then
  echo 'Run this command as root on the Proxmox VE host.' >&2
  exit 1
fi
if [[ -z ${CTID:-} ]]; then
  read -r -p 'HomeLab Griller container ID [120]: ' CTID
  CTID=${CTID:-120}
fi
[[ $CTID =~ ^[0-9]+$ ]] || { echo 'Invalid container ID' >&2; exit 1; }
pct status "$CTID" | grep -q 'status: running' || { echo 'The container must be running.' >&2; exit 1; }
echo "Updating HomeLab Griller inside CT $CTID"
pct exec "$CTID" -- bash -s <<'UPGRADE'
set -Eeuo pipefail
cd /opt/homelab-griller
test -f docker-compose.yml || { echo 'HomeLab Griller compose file not found.' >&2; exit 1; }
mkdir -p backups
stamp=$(date +%Y%m%d-%H%M%S)
# Stop before copying SQLite so the backup is transactionally consistent.
docker compose down
tar -czf "backups/data-$stamp.tar.gz" -C /opt/homelab-griller data
if docker compose pull && docker compose up -d; then
  echo "Update complete. Backup: /opt/homelab-griller/backups/data-$stamp.tar.gz"
else
  echo 'Update failed. Data backup preserved. Examine docker compose logs.' >&2
  exit 1
fi
UPGRADE
