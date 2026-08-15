#!/usr/bin/env bash
set -euo pipefail

compose=(docker compose -f tests/docker-compose.yml)
cleanup() {
  "${compose[@]}" down --volumes --remove-orphans >/dev/null 2>&1 || true
}
trap cleanup EXIT

"${compose[@]}" up --detach

ready=0
for _ in $(seq 1 90); do
  if curl --fail --silent --output /dev/null http://localhost:8087/; then
    ready=1
    break
  fi
  sleep 2
done

if [[ "$ready" != "1" ]]; then
  "${compose[@]}" logs prestashop
  echo "PrestaShop did not become ready." >&2
  exit 1
fi

"${compose[@]}" exec -T prestashop php bin/console prestashop:module install govpexchange --no-interaction
"${compose[@]}" exec -T prestashop php bin/console prestashop:module disable govpexchange --no-interaction
"${compose[@]}" exec -T prestashop php bin/console prestashop:module enable govpexchange --no-interaction
"${compose[@]}" exec -T prestashop php bin/console prestashop:module uninstall govpexchange --no-interaction

echo "GOVP for PrestaShop native install lifecycle passed."

