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

docker build -t homelab-griller:smoke .
docker run -d --name "$NAME" -p "127.0.0.1:${PORT}:80" -p "127.0.0.1:${HTTPS_PORT}:443" -e GRILLER_DEFAULT_LANGUAGE=en homelab-griller:smoke >/dev/null
ready=0
for i in $(seq 1 30); do
  if curl -fsS -L "$BASE/?r=setup" -o "$DIR/setup.html" && grep -q 'name="password_confirm"' "$DIR/setup.html"; then ready=1;break;fi
  sleep 2
done
[[ "$ready" == 1 ]] || { docker logs "$NAME"; echo 'Setup page not ready' >&2; exit 1; }
! grep -q 'name="setup_token"' "$DIR/setup.html" || { echo 'Log token still required' >&2; exit 1; }
[[ "$(curl -sS -o /dev/null -w '%{http_code}' "$BASE/data/griller.sqlite")" == 403 ]] || { echo 'SQLite exposed!' >&2; exit 1; }
[[ "$(curl -ksS -o /dev/null -w '%{http_code}' "https://127.0.0.1:${HTTPS_PORT}/?r=setup")" == 200 ]] || { echo 'HTTPS failed' >&2; exit 1; }
curl -fsS -c "$DIR/cookies" "$BASE/?r=setup" -o "$DIR/setup.html"
csrf="$(grep -oE 'name="csrf" value="[a-f0-9]+"' "$DIR/setup.html" | head -1 | cut -d '"' -f4)"
[[ -n "$csrf" ]] || { echo 'Missing CSRF' >&2; exit 1; }
curl -sS -b "$DIR/cookies" -c "$DIR/cookies" -o /dev/null \
  --data-urlencode "csrf=$csrf" --data-urlencode "username=Griller" \
  --data-urlencode "password=Test123" --data-urlencode "password_confirm=Test123" \
  --data-urlencode 'theme=ember' "$BASE/?r=setup"
curl -fsS -b "$DIR/cookies" "$BASE/?r=admin" -o "$DIR/admin.html"
grep -q 'New BBQ event' "$DIR/admin.html" || { echo 'Admin setup failed'; tail -25 "$DIR/admin.html"; exit 1; }
csrf="$(grep -oE 'name="csrf" value="[a-f0-9]+"' "$DIR/admin.html" | head -1 | cut -d '"' -f4)"
curl -sS -b "$DIR/cookies" -c "$DIR/cookies" -o /dev/null \
  --data-urlencode "csrf=$csrf" --data-urlencode 'action=create_event' --data-urlencode 'title=Smoke BBQ' \
  --data-urlencode 'event_date=2026-10-17' "$BASE/?r=admin"
curl -fsS -b "$DIR/cookies" "$BASE/?r=admin" -o "$DIR/admin.html"
grep -q 'Smoke BBQ' "$DIR/admin.html" || { echo 'Event creation failed'; exit 1; }
grep -q 'class="pill public"' "$DIR/admin.html" || { echo 'Expected public default for event'; exit 1; }
[[ "$(curl -sS -o /dev/null -w '%{http_code}' "$BASE/data/tls/server.key")" == 403 ]] || { echo 'Private key exposed!' >&2; exit 1; }
# Enable inventory for the first product and cap it at one serving.
curl -fsS -b "$DIR/cookies" "$BASE/?r=admin_inventory&id=1" -o "$DIR/inventory.html"
grep -q 'name="inventory_enabled"' "$DIR/inventory.html" || { echo 'Stock management missing'; exit 1; }
ep_id="$(grep -oE 'name="product_stock\[[0-9]+\]"' "$DIR/inventory.html" | head -1 | grep -oE '[0-9]+')"
[[ -n "$ep_id" ]] || { echo 'Cannot find an event product'; exit 1; }
curl -sS -b "$DIR/cookies" -c "$DIR/cookies" -o /dev/null \
  --data-urlencode "csrf=$csrf" --data-urlencode 'inventory_enabled=on' \
  --data-urlencode "product_stock[$ep_id]=1" "$BASE/?r=admin_inventory&id=1"
curl -fsS -b "$DIR/cookies" "$BASE/?r=admin_inventory&id=1" -o "$DIR/inventory.html"
grep -q 'name="inventory_enabled" checked' "$DIR/inventory.html" || { echo 'Inventory not enabled'; exit 1; }

# Guest A joins and consumes the one available item.
for guest in first second; do
  cookie="$DIR/guest-$guest"
  curl -fsS -c "$cookie" "$BASE/?r=event&slug=smoke-bbq" -o "$DIR/guest-$guest.html"
  guest_csrf="$(grep -oE 'name="csrf" value="[a-f0-9]+"' "$DIR/guest-$guest.html" | head -1 | cut -d '"' -f4)"
  curl -sS -b "$cookie" -c "$cookie" -o /dev/null \
    --data-urlencode "csrf=$guest_csrf" --data-urlencode 'action=join' \
    --data-urlencode "name=$guest" "$BASE/?r=event&slug=smoke-bbq"
  curl -fsS -b "$cookie" "$BASE/?r=event&slug=smoke-bbq" -o "$DIR/guest-$guest.html"
  grep -q 'id="guest-order-form"' "$DIR/guest-$guest.html" || { echo "Guest $guest could not join"; exit 1; }
done
for guest in first second; do
  cookie="$DIR/guest-$guest"
  guest_csrf="$(grep -oE 'name="csrf" value="[a-f0-9]+"' "$DIR/guest-$guest.html" | head -1 | cut -d '"' -f4)"
  curl -sS -b "$cookie" -c "$cookie" -o /dev/null \
    --data-urlencode "csrf=$guest_csrf" --data-urlencode 'action=order' \
    --data-urlencode "items[$ep_id][quantity]=1" "$BASE/?r=event&slug=smoke-bbq"
  curl -fsS -b "$cookie" "$BASE/?r=guest_inventory_api&slug=smoke-bbq" -o "$DIR/availability-$guest.json"
done
python3 - "$DIR/availability-second.json" "$ep_id" <<'PYJSON'
import json,sys
result=json.load(open(sys.argv[1])); item=result['products'][sys.argv[2]]
assert result['enabled'] and item['remaining']==0,item
PYJSON
# Only the first order must be persisted. The second order must be rejected.
curl -fsS -b "$DIR/guest-second" "$BASE/?r=guest_status_api&slug=smoke-bbq" -o "$DIR/second-status.html"
! grep -q 'data-order-id=' "$DIR/second-status.html" || { echo 'Oversold stock'; exit 1; }
curl -fsS -b "$DIR/guest-first" "$BASE/?r=guest_status_api&slug=smoke-bbq" -o "$DIR/first-status.html"
first_order="$(grep -oE 'data-order-id="[0-9]+"' "$DIR/first-status.html" | head -1 | cut -d '"' -f2)"
[[ -n "$first_order" ]] || { echo 'Original guest order not found'; exit 1; }
# Cancellation must release the previously reserved unit.
first_csrf="$(grep -oE 'name="csrf" value="[a-f0-9]+"' "$DIR/guest-first.html" | head -1 | cut -d '"' -f4)"
curl -sS -b "$DIR/guest-first" -c "$DIR/guest-first" -o /dev/null \
  --data-urlencode "csrf=$first_csrf" --data-urlencode 'action=cancel_order' \
  --data-urlencode "order_id=$first_order" "$BASE/?r=event&slug=smoke-bbq"
curl -fsS -b "$DIR/guest-second" "$BASE/?r=guest_inventory_api&slug=smoke-bbq" -o "$DIR/availability-after-cancel.json"
python3 - "$DIR/availability-after-cancel.json" "$ep_id" <<'PYJSON'
import json,sys
item=json.load(open(sys.argv[1]))['products'][sys.argv[2]]
assert item['remaining']==1,item
PYJSON

echo 'Smoke checks passed: first-run, HTTP/HTTPS security, public events, stock caps, oversell protection and cancellation release.' 
