#!/usr/bin/env bash
# Read-only, low-volume anonymous cache validation. 4 article + optional 1 search GET.
set -euo pipefail
if [[ $# -lt 1 || $# -gt 2 ]]; then
  echo "Usage: bash tools/cache-probe.sh 'https://stage.example.com/article/' ['https://stage.example.com/?s=test']" >&2
  exit 2
fi
for url in "$@"; do
  if [[ "$url" != https://* ]]; then echo 'Only HTTPS URLs allowed' >&2; exit 2; fi
done
if ! command -v curl >/dev/null; then echo 'curl not found' >&2; exit 2; fi
# Require a staging hostname unless explicitly authorized for a five-request live probe.
if [[ "${NCG_ALLOW_LIVE_PROBE:-}" != 'I_APPROVE_5_REQUESTS' && "${1,,}" != *stage* ]]; then
  echo 'URL must contain stage/staging; otherwise set NCG_ALLOW_LIVE_PROBE=I_APPROVE_5_REQUESTS for your own site' >&2
  exit 2
fi
article="$1"
search_url="${2:-}"
header=$(mktemp)
trap 'rm -f "$header"' EXIT
probe() {
  local name="$1" url="$2" result lsc cf qcache
  shift 2
  : > "$header"
  result=$(curl --silent --show-error --location --max-redirs 2 --max-time 12 --retry 0 \
    --header 'Accept: text/html' "$@" --output /dev/null --dump-header "$header" \
    --write-out 'http=%{http_code} ttfb=%{time_starttransfer}s total=%{time_total}s' "$url")
  # Take the most recent values (including redirects).
  lsc=$(awk '{l=tolower($0);if(l ~ /^x-litespeed-cache:/) v=$0}END{print v}' "$header" | tr -d '\r')
  cf=$(awk '{l=tolower($0);if(l ~ /^cf-cache-status:/) v=$0}END{print v}' "$header" | tr -d '\r')
  qcache=$(awk '{l=tolower($0);if(l ~ /^x-qc-cache:/) v=$0}END{print v}' "$header" | tr -d '\r')
  printf '%s %s | %s | %s | %s\n' "$name" "$result" "${lsc:-litespeed=not-visible}" "${cf:-cloudflare=not-visible}" "${qcache:-quic=not-visible}"
  if [[ "$name" == search && ( "${lsc,,}" == *hit* || "${cf,,}" == *hit* || "${qcache,,}" == *hit* ) ]]; then
    echo 'FAIL: search response appears publicly cached. Inspect LSCache/Cloudflare bypass configuration.' >&2
    return 1
  fi
}
echo 'Probing anonymous HTTP cache (not a load test).'
probe article-1 "$article"
sleep 1
probe article-2 "$article"
sleep 1
probe article-revalidate "$article" --header 'Cache-Control: no-cache' --header 'Pragma: no-cache'
sleep 1
probe article-3 "$article"
if [[ -n "$search_url" ]]; then probe search "$search_url"; fi
echo 'Review: revalidate should be HIT only after the cache is populated and CacheIgnoreCacheControl is supported/enabled. A Cloudflare HIT does not prove an origin LiteSpeed HIT.'
echo 'Ensure admin, search, previews and personalized paths are still uncached.'
