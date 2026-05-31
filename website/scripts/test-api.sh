#!/usr/bin/env bash
# Integration tests for Bedda PHP APIs (run from repo root).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
API="$ROOT/api"
PORT="${BEDDA_TEST_PORT:-8765}"
BASE="http://127.0.0.1:${PORT}"
PID=""

cleanup() {
  if [[ -n "$PID" ]] && kill -0 "$PID" 2>/dev/null; then
    kill "$PID" 2>/dev/null || true
    wait "$PID" 2>/dev/null || true
  fi
  rm -f "$API/secure-config.php"
}
trap cleanup EXIT

echo "==> Preparing test config"
cp "$API/secure-config.test.php" "$API/secure-config.php"
rm -f "$API/data/test-bedda.sqlite"

echo "==> Starting PHP server on $BASE"
php -S "127.0.0.1:${PORT}" -t "$ROOT" >/tmp/bedda-php-server.log 2>&1 &
PID=$!
sleep 1

fail() {
  echo "FAIL: $1"
  exit 1
}

assert_status() {
  local url="$1" expected="$2" method="${3:-GET}" data="${4:-}"
  local code
  if [[ -n "$data" ]]; then
    code=$(curl -sS -o /tmp/bedda_test_body.json -w "%{http_code}" -X "$method" -H "Content-Type: application/json" -d "$data" "$url")
  else
    code=$(curl -sS -o /tmp/bedda_test_body.json -w "%{http_code}" -X "$method" "$url")
  fi
  if [[ "$code" != "$expected" ]]; then
    echo "Response body:"
    cat /tmp/bedda_test_body.json || true
    fail "$url expected HTTP $expected got $code"
  fi
  echo "OK $method $url -> $expected"
}

assert_json_field() {
  local field="$1" expected="$2"
  python3 - <<PY || fail "JSON check failed for $field"
import json
d = json.load(open("/tmp/bedda_test_body.json"))
parts = "$field".split(".")
cur = d
for p in parts:
    cur = cur[p]
exp = "$expected"
if exp == "True":
    exp_val = True
elif exp == "False":
    exp_val = False
else:
    exp_val = exp
if cur != exp_val:
    raise SystemExit(f"got {cur!r} expected {exp_val!r}")
PY
  echo "OK json $field == $expected"
}

echo "==> health.php"
assert_status "$BASE/api/health.php" "200"
assert_json_field "ok" "True"

echo "==> log-event.php (batch)"
assert_status "$BASE/api/log-event.php" "200" "POST" '{"events":[{"event_type":"test","session_id":"s1","user_id":"u1","page":"/","data":{}}]}'
assert_json_field "success" "True"

echo "==> log-event.php (single beacon-style)"
assert_status "$BASE/api/log-event.php" "200" "POST" '{"type":"ai_query","userId":"u2","sessionId":"s2","page":"/index.html"}'
assert_json_field "success" "True"

echo "==> customer-auth.php?action=me (guest)"
assert_status "$BASE/api/customer-auth.php?action=me" "401"
assert_json_field "success" "False"

echo "==> register + login flow"
EMAIL="test_$(date +%s)@example.com"
assert_status "$BASE/api/customer-auth.php" "201" "POST" "{\"action\":\"register\",\"email\":\"$EMAIL\",\"password\":\"testpass12\",\"first_name\":\"Test\",\"last_name\":\"User\"}"
assert_json_field "success" "True"

# New session for me check (cookie jar)
curl -sS -c /tmp/bedda_cookies.txt -b /tmp/bedda_cookies.txt \
  -X POST -H "Content-Type: application/json" \
  -d "{\"action\":\"login\",\"email\":\"$EMAIL\",\"password\":\"testpass12\"}" \
  "$BASE/api/customer-auth.php" -o /tmp/bedda_test_body.json
code=$(curl -sS -b /tmp/bedda_cookies.txt -o /tmp/bedda_test_body.json -w "%{http_code}" "$BASE/api/customer-auth.php?action=me")
[[ "$code" == "200" ]] || fail "me after login expected 200 got $code"
assert_json_field "success" "True"

echo ""
echo "All API integration tests passed."
