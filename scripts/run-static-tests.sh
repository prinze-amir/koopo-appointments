#!/usr/bin/env bash
set -euo pipefail

plugin_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$plugin_root"

php_count="$(find . -type f -name '*.php' -not -path './vendor/*' -not -path './node_modules/*' | wc -l | tr -d ' ')"
find . -type f -name '*.php' -not -path './vendor/*' -not -path './node_modules/*' \
  -exec sh -c 'for file do php -l "$file" >/dev/null || exit 1; done' sh {} +
echo "PHP syntax checks passed (${php_count} files)."

js_count="$(find assets -type f -name '*.js' | wc -l | tr -d ' ')"
if [[ "${KOOPO_SKIP_JS:-0}" == "1" ]]; then
  echo "JavaScript syntax checks skipped explicitly (${js_count} files; Node is required by the default gate)."
else
  command -v node >/dev/null 2>&1 || { echo "Node is required for JavaScript syntax checks." >&2; exit 1; }
  find assets -type f -name '*.js' \
    -exec sh -c 'for file do node --check "$file" >/dev/null || exit 1; done' sh {} +
  echo "JavaScript syntax checks passed (${js_count} files)."
fi

test_files=(
  scripts/test-api-boundaries.php
  scripts/test-buddyboss-service-activity.php
  scripts/test-booking-invitations.php
  scripts/test-bootstrap-organization.php
  scripts/test-calendar-sync.php
  scripts/test-dashboard-asset-routing.php
  scripts/test-financial-integrity.php
  scripts/test-geocoding-router.php
  scripts/test-production-hardening.php
  scripts/test-provider-architecture.php
  scripts/test-provider-onboarding.php
  scripts/test-sms-provider.php
)

for test_file in "${test_files[@]}"; do
  php "$test_file"
done

if git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  git diff --check
fi

echo "Koopo Appointments static quality gate passed (${#test_files[@]} suites)."
