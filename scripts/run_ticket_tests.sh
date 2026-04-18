#!/usr/bin/env bash
set -u

BASE_URL="${BASE_URL:-http://localhost:8081}"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR" || exit 1

PASS_COUNT=0
FAIL_COUNT=0

pass() {
  PASS_COUNT=$((PASS_COUNT + 1))
  printf "[PASS] %s\n" "$1"
}

fail() {
  FAIL_COUNT=$((FAIL_COUNT + 1))
  printf "[FAIL] %s\n" "$1"
  if [[ -n "${2:-}" ]]; then
    printf "       %s\n" "$2"
  fi
}

run_test() {
  local name="$1"
  shift
  if "$@"; then
    pass "$name"
  else
    fail "$name" "Expected behavior not met"
  fi
}

json_expr() {
  local expr="$1"
  python3 -c "import json,sys; data=json.load(sys.stdin); print($expr)"
}

safe_http_code() {
  local url="$1"
  shift
  curl -s -o /dev/null -w "%{http_code}" "$@" "$url"
}

# JF-101: duplicate submissions should not create duplicate records.
test_jf_101() {
  local email="ticket101_$(date +%s)@example.com"
  local key="submission:testform:${email}"

  local before
  before=$(docker compose exec -T mysql mysql -N -u jutform -pjutform_secret -D jutform -e "SELECT COUNT(*) FROM preflight_check WHERE check_name='${key}';" 2>/dev/null | tr -d '\r')
  [[ -z "$before" ]] && before=0

  local payload
  payload="{\"form_id\":\"testform\",\"email\":\"${email}\"}"

  safe_http_code "$BASE_URL/api/forms/submit" -X POST -H 'Content-Type: application/json' -d "$payload" >/dev/null
  safe_http_code "$BASE_URL/api/forms/submit" -X POST -H 'Content-Type: application/json' -d "$payload" >/dev/null

  local after
  after=$(docker compose exec -T mysql mysql -N -u jutform -pjutform_secret -D jutform -e "SELECT COUNT(*) FROM preflight_check WHERE check_name='${key}';" 2>/dev/null | tr -d '\r')
  [[ -z "$after" ]] && after=0

  local delta=$((after - before))
  [[ "$delta" -le 1 ]]
}

# JF-102: health should be stable while dependencies are healthy.
test_jf_102() {
  local non200=0
  for _ in $(seq 1 12); do
    local code
    code=$(safe_http_code "$BASE_URL/api/health")
    if [[ "$code" != "200" ]]; then
      non200=$((non200 + 1))
    fi
  done
  [[ "$non200" -eq 0 ]]
}

# JF-103: Turkish-case searches should be consistent.
test_jf_103() {
  local r1 r2 s1 s2
  r1=$(curl -s "$BASE_URL/api/submissions/search?q=ipek")
  r2=$(curl -s "$BASE_URL/api/submissions/search?q=%C4%B0pek")

  s1=$(printf "%s" "$r1" | json_expr '"|".join(sorted([x.get("name", "") for x in data.get("items", [])]))')
  s2=$(printf "%s" "$r2" | json_expr '"|".join(sorted([x.get("name", "") for x in data.get("items", [])]))')

  [[ "$s1" == "$s2" ]]
}

# JF-104: filters should apply and response should include total metadata.
test_jf_104() {
  local response
  response=$(curl -s "$BASE_URL/api/admin/logs?event=submit&date_from=2026-04-01&date_to=2026-04-30&page=1&per_page=10")

  local only_submit has_total
  only_submit=$(printf "%s" "$response" | json_expr 'all((item.get("event") == "submit") for item in data.get("items", []))')
  has_total=$(printf "%s" "$response" | json_expr '"total" in data')

  [[ "$only_submit" == "True" && "$has_total" == "True" ]]
}

# JF-105: queue processing should be FIFO and not starve old jobs.
test_jf_105() {
  docker compose exec -T php-fpm sh -lc 'rm -f /tmp/jutform-practice-queue.json' >/dev/null 2>&1 || true

  curl -s -X POST "$BASE_URL/api/queue/enqueue" -H 'Content-Type: application/json' -d '{"type":"a"}' >/dev/null
  curl -s -X POST "$BASE_URL/api/queue/enqueue" -H 'Content-Type: application/json' -d '{"type":"b"}' >/dev/null
  curl -s -X POST "$BASE_URL/api/queue/enqueue" -H 'Content-Type: application/json' -d '{"type":"c"}' >/dev/null

  curl -s -X POST "$BASE_URL/api/queue/process" >/dev/null

  local queue_json types
  queue_json=$(docker compose exec -T php-fpm sh -lc 'cat /tmp/jutform-practice-queue.json 2>/dev/null || echo []' | tr -d '\r')
  types=$(printf "%s" "$queue_json" | python3 -c 'import json,sys; d=json.load(sys.stdin); print(",".join([x.get("type","") for x in d]))')

  [[ "$types" == "b,c" ]]
}

# JF-106: CSV should parse cleanly with 3 columns per row.
test_jf_106() {
  local csv
  csv=$(curl -s "$BASE_URL/api/export/csv")

  printf "%s" "$csv" | python3 -c '
import csv, io, sys
text = sys.stdin.read()
rows = list(csv.reader(io.StringIO(text)))
ok = len(rows) >= 2 and all(len(r) == 3 for r in rows)
print("OK" if ok else "NO")
' | grep -q '^OK$'
}

# JF-107: daily stats should contain 30 consecutive days.
test_jf_107() {
  local response
  response=$(curl -s "$BASE_URL/api/stats/daily-submissions")

  printf "%s" "$response" | python3 -c '
import json, sys
from datetime import date
obj = json.load(sys.stdin)
days = obj.get("days", [])
if len(days) != 30:
    print("NO")
    raise SystemExit(0)
vals = [date.fromisoformat(d["date"]) for d in days]
vals_sorted = sorted(vals)
ok = all((vals_sorted[i+1] - vals_sorted[i]).days == 1 for i in range(len(vals_sorted)-1))
print("OK" if ok else "NO")
' | grep -q '^OK$'
}

# JF-108: reset token expiry should be in the future.
test_jf_108() {
  local response
  response=$(curl -s -X POST "$BASE_URL/api/auth/request-reset" -H 'Content-Type: application/json' -d '{"email":"user@example.com"}')

  printf "%s" "$response" | python3 -c '
import json, sys
from datetime import datetime, timezone
obj = json.load(sys.stdin)
exp = obj.get("expires_at")
if not exp:
    print("NO")
    raise SystemExit(0)
exp_dt = datetime.fromisoformat(exp.replace("Z", "+00:00"))
now = datetime.now(timezone.utc)
print("OK" if exp_dt > now else "NO")
' | grep -q '^OK$'
}

# JF-109: unexpected params should be rejected with 4xx.
test_jf_109() {
  local code
  code=$(safe_http_code "$BASE_URL/api/admin/action?unsafe=1&drop=true" -X POST -H 'Content-Type: application/json' -d '{"action":"delete_all","unexpected":"yes"}')
  [[ "$code" =~ ^4 ]]
}

# JF-110: restore should succeed after soft delete.
test_jf_110() {
  local delete_code restore_code
  delete_code=$(safe_http_code "$BASE_URL/api/submissions/delete" -X POST -H 'Content-Type: application/json' -d '{"id":123}')
  restore_code=$(safe_http_code "$BASE_URL/api/submissions/restore" -X POST -H 'Content-Type: application/json' -d '{"id":123}')

  [[ "$delete_code" == "200" && "$restore_code" == "200" ]]
}

# JF-111: invalid request should not return 200.
test_jf_111() {
  local code
  code=$(safe_http_code "$BASE_URL/api/forms/submit" -X POST -H 'Content-Type: application/json' -d '{}')
  [[ "$code" =~ ^4|^5 ]]
}

# JF-112: listing endpoint should stay under threshold for large page.
test_jf_112() {
  local start_ms end_ms duration
  start_ms=$(date +%s%3N)
  curl -s "$BASE_URL/api/submissions/list?limit=500" >/dev/null
  end_ms=$(date +%s%3N)
  duration=$((end_ms - start_ms))

  [[ "$duration" -lt 1200 ]]
}

run_test "JF-101 duplicate submit protection" test_jf_101
run_test "JF-102 health stability" test_jf_102
run_test "JF-103 Turkish search consistency" test_jf_103
run_test "JF-104 logs filtering + metadata" test_jf_104
run_test "JF-105 queue fairness" test_jf_105
run_test "JF-106 CSV escaping" test_jf_106
run_test "JF-107 daily zero-fill" test_jf_107
run_test "JF-108 reset token expiry" test_jf_108
run_test "JF-109 strict validation" test_jf_109
run_test "JF-110 soft delete + restore" test_jf_110
run_test "JF-111 HTTP status semantics" test_jf_111
run_test "JF-112 large list performance" test_jf_112

echo
echo "Summary: PASS=$PASS_COUNT FAIL=$FAIL_COUNT"

if [[ "$FAIL_COUNT" -gt 0 ]]; then
  exit 1
fi
