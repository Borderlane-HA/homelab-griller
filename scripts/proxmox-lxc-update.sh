#!/usr/bin/env bash
# HomeLab Griller by Pengu - Proxmox LXC updater
# Run on the Proxmox VE host (never inside the guest LXC).
set -Eeuo pipefail

readonly APP_DIR='/opt/homelab-griller'
readonly COMPOSE_FILE="${APP_DIR}/docker-compose.yml"
readonly IMAGE_PREFIX='ghcr.io/borderlane-ha/homelab-griller'

fail() {
  printf 'ERROR: %s\n' "$*" >&2
  exit 1
}

[[ ${EUID} -eq 0 ]] || fail 'Run as root on the Proxmox VE host.'
command -v pct >/dev/null 2>&1 || fail 'pct not found. Run on a Proxmox VE host.'
pct list >/dev/null || fail 'Unable to list Proxmox containers.'

is_griller_ct() {
  local id=$1
  pct exec "$id" -- test -f "$COMPOSE_FILE" >/dev/null 2>&1 &&
    pct exec "$id" -- grep -Fq "$IMAGE_PREFIX" "$COMPOSE_FILE" >/dev/null 2>&1
}

printf '\nHomeLab Griller by Pengu - Proxmox LXC updater\n'
printf 'Searching running LXC containers for HomeLab Griller...\n\n'

mapfile -t running_cts < <(pct list | awk 'NR > 1 && $2 == "running" && $1 ~ /^[0-9]+$/ { print $1 }')
matched_cts=()
for id in "${running_cts[@]}"; do
  if is_griller_ct "$id"; then
    matched_cts+=("$id")
    hostname="$(pct config "$id" 2>/dev/null | sed -n 's/^hostname: //p' | head -n 1)"
    printf '  Found: CT %s (%s)\n' "$id" "${hostname:-hostname unknown}"
  fi
done

((${#matched_cts[@]} > 0)) || fail "No running LXC with ${COMPOSE_FILE} and the HomeLab Griller image was found. Nothing was modified."

if [[ -n ${CTID:-} ]]; then
  [[ "$CTID" =~ ^[0-9]+$ ]] || fail 'CTID must be a number.'
  chosen_ct="$CTID"
elif ((${#matched_cts[@]} == 1)); then
  chosen_ct="${matched_cts[0]}"
  printf '\nOnly one matching installation was found: CT %s.\n' "$chosen_ct"
else
  printf '\nMultiple installations found. Enter the Container ID to update: '
  [[ -t 0 ]] || fail 'Interactive selection required. Set CTID explicitly.'
  read -r chosen_ct
  [[ "$chosen_ct" =~ ^[0-9]+$ ]] || fail 'Invalid Container ID.'
fi

valid=false
for id in "${matched_cts[@]}"; do
  if [[ "$id" == "$chosen_ct" ]]; then valid=true; break; fi
done
[[ "$valid" == true ]] || fail "CT ${chosen_ct} is not a detected HomeLab Griller installation. Nothing was modified."

ct_hostname="$(pct config "$chosen_ct" 2>/dev/null | sed -n 's/^hostname: //p' | head -n 1)"
printf '\nSelected: CT %s (%s)\n' "$chosen_ct" "${ct_hostname:-hostname unknown}"
printf 'Installation: %s\n' "$APP_DIR"

# For unattended use, both CTID=<id> and CONFIRM=YES must be explicitly supplied.
if [[ ${CONFIRM:-} == YES && -n ${CTID:-} ]]; then
  printf 'Explicit CTID and CONFIRM=YES supplied. Proceeding.\n'
else
  [[ -t 0 ]] || fail 'Confirmation required. Run interactively or specify CTID=<id> CONFIRM=YES.'
  read -r -p "Update HomeLab Griller in CT ${chosen_ct}? [y/N]: " answer
  [[ "$answer" == y || "$answer" == Y || "$answer" == yes || "$answer" == YES ]] || {
    printf 'Cancelled. Nothing was modified.\n'
    exit 0
  }
fi

printf '\nStarting update in CT %s...\n' "$chosen_ct"
pct exec "$chosen_ct" -- bash -s <<'IN_LXC'
set -Eeuo pipefail

readonly APP_DIR='/opt/homelab-griller'
readonly SERVICE='homelab-griller'
readonly IMAGE_PREFIX='ghcr.io/borderlane-ha/homelab-griller'

cd "$APP_DIR"
[[ -f docker-compose.yml ]] || { echo 'Missing docker-compose.yml.' >&2; exit 1; }
[[ -d data ]] || { echo 'Missing data directory; refusing to update.' >&2; exit 1; }
command -v docker >/dev/null || { echo 'Docker is not installed.' >&2; exit 1; }
docker compose version >/dev/null

docker compose config --services | grep -Fxq "$SERVICE" || { echo 'HomeLab Griller compose service not found.' >&2; exit 1; }
image_ref="$(docker compose config --images | grep -E '^ghcr[.]io/borderlane-ha/homelab-griller(:|@)' | head -n 1)"
[[ -n "$image_ref" ]] || { echo 'Unexpected container image; refusing to update.' >&2; exit 1; }

stamp="$(date +%Y%m%d-%H%M%S)"
old_image_id="$(docker image inspect --format '{{.Id}}' "$image_ref" 2>/dev/null || true)"

# Critical: finish the network operation BEFORE stopping the current application.
echo "Pulling ${image_ref} before stopping the current installation..."
if ! docker compose pull "$SERVICE"; then
  echo 'Image download failed. Existing container state was not changed.' >&2
  exit 1
fi

umask 077
mkdir -p backups
backup="${APP_DIR}/backups/data-${stamp}.tar.gz"

rollback() {
  local rc=$?
  trap - ERR
  echo 'Update failed. Attempting to bring HomeLab Griller back online...' >&2
  if [[ -n "$old_image_id" ]]; then
    docker image tag "$old_image_id" "$image_ref" || true
    docker compose up -d --no-deps --pull never "$SERVICE" || true
  else
    docker compose start "$SERVICE" || docker compose up -d --no-deps --pull never "$SERVICE" || true
  fi
  echo "Backup, if created: ${backup}" >&2
  echo 'Check the container and restore the backup manually if a database migration caused a problem.' >&2
  exit "$rc"
}

# Short downtime starts here, after the image is already available locally.
trap rollback ERR
printf 'Stopping service briefly to produce a consistent SQLite backup...\n'
docker compose stop "$SERVICE"
tar -czf "$backup" -C "$APP_DIR" data
printf 'Backup created: %s\n' "$backup"

printf 'Starting new HomeLab Griller version...\n'
docker compose up -d --no-deps --pull never "$SERVICE"
new_cid="$(docker compose ps -q "$SERVICE")"
[[ -n "$new_cid" ]] || { echo 'No container ID returned after update.' >&2; false; }
[[ "$(docker inspect --format '{{.State.Running}}' "$new_cid")" == true ]] || { echo 'New container is not running.' >&2; false; }
trap - ERR

printf '\nUpdate completed for HomeLab Griller.\nBackup: %s\n' "$backup"
printf 'Verify the application in your browser.\n'
IN_LXC
