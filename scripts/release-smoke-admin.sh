#!/usr/bin/env bash

set -euo pipefail

SITE_URL="${SITE_URL:-${1:-}}"
WP_USER="${WP_USER:-${2:-}}"
WP_PASS="${WP_PASS:-${3:-}}"
SKIP_LOGIN="${SKIP_LOGIN:-0}"
COOKIE_JAR_PATH="${COOKIE_JAR_PATH:-}"

COOKIE_JAR_CREATED=0
COOKIE_JAR=""
RESPONSE_BODY=""
RESPONSE_STATUS=""

if [[ -z "${SITE_URL}" || -z "${WP_USER}" || -z "${WP_PASS}" ]]; then
  echo "Usage: SITE_URL=http://localhost:8085 WP_USER=admin WP_PASS=secret bash scripts/release-smoke-admin.sh"
  echo "   or: bash scripts/release-smoke-admin.sh http://localhost:8085 admin secret"
  exit 1
fi

SITE_URL="${SITE_URL%/}"
cleanup() {
  if [[ "${COOKIE_JAR_CREATED}" == "1" && -n "${COOKIE_JAR}" ]]; then
    rm -f "${COOKIE_JAR}"
  fi
}

fail() {
  echo "[FAIL] $1" >&2
  exit 1
}

fail_with_response() {
  local message="$1"

  echo "[FAIL] ${message}" >&2
  [[ -n "${RESPONSE_STATUS}" ]] && echo "Status: ${RESPONSE_STATUS}" >&2

  if [[ -n "${RESPONSE_BODY}" ]]; then
    echo "Response excerpt:" >&2
    printf '%s\n' "${RESPONSE_BODY}" | sed -n '1,12p' >&2
  fi

  exit 1
}

pass() {
  echo "[PASS] $1"
}

extract_nonce() {
  local page_html="$1"

  printf '%s' "${page_html}" \
    | rg -o '"nonce":"[^"]+"' \
    | head -1 \
    | sed 's/"nonce":"//; s/"$//'
}

extract_asset_url() {
  local page_html="$1"

  printf '%s' "${page_html}" \
    | rg -o 'https?://[^"]+/admin-dashboard\.js\?ver=[^"]+' \
    | head -1
}

require_tools() {
  command -v curl >/dev/null 2>&1 || fail "curl is required"
  command -v rg >/dev/null 2>&1 || fail "rg is required"
}

prepare_cookie_jar() {
  if [[ -n "${COOKIE_JAR_PATH}" ]]; then
    COOKIE_JAR="${COOKIE_JAR_PATH}"
    touch "${COOKIE_JAR}"
    return
  fi

  COOKIE_JAR="$(mktemp)"
  COOKIE_JAR_CREATED=1
}

request() {
  local method="$1"
  local url="$2"
  shift 2
  local result

  result="$(
    curl -sS -L \
      -X "${method}" \
      -b "${COOKIE_JAR}" \
      -c "${COOKIE_JAR}" \
      -w $'\n%{http_code}' \
      "$@" \
      "${url}"
  )"

  RESPONSE_STATUS="${result##*$'\n'}"
  RESPONSE_BODY="${result%$'\n'*}"
}

login() {
  request POST "${SITE_URL}/wp-login.php" \
    --data-urlencode "log=${WP_USER}" \
    --data-urlencode "pwd=${WP_PASS}" \
    --data-urlencode "redirect_to=${SITE_URL}/wp-admin/" \
    --data "wp-submit=Log In" \
    --data "testcookie=1"

  [[ "${RESPONSE_STATUS}" == "200" ]] || fail_with_response "WordPress login request failed"

  if ! rg -q 'wordpress_logged_in_' "${COOKIE_JAR}"; then
    fail_with_response "WordPress login failed"
  fi

  pass "Authenticated WordPress admin session"
}

fetch_admin_page() {
  local slug="$1"
  request GET "${SITE_URL}/wp-admin/admin.php?page=${slug}"
  [[ "${RESPONSE_STATUS}" == "200" ]] || fail_with_response "Admin page request failed for ${slug}"
  printf '%s' "${RESPONSE_BODY}"
}

assert_admin_page() {
  local label="$1"
  local html="$2"

  [[ "${html}" == *"KOOPO_ADMIN"* ]] || fail "${label} page is missing localized KOOPO_ADMIN config"
  [[ "${html}" == *"koopo-admin-dashboard-js"* ]] || fail "${label} page is missing the admin dashboard script handle"

  pass "${label} page renders with localized admin config"
}

assert_asset() {
  local asset_url="$1"
  local headers

  [[ -n "${asset_url}" ]] || fail "Could not find admin-dashboard.js asset URL"

  headers="$(curl -I -s "${asset_url}")"
  [[ "${headers}" == *"200 OK"* ]] || fail "Admin dashboard asset did not return 200 OK"

  pass "Admin dashboard asset responds successfully"
}

assert_json_endpoint() {
  local label="$1"
  local nonce="$2"
  local url="$3"
  local expected_fragment="$4"
  local response

  request GET "${url}" -H "X-WP-Nonce: ${nonce}"
  [[ "${RESPONSE_STATUS}" == "200" ]] || fail_with_response "${label} endpoint request failed"
  response="${RESPONSE_BODY}"
  [[ "${response}" == *"${expected_fragment}"* ]] || fail "${label} endpoint returned an unexpected response"

  pass "${label} endpoint returned expected JSON"
}

assert_export_endpoint() {
  local nonce="$1"
  local response

  request POST "${SITE_URL}/wp-json/koopo/v1/admin/export" \
    -H "X-WP-Nonce: ${nonce}" \
    -H "Content-Type: application/json" \
    -d '{"status":"all"}'

  [[ "${RESPONSE_STATUS}" == "200" ]] || fail_with_response "Admin export endpoint request failed"
  response="${RESPONSE_BODY}"

  [[ "${response}" == *'"csv"'* ]] || fail "Admin export endpoint did not return CSV payload data"
  [[ "${response}" == *'"filename"'* ]] || fail "Admin export endpoint did not include an export filename"

  pass "Admin export endpoint returned CSV payload"
}

main() {
  local dashboard_html
  local bookings_html
  local nonce
  local asset_url

  trap cleanup EXIT

  require_tools
  prepare_cookie_jar

  if [[ "${SKIP_LOGIN}" == "1" ]]; then
    [[ -s "${COOKIE_JAR}" ]] || fail "SKIP_LOGIN=1 requires a populated cookie jar"
    pass "Using existing authenticated admin session"
  else
    login
  fi

  dashboard_html="$(fetch_admin_page "koopo-appointments")"
  bookings_html="$(fetch_admin_page "koopo-appointments-bookings")"

  assert_admin_page "Dashboard" "${dashboard_html}"
  assert_admin_page "Bookings" "${bookings_html}"

  nonce="$(extract_nonce "${dashboard_html}")"
  [[ -n "${nonce}" ]] || fail "Failed to extract admin REST nonce"
  pass "Extracted admin REST nonce"

  asset_url="$(extract_asset_url "${dashboard_html}")"
  assert_asset "${asset_url}"

  assert_json_endpoint \
    "Admin stats" \
    "${nonce}" \
    "${SITE_URL}/wp-json/koopo/v1/admin/stats" \
    '"today"'

  assert_json_endpoint \
    "Admin bookings" \
    "${nonce}" \
    "${SITE_URL}/wp-json/koopo/v1/admin/bookings?per_page=2&status=all" \
    '"items"'

  assert_export_endpoint "${nonce}"

  echo
  echo "Release smoke test completed successfully."
}

main "$@"
