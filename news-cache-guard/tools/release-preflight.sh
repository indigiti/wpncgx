#!/usr/bin/env bash
# Newsroom Guard local PHP-only release preflight.
# Does not connect to WordPress, MySQL, Redis, Cloudways or GitHub; cannot
# replace the WordPress integration and staging performance acceptance gates.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"
PHP_BIN="${PHP_BIN:-php}"
if ! command -v "$PHP_BIN" >/dev/null 2>&1; then
  echo "FAIL: PHP CLI is unavailable: $PHP_BIN" >&2
  exit 1
fi
echo "PHP: $("$PHP_BIN" -r 'echo PHP_VERSION;')"
echo "Checking PHP syntax..."
while IFS= read -r -d '' file; do
  "$PHP_BIN" -l "$file" >/dev/null
done < <(find news-cache-guard -type f -name '*.php' -print0)

run_test() {
  local path="$1"
  shift
  if [[ -f "$path" ]]; then
    echo "Checking $path ${*:-default}"
    "$PHP_BIN" "$path" "$@"
  fi
}

run_test news-cache-guard/tests/regression.php
run_test news-cache-guard/tests/mu-search-guard.php
run_test news-cache-guard/tests/worker-guard.php
run_test news-cache-guard/tests/worker-guard.php phase2
run_test news-cache-guard/tests/worker-guard.php forcecache
run_test news-cache-guard/tests/post-history.php
run_test news-cache-guard/tests/editorial.php
run_test news-cache-guard/tests/dashboard.php
run_test news-cache-guard/tests/navigation.php
run_test news-cache-guard/tests/branding.php
run_test news-cache-guard/tests/media-policy.php
run_test news-cache-guard/tests/github-updates.php
run_test news-cache-guard/tests/performance.php
run_test news-cache-guard/tests/counter-efficiency.php
run_test news-cache-guard/tests/guard-budget.php dry
run_test news-cache-guard/tests/guard-budget.php enforce
for mode in unavailable future overdue empty error both-off; do
  run_test news-cache-guard/tests/worker-diagnostics.php "$mode"
done
bash -n news-cache-guard/tools/cache-probe.sh
echo "PASS: All available standalone tests and PHP lint checks completed."
echo "NOT VERIFIED: WordPress/MariaDB, server cache and Cloudways CPU/RAM profiling."
