#!/usr/bin/env bash
set -Eeuo pipefail

APP_IMAGE="${APP_IMAGE:-ghcr.io/borderlane-ha/homelab-griller:latest}"
CT_HOSTNAME="${CT_HOSTNAME:-homelab-griller}"
APP_PORT="${APP_PORT:-8091}"
TZ_VALUE="${TZ_VALUE:-Europe/Berlin}"
DEFAULT_LANGUAGE="${GRILLER_DEFAULT_LANGUAGE:-de}"
MEMORY="${MEMORY:-1024}"
CORES="${CORES:-1}"
DISK_SIZE="${DISK_SIZE:-8}"
BRIDGE="${BRIDGE:-vmbr0}"
IP_CONFIG="${IP_CONFIG:-dhcp}"
TEMPLATE_STORAGE="${TEMPLATE_STORAGE:-local}"
DEBIAN_VERSION="${DEBIAN_VERSION:-12}"

if [ "${EUID}" -ne 0 ]; then
  echo "Please run this script as root on the Proxmox VE host."
  exit 1
fi

require_cmd() {
  if ! command -v "$1" >/dev/null 2>&1; then
    echo "Required command not found: $1"
    echo "This script must be run on a Proxmox VE host."
    exit 1
  fi
}

require_cmd pct
require_cmd pveam
require_cmd pvesm
require_cmd awk
require_cmd grep

log() {
  echo ""
  echo "==> $*"
}

fail() {
  echo ""
  echo "ERROR: $*" >&2
  exit 1
}

generate_password() {
  local password

  # Avoid set -o pipefail breaking on SIGPIPE when head closes early.
  password="$(LC_ALL=C tr -dc 'A-Za-z0-9_@#%+=' </dev/urandom | head -c 24 || true)"

  if [ -z "${password}" ]; then
    password="HomeLabGriller$(date +%s)"
  fi

  printf '%s' "${password}"
}

default_ctid() {
  local id
  id="$(pvesh get /cluster/nextid 2>/dev/null || true)"

  if [[ "${id}" =~ ^[0-9]+$ ]]; then
    printf '%s' "${id}"
  else
    printf '200'
  fi
}

ask_value() {
  local var_name="$1"
  local label="$2"
  local default_value="$3"
  local current_value="${!var_name:-}"
  local input=""

  if [ -n "${current_value}" ]; then
    return 0
  fi

  if [ -t 0 ]; then
    read -r -p "${label} [${default_value}]: " input || true
    printf -v "${var_name}" '%s' "${input:-${default_value}}"
  else
    printf -v "${var_name}" '%s' "${default_value}"
  fi
}

ask_secret() {
  local var_name="$1"
  local label="$2"
  local current_value="${!var_name:-}"
  local input=""

  if [ -n "${current_value}" ]; then
    return 0
  fi

  if [ -t 0 ]; then
    read -r -s -p "${label}: " input || true
    echo ""
  fi

  if [ -z "${input}" ]; then
    input="$(generate_password)"
    echo "No value entered for ${label}. A random password was generated."
  fi

  printf -v "${var_name}" '%s' "${input}"
}

yaml_quote() {
  local value="$1"
  value="${value//\'/\'\'}"
  printf "'%s'" "${value}"
}

get_latest_debian_template() {
  pveam update >/dev/null 2>&1 || true

  pveam available --section system \
    | awk -v version="debian-${DEBIAN_VERSION}-standard" '$2 ~ version && $2 ~ /amd64/ {print $2}' \
    | sort -V \
    | tail -n 1
}

wait_for_lxc_network() {
  local attempt

  for attempt in $(seq 1 30); do
    if pct exec "${CTID}" -- bash -lc "getent hosts deb.debian.org >/dev/null 2>&1"; then
      return 0
    fi
    sleep 2
  done

  return 1
}

run_in_lxc() {
  pct exec "${CTID}" -- bash -lc "$1"
}

cat <<'BANNER'
HomeLab Griller by Pengu - Proxmox LXC installer
BANNER

DEFAULT_CTID="$(default_ctid)"

ask_value CTID "Container ID" "${DEFAULT_CTID}"
ask_value CT_HOSTNAME "Hostname" "${CT_HOSTNAME}"
ask_value STORAGE "Storage" "local-lvm"
ask_value BRIDGE "Bridge" "${BRIDGE}"
ask_value IP_CONFIG "Network IP config" "${IP_CONFIG}"
ask_value APP_PORT "App port" "${APP_PORT}"
ask_secret GRILLER_ADMIN_PASSWORD "Admin password for user Griller"

ROOT_PASSWORD="${ROOT_PASSWORD:-$(generate_password)}"

[[ "${CTID}" =~ ^[0-9]+$ ]] || fail "Container ID must be numeric."

if pct status "${CTID}" >/dev/null 2>&1; then
  fail "Container ID ${CTID} already exists. Choose a free Container ID."
fi

pvesm status | awk 'NR>1 {print $1}' | grep -qx "${STORAGE}" || fail "Storage '${STORAGE}' was not found."
ip link show "${BRIDGE}" >/dev/null 2>&1 || fail "Bridge '${BRIDGE}' was not found."

TEMPLATE="${TEMPLATE:-$(get_latest_debian_template)}"
[ -n "${TEMPLATE}" ] || fail "Could not find a Debian ${DEBIAN_VERSION} amd64 LXC template via pveam."

log "Downloading template ${TEMPLATE} to ${TEMPLATE_STORAGE} if needed"
pveam download "${TEMPLATE_STORAGE}" "${TEMPLATE}"

log "Creating LXC ${CTID} (${CT_HOSTNAME})"
pct create "${CTID}" "${TEMPLATE_STORAGE}:vztmpl/${TEMPLATE}" \
  --hostname "${CT_HOSTNAME}" \
  --ostype debian \
  --unprivileged 1 \
  --features nesting=1,keyctl=1,fuse=1 \
  --password "${ROOT_PASSWORD}" \
  --storage "${STORAGE}" \
  --rootfs "${STORAGE}:${DISK_SIZE}" \
  --memory "${MEMORY}" \
  --cores "${CORES}" \
  --net0 "name=eth0,bridge=${BRIDGE},ip=${IP_CONFIG}" \
  --onboot 1 \
  --start 1

log "Waiting for LXC startup"
sleep 8

if ! pct status "${CTID}" | grep -q "status: running"; then
  fail "LXC ${CTID} is not running after creation."
fi

log "Waiting for network/DNS inside LXC"
if ! wait_for_lxc_network; then
  echo "Warning: DNS check inside LXC timed out. Continuing with apt update anyway."
fi

log "Installing Docker inside LXC"
pct exec "${CTID}" -- bash -s <<'INSTALL_DOCKER'
set -Eeuo pipefail
export DEBIAN_FRONTEND=noninteractive

for i in $(seq 1 10); do
  apt-get update && break
  sleep 3

  if [ "$i" -eq 10 ]; then
    exit 1
  fi
done

apt-get install -y ca-certificates curl gnupg fuse-overlayfs

install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/debian/gpg -o /etc/apt/keyrings/docker.asc
chmod a+r /etc/apt/keyrings/docker.asc

. /etc/os-release
ARCH="$(dpkg --print-architecture)"

echo "deb [arch=${ARCH} signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/debian ${VERSION_CODENAME} stable" > /etc/apt/sources.list.d/docker.list

apt-get update
apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin

mkdir -p /etc/docker

cat >/etc/docker/daemon.json <<'JSON'
{
  "storage-driver": "fuse-overlayfs",
  "log-driver": "json-file",
  "log-opts": {
    "max-size": "10m",
    "max-file": "3"
  }
}
JSON

systemctl enable docker
systemctl restart docker

docker version >/dev/null
INSTALL_DOCKER

log "Deploying HomeLab Griller"

run_in_lxc "mkdir -p /opt/homelab-griller/data"

APP_IMAGE_Q="$(yaml_quote "${APP_IMAGE}")"
TZ_VALUE_Q="$(yaml_quote "${TZ_VALUE}")"
DEFAULT_LANGUAGE_Q="$(yaml_quote "${DEFAULT_LANGUAGE}")"
GRILLER_ADMIN_PASSWORD_Q="$(yaml_quote "${GRILLER_ADMIN_PASSWORD}")"
APP_PORT_Q="$(yaml_quote "${APP_PORT}:80")"

pct exec "${CTID}" -- bash -s <<INSTALL_APP
set -Eeuo pipefail

cat >/opt/homelab-griller/docker-compose.yml <<'COMPOSE'
services:
  homelab-griller:
    image: ${APP_IMAGE_Q}
    container_name: homelab-griller
    restart: unless-stopped
    ports:
      - ${APP_PORT_Q}
    environment:
      TZ: ${TZ_VALUE_Q}
      GRILLER_ADMIN_PASSWORD: ${GRILLER_ADMIN_PASSWORD_Q}
      GRILLER_DEFAULT_LANGUAGE: ${DEFAULT_LANGUAGE_Q}
    volumes:
      - ./data:/var/www/html/data
COMPOSE

cd /opt/homelab-griller
docker compose pull
docker compose up -d
INSTALL_APP

CT_IP="$(pct exec "${CTID}" -- hostname -I 2>/dev/null | awk '{print $1}' || true)"

log "Installation finished"

echo "Container ID: ${CTID}"
echo "Hostname: ${CT_HOSTNAME}"
echo "Open: http://${CT_IP:-LXC-IP}:${APP_PORT}"
echo "Admin username: Griller"
echo "Admin password: ${GRILLER_ADMIN_PASSWORD}"
echo "LXC root password: ${ROOT_PASSWORD}"
echo ""
echo "Data directory inside LXC: /opt/homelab-griller/data"
echo "Useful commands:"
echo "  pct enter ${CTID}"
echo "  pct exec ${CTID} -- bash -lc 'cd /opt/homelab-griller && docker compose ps'"
echo "  pct exec ${CTID} -- bash -lc 'cd /opt/homelab-griller && docker compose logs -f'"
