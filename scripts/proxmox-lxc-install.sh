#!/usr/bin/env bash
set -Eeuo pipefail

APP_IMAGE="${APP_IMAGE:-ghcr.io/borderlane-ha/homelab-griller:latest}"
CT_HOSTNAME="${CT_HOSTNAME:-homelab-griller}"
APP_PORT="${APP_PORT:-8088}"
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

if ! command -v pct >/dev/null 2>&1; then
  echo "pct was not found. This installer must be run on a Proxmox VE host."
  exit 1
fi

if ! command -v pveam >/dev/null 2>&1; then
  echo "pveam was not found. This installer must be run on a Proxmox VE host."
  exit 1
fi

next_ctid() {
  local id
  for id in $(seq 200 999); do
    if ! pct status "$id" >/dev/null 2>&1; then
      echo "$id"
      return 0
    fi
  done
  return 1
}

storage_exists() {
  pvesm status 2>/dev/null | awk 'NR>1 {print $1}' | grep -qx "$1"
}

first_storage() {
  pvesm status 2>/dev/null | awk 'NR>1 {print $1; exit}'
}

CTID="${CTID:-$(next_ctid)}"
STORAGE="${STORAGE:-}"
if [ -z "${STORAGE}" ]; then
  if storage_exists local-lvm; then
    STORAGE="local-lvm"
  elif storage_exists local; then
    STORAGE="local"
  else
    STORAGE="$(first_storage)"
  fi
fi

if [ -t 0 ]; then
  echo "HomeLab Griller by Pengu - Proxmox LXC installer"
  echo
  read -r -p "Container ID [${CTID}]: " INPUT_CTID
  CTID="${INPUT_CTID:-${CTID}}"
  read -r -p "Hostname [${CT_HOSTNAME}]: " INPUT_HOSTNAME
  CT_HOSTNAME="${INPUT_HOSTNAME:-${CT_HOSTNAME}}"
  read -r -p "Storage [${STORAGE}]: " INPUT_STORAGE
  STORAGE="${INPUT_STORAGE:-${STORAGE}}"
  read -r -p "Bridge [${BRIDGE}]: " INPUT_BRIDGE
  BRIDGE="${INPUT_BRIDGE:-${BRIDGE}}"
  read -r -p "Network IP config [${IP_CONFIG}] (use dhcp or e.g. 192.168.1.50/24,gw=192.168.1.1): " INPUT_IP
  IP_CONFIG="${INPUT_IP:-${IP_CONFIG}}"
  read -r -p "App port [${APP_PORT}]: " INPUT_PORT
  APP_PORT="${INPUT_PORT:-${APP_PORT}}"
  read -r -s -p "Admin password for user Griller: " GRILLER_ADMIN_PASSWORD_INPUT
  echo
  GRILLER_ADMIN_PASSWORD="${GRILLER_ADMIN_PASSWORD:-${GRILLER_ADMIN_PASSWORD_INPUT}}"
fi

if [ -z "${GRILLER_ADMIN_PASSWORD:-}" ]; then
  echo "GRILLER_ADMIN_PASSWORD is required."
  echo "Example: GRILLER_ADMIN_PASSWORD='my-password' bash -c \"\$(curl -fsSL https://raw.githubusercontent.com/Borderlane-HA/homelab-griller/main/scripts/proxmox-lxc-install.sh)\""
  exit 1
fi

if pct status "$CTID" >/dev/null 2>&1; then
  echo "Container ID ${CTID} already exists."
  exit 1
fi

if ! storage_exists "$STORAGE"; then
  echo "Storage '${STORAGE}' does not exist."
  echo "Available storages:"
  pvesm status | awk 'NR>1 {print "- " $1}'
  exit 1
fi

if ! storage_exists "$TEMPLATE_STORAGE"; then
  echo "Template storage '${TEMPLATE_STORAGE}' does not exist."
  echo "Set TEMPLATE_STORAGE to a storage that can hold container templates."
  exit 1
fi

TEMPLATE="$(pveam available --section system | awk -v ver="debian-${DEBIAN_VERSION}-standard" '$2 ~ ver {print $2}' | tail -n 1)"
if [ -z "${TEMPLATE}" ]; then
  echo "Could not find a Debian ${DEBIAN_VERSION} LXC template via pveam."
  exit 1
fi

TEMPLATE_PATH="${TEMPLATE_STORAGE}:vztmpl/${TEMPLATE}"
if [ ! -f "/var/lib/vz/template/cache/${TEMPLATE}" ] || [ "${TEMPLATE_STORAGE}" != "local" ]; then
  echo "Downloading template ${TEMPLATE} to ${TEMPLATE_STORAGE}..."
  pveam download "${TEMPLATE_STORAGE}" "${TEMPLATE}" || true
fi

ROOT_PASSWORD="${ROOT_PASSWORD:-$(tr -dc 'A-Za-z0-9_@#%+=' </dev/urandom | head -c 24)}"

NET0="name=eth0,bridge=${BRIDGE},ip=${IP_CONFIG}"

echo "Creating LXC ${CTID} (${CT_HOSTNAME})..."
pct create "${CTID}" "${TEMPLATE_PATH}" \
  -hostname "${CT_HOSTNAME}" \
  -unprivileged 1 \
  -features nesting=1,keyctl=1 \
  -cores "${CORES}" \
  -memory "${MEMORY}" \
  -swap 512 \
  -rootfs "${STORAGE}:${DISK_SIZE}" \
  -net0 "${NET0}" \
  -onboot 1 \
  -start 1 \
  -password "${ROOT_PASSWORD}"

echo "Waiting for container network..."
sleep 8
pct exec "${CTID}" -- bash -lc 'for i in $(seq 1 30); do ping -c1 -W1 deb.debian.org >/dev/null 2>&1 && exit 0; sleep 2; done; exit 1' || {
  echo "The container could not reach the internet. Check bridge, DHCP/static IP and DNS."
  exit 1
}

echo "Installing Docker and HomeLab Griller inside the container..."
pct exec "${CTID}" -- bash -lc "apt-get update && apt-get install -y ca-certificates curl gnupg"
pct exec "${CTID}" -- bash -lc "install -m 0755 -d /etc/apt/keyrings && curl -fsSL https://download.docker.com/linux/debian/gpg -o /etc/apt/keyrings/docker.asc && chmod a+r /etc/apt/keyrings/docker.asc"
pct exec "${CTID}" -- bash -lc 'ARCH=$(dpkg --print-architecture); . /etc/os-release; echo "deb [arch=${ARCH} signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/debian ${VERSION_CODENAME} stable" > /etc/apt/sources.list.d/docker.list'
pct exec "${CTID}" -- bash -lc "apt-get update && apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin"

pct exec "${CTID}" -- bash -lc "mkdir -p /opt/homelab-griller/data && chmod 755 /opt/homelab-griller"

TMP_ENV="$(mktemp)"
TMP_COMPOSE="$(mktemp)"
cat > "${TMP_ENV}" <<ENV_FILE
GRILLER_ADMIN_PASSWORD=${GRILLER_ADMIN_PASSWORD}
GRILLER_DEFAULT_LANGUAGE=${DEFAULT_LANGUAGE}
TZ=${TZ_VALUE}
APP_PORT=${APP_PORT}
ENV_FILE
cat > "${TMP_COMPOSE}" <<COMPOSE_FILE
services:
  homelab-griller:
    image: ${APP_IMAGE}
    container_name: homelab-griller
    restart: unless-stopped
    ports:
      - "\${APP_PORT:-8088}:80"
    environment:
      TZ: "\${TZ:-Europe/Berlin}"
      GRILLER_ADMIN_PASSWORD: "\${GRILLER_ADMIN_PASSWORD}"
      GRILLER_DEFAULT_LANGUAGE: "\${GRILLER_DEFAULT_LANGUAGE:-de}"
    volumes:
      - ./data:/var/www/html/data
COMPOSE_FILE
pct push "${CTID}" "${TMP_ENV}" /opt/homelab-griller/.env -perms 600
pct push "${CTID}" "${TMP_COMPOSE}" /opt/homelab-griller/docker-compose.yml -perms 644
rm -f "${TMP_ENV}" "${TMP_COMPOSE}"

pct exec "${CTID}" -- bash -lc "cd /opt/homelab-griller && docker compose pull && docker compose up -d"

CT_IP="$(pct exec "${CTID}" -- bash -lc "hostname -I | awk '{print \$1}'" 2>/dev/null | tr -d '\r')"

echo
echo "HomeLab Griller by Pengu is installed."
echo "Container ID: ${CTID}"
echo "App directory in LXC: /opt/homelab-griller"
echo "Data directory in LXC: /opt/homelab-griller/data"
echo "Admin user: Griller"
echo "Open: http://${CT_IP:-LXC-IP}:${APP_PORT}"
echo
echo "Manage it with: pct enter ${CTID}"
echo "Update it later inside the LXC with: cd /opt/homelab-griller && docker compose pull && docker compose up -d"
