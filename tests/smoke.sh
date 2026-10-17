#!/usr/bin/env bash
# GitHub Actions / Docker runner integration test.
set -Eeuo pipefail
NAME="griller-smoke-$$"
PORT=19091
HTTPS_PORT=19443
BASE="http://127.0.0.1:${PORT}"
DIR="$(mktemp -d)"
cleanup() { docker rm -f "$NAME" >/dev/null 2>&1 || true; rm -rf "$DIR"; }
trap cleanup EXIT

# Keep container diagnostics if any assertion or HTTP request fails.
on_error() {
  local status="$1" line="$2" cmd="$3"
  trap - ERR
  set +e
  echo "" >&2
  echo "Smoke test failed at line ${line} (exit ${status}): ${cmd}" >&2
  echo "=== Container state ===" >&2
  docker inspect -f 'state={{.State.Status}} exit={{.State.ExitCode}} oom={{.State.OOMKilled}}' "$NAME" >&2
  echo "=== Container logs (last 120 lines) ===" >&2
  docker logs --tail 120 "$NAME" >&2
  exit "$status"
}
trap 'on_error "$?" "$LINENO" "$BASH_COMMAND"' ERR

# Only use retries for idempotent GETs. Never automatically retry order-changing POSTs.
http_get() {
  curl --connect-timeout 5 --max-time 20 \
       --retry 5 --retry-delay 1 --retry-connrefused --retry-all-errors "$@"
}
step() { printf '\n[SMOKE] %s\n' "$*"; }

step "Building Docker image"
docker build -t homelab-griller:smoke .
step "Starting container"
docker run -d --name "$NAME" -p "127.0.0.1:${PORT}:80" -p "127.0.0.1:${HTTPS_PORT}:443" -e GRILLER_DEFAULT_LANGUAGE=en homelab-griller:smoke >/dev/null
step "Waiting for the setup page"
ready=0
for i in $(seq 1 30); do
  if curl -fsS --connect-timeout 2 --max-time 3 -L "$BASE/?r=setup" -o "$DIR/setup.html" 2>/dev/null && grep -q 'name="password_confirm"' "$DIR/setup.html"; then ready=1;break;fi
  sleep 2
done
[[ "$ready" == 1 ]] || {
  echo 'Setup page not ready after 60 seconds' >&2
  docker ps -a --filter "name=$NAME" >&2
  docker logs --tail 120 "$NAME" >&2
  exit 1
}
step "Setup page is ready; checking HTTP, HTTPS and private files"
! grep -q 'name="setup_token"' "$DIR/setup.html" || { echo 'Log token still required' >&2; exit 1; }
[[ "$(http_get -sS -o /dev/null -w '%{http_code}' "$BASE/data/griller.sqlite")" == 403 ]] || { echo 'SQLite exposed!' >&2; exit 1; }
[[ "$(http_get -ksS -o /dev/null -w '%{http_code}' "https://127.0.0.1:${HTTPS_PORT}/?r=setup")" == 200 ]] || { echo 'HTTPS failed' >&2; exit 1; }
step "Submitting initial admin setup"
http_get -fsS -c "$DIR/cookies" "$BASE/?r=setup" -o "$DIR/setup.html"
csrf="$(grep -oE 'name="csrf" value="[a-f0-9]+"' "$DIR/setup.html" | head -1 | cut -d '"' -f4)"
[[ -n "$csrf" ]] || { echo 'Missing CSRF' >&2; exit 1; }
curl -sS -b "$DIR/cookies" -c "$DIR/cookies" -o /dev/null \
  --data-urlencode "csrf=$csrf" --data-urlencode "username=Griller" \
  --data-urlencode "password=Test123" --data-urlencode "password_confirm=Test123" \
  --data-urlencode 'theme=ember' "$BASE/?r=setup"
http_get -fsS -b "$DIR/cookies" "$BASE/?r=admin" -o "$DIR/admin.html"
grep -q 'admin-sidebar' "$DIR/admin.html" || { echo 'Admin sidebar missing'; exit 1; }
grep -q 'admin-mobile-bottom' "$DIR/admin.html" || { echo 'Admin mobile navigation missing'; exit 1; }
grep -q 'Welcome back' "$DIR/admin.html" || { echo 'Dashboard missing'; exit 1; }
http_get -fsS -b "$DIR/cookies" "$BASE/?r=admin_events" -o "$DIR/events.html"
grep -q 'New BBQ event' "$DIR/events.html" || { echo 'Events page missing'; exit 1; }
# The beta.5 dashboard is read-only and has no CSRF field. The create-event
# form lives on the separate admin_events page, so read its CSRF token there.
csrf="$(grep -m1 -oE 'name="csrf" value="[a-f0-9]+"' "$DIR/events.html" | cut -d '"' -f4 || true)"
[[ -n "$csrf" ]] || { echo 'Missing CSRF token in event creation form' >&2; exit 1; }
curl -sS -b "$DIR/cookies" -c "$DIR/cookies" -o /dev/null \
  --data-urlencode "csrf=$csrf" --data-urlencode 'action=create_event' --data-urlencode 'title=Smoke BBQ' \
  --data-urlencode 'event_date=2026-10-17' "$BASE/?r=admin_events"
http_get -fsS -b "$DIR/cookies" "$BASE/?r=admin_events" -o "$DIR/events.html"
grep -q 'Smoke BBQ' "$DIR/events.html" || { echo 'Event creation failed'; exit 1; }
grep -q 'class="pill public"' "$DIR/events.html" || { echo 'Expected public default for event'; exit 1; }
http_get -fsS -b "$DIR/cookies" "$BASE/?r=admin" -o "$DIR/dashboard.html"
grep -q 'Smoke BBQ' "$DIR/dashboard.html" || { echo 'Dashboard featured event missing'; exit 1; }
http_get -fsS -b "$DIR/cookies" "$BASE/?r=admin_overview_api" -o "$DIR/overview.json"
python3 - "$DIR/overview.json" <<'PYADMIN'
import json,sys
payload=json.load(open(sys.argv[1]))
assert payload['event_id']==1,payload
assert 'pending' in payload['stats'],payload
assert 'recent' in payload and 'alerts' in payload,payload
PYADMIN
step "Checking admin sections"
for page in admin_categories admin_products admin_ingredients admin_guests admin_lang admin_settings; do
  http_get -fsS -b "$DIR/cookies" "$BASE/?r=$page" -o "$DIR/${page}.html"
  grep -q 'admin-sidebar' "$DIR/${page}.html" || { echo "Admin menu missing on $page"; exit 1; }
done

[[ "$(http_get -sS -o /dev/null -w '%{http_code}' "$BASE/data/tls/server.key")" == 403 ]] || { echo 'Private key exposed!' >&2; exit 1; }
# Enable inventory for the first product and cap it at one serving.
http_get -fsS -b "$DIR/cookies" "$BASE/?r=admin_inventory&id=1" -o "$DIR/inventory.html"
grep -q 'name="inventory_enabled"' "$DIR/inventory.html" || { echo 'Stock management missing'; exit 1; }
ep_id="$(grep -oE 'name="product_stock\[[0-9]+\]"' "$DIR/inventory.html" | head -1 | grep -oE '[0-9]+')"
[[ -n "$ep_id" ]] || { echo 'Cannot find an event product'; exit 1; }
curl -sS -b "$DIR/cookies" -c "$DIR/cookies" -o /dev/null \
  --data-urlencode "csrf=$csrf" --data-urlencode 'inventory_enabled=on' \
  --data-urlencode "product_stock[$ep_id]=1" "$BASE/?r=admin_inventory&id=1"
http_get -fsS -b "$DIR/cookies" "$BASE/?r=admin_inventory&id=1" -o "$DIR/inventory.html"
grep -q 'name="inventory_enabled" checked' "$DIR/inventory.html" || { echo 'Inventory not enabled'; exit 1; }

step "Testing inventory limit and cancellation release"
# Guest A joins and consumes the one available item.
step "Creating two guest sessions"
for guest in first second; do
  cookie="$DIR/guest-$guest"
  http_get -fsS -c "$cookie" "$BASE/?r=event&slug=smoke-bbq" -o "$DIR/guest-$guest.html"
  guest_csrf="$(grep -oE 'name="csrf" value="[a-f0-9]+"' "$DIR/guest-$guest.html" | head -1 | cut -d '"' -f4)"
  curl -sS -b "$cookie" -c "$cookie" -o /dev/null \
    --data-urlencode "csrf=$guest_csrf" --data-urlencode 'action=join' \
    --data-urlencode "name=$guest" "$BASE/?r=event&slug=smoke-bbq"
  http_get -fsS -b "$cookie" "$BASE/?r=event&slug=smoke-bbq" -o "$DIR/guest-$guest.html"
  grep -q 'id="guest-order-form"' "$DIR/guest-$guest.html" || { echo "Guest $guest could not join"; exit 1; }
done
for guest in first second; do
  cookie="$DIR/guest-$guest"
  guest_csrf="$(grep -oE 'name="csrf" value="[a-f0-9]+"' "$DIR/guest-$guest.html" | head -1 | cut -d '"' -f4)"
  curl -sS -b "$cookie" -c "$cookie" -o /dev/null \
    --data-urlencode "csrf=$guest_csrf" --data-urlencode 'action=order' \
    --data-urlencode "items[$ep_id][quantity]=1" "$BASE/?r=event&slug=smoke-bbq"
  http_get -fsS -b "$cookie" "$BASE/?r=guest_inventory_api&slug=smoke-bbq" -o "$DIR/availability-$guest.json"
done
python3 - "$DIR/availability-second.json" "$ep_id" <<'PYJSON'
import json,sys
result=json.load(open(sys.argv[1])); item=result['products'][sys.argv[2]]
assert result['enabled'] and item['remaining']==0,item
PYJSON
# Only the first order must be persisted. The second order must be rejected.
http_get -fsS -b "$DIR/guest-second" "$BASE/?r=guest_status_api&slug=smoke-bbq" -o "$DIR/second-status.html"
! grep -q 'data-order-id=' "$DIR/second-status.html" || { echo 'Oversold stock'; exit 1; }
http_get -fsS -b "$DIR/guest-first" "$BASE/?r=guest_status_api&slug=smoke-bbq" -o "$DIR/first-status.html"
first_order="$(grep -oE 'data-order-id="[0-9]+"' "$DIR/first-status.html" | head -1 | cut -d '"' -f2)"
[[ -n "$first_order" ]] || { echo 'Original guest order not found'; exit 1; }
# Cancellation must release the previously reserved unit.
first_csrf="$(grep -oE 'name="csrf" value="[a-f0-9]+"' "$DIR/guest-first.html" | head -1 | cut -d '"' -f4)"
curl -sS -b "$DIR/guest-first" -c "$DIR/guest-first" -o /dev/null \
  --data-urlencode "csrf=$first_csrf" --data-urlencode 'action=cancel_order' \
  --data-urlencode "order_id=$first_order" "$BASE/?r=event&slug=smoke-bbq"
http_get -fsS -b "$DIR/guest-second" "$BASE/?r=guest_inventory_api&slug=smoke-bbq" -o "$DIR/availability-after-cancel.json"
python3 - "$DIR/availability-after-cancel.json" "$ep_id" <<'PYJSON'
import json,sys
item=json.load(open(sys.argv[1]))['products'][sys.argv[2]]
assert item['remaining']==1,item
PYJSON

echo 'Smoke checks passed: first-run, HTTP/HTTPS security, public events, stock caps, oversell protection and cancellation release.' 
