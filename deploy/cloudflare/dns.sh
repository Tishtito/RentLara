#!/usr/bin/env bash
# Point the three Rentflow hostnames at the server (Cloudflare DNS, API v4). Safe to run again:
# existing records are updated, missing ones created.
#
#   export CLOUDFLARE_API_TOKEN=...      # token with Zone → DNS → Edit on codepasstech.top
#   ./dns.sh --dry-run                   # show what would change
#   ./dns.sh                             # apply
#
# Records are DNS-only (grey cloud) so certbot's HTTP challenge reaches nginx directly. To put them
# behind Cloudflare's proxy later: PROXIED=true ./dns.sh, then set SSL/TLS mode to "Full (strict)"
# (the server has valid Let's Encrypt certificates) and TRUSTED_PROXIES=* in the backend .env.
set -euo pipefail

ZONE_NAME="${ZONE_NAME:-codepasstech.top}"
SERVER_IP="${SERVER_IP:-102.212.246.251}"
PROXIED="${PROXIED:-false}"
TTL="${TTL:-1}" # 1 = automatic
HOSTS=("rentflow" "admin.rentflow" "api.rentflow")
DRY_RUN=false
[[ "${1:-}" == "--dry-run" ]] && DRY_RUN=true

: "${CLOUDFLARE_API_TOKEN:?Set CLOUDFLARE_API_TOKEN (Zone → DNS → Edit on $ZONE_NAME)}"
command -v jq >/dev/null || { echo "jq is required (sudo apt install jq)"; exit 1; }

API="https://api.cloudflare.com/client/v4"
cf() { curl -fsS -H "Authorization: Bearer $CLOUDFLARE_API_TOKEN" -H "Content-Type: application/json" "$@"; }

ZONE_ID="${CLOUDFLARE_ZONE_ID:-$(cf "$API/zones?name=$ZONE_NAME" | jq -r '.result[0].id // empty')}"
[[ -n "$ZONE_ID" ]] || { echo "Zone $ZONE_NAME not found for this token."; exit 1; }
echo "Zone $ZONE_NAME ($ZONE_ID) → $SERVER_IP, proxied=$PROXIED"

for host in "${HOSTS[@]}"; do
  fqdn="$host.$ZONE_NAME"
  body=$(jq -n --arg name "$fqdn" --arg ip "$SERVER_IP" --argjson proxied "$PROXIED" --argjson ttl "$TTL" \
    '{type: "A", name: $name, content: $ip, proxied: $proxied, ttl: $ttl, comment: "Rentflow"}')
  existing=$(cf "$API/zones/$ZONE_ID/dns_records?type=A&name=$fqdn" | jq -r '.result[0] // empty | "\(.id) \(.content) \(.proxied)"')

  if [[ -z "$existing" ]]; then
    echo "  CREATE  A $fqdn → $SERVER_IP"
    $DRY_RUN || cf -X POST "$API/zones/$ZONE_ID/dns_records" --data "$body" | jq -r '"          ok: \(.result.id)"'
  else
    read -r id content proxied <<<"$existing"
    if [[ "$content" == "$SERVER_IP" && "$proxied" == "$PROXIED" ]]; then
      echo "  OK      A $fqdn → $content (unchanged)"
    else
      echo "  UPDATE  A $fqdn: $content (proxied=$proxied) → $SERVER_IP (proxied=$PROXIED)"
      $DRY_RUN || cf -X PUT "$API/zones/$ZONE_ID/dns_records/$id" --data "$body" | jq -r '"          ok: \(.result.id)"'
    fi
  fi
done

$DRY_RUN && echo "Dry run: nothing changed."
echo "Check: dig +short rentflow.$ZONE_NAME admin.rentflow.$ZONE_NAME api.rentflow.$ZONE_NAME"
