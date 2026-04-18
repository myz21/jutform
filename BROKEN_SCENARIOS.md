# Broken Scenario Map (Practice Branch)

Branch: practice/broken-backend

This file maps each fake ticket to an intentionally broken endpoint.
No fixes are included.

## JF-101 | Duplicate submission
- Endpoint: POST /api/forms/submit
- Example:
  - curl -s -X POST http://localhost:8081/api/forms/submit -H 'Content-Type: application/json' -d '{"form_id":"contact","email":"test@example.com"}'

## JF-102 | Intermittent health 500
- Endpoint: GET /api/health
- Behavior: Random transient 500 branch appears intermittently.

## JF-103 | Turkish search/sort inconsistency
- Endpoint: GET /api/submissions/search?q=ipek

## JF-104 | Log filtering feature missing
- Endpoint: GET /api/admin/logs?event=submit&date_from=2026-04-01&date_to=2026-04-30&page=2
- Behavior: Filters ignored, missing total metadata.

## JF-105 | Queue jobs stuck under load
- Endpoint: POST /api/queue/enqueue
- Endpoint: POST /api/queue/process
- Behavior: Worker processes only newest job and leaves older ones.

## JF-106 | Broken CSV escaping
- Endpoint: GET /api/export/csv

## JF-107 | Daily stats missing zero-fill
- Endpoint: GET /api/stats/daily-submissions

## JF-108 | Reset token expires immediately
- Endpoint: POST /api/auth/request-reset
- Example:
  - curl -s -X POST http://localhost:8081/api/auth/request-reset -H 'Content-Type: application/json' -d '{"email":"user@example.com"}'

## JF-109 | Weak input validation
- Endpoint: POST /api/admin/action?unsafe=1&drop=true
- Behavior: Accepts arbitrary payload/params.

## JF-110 | Soft delete not implemented
- Endpoint: POST /api/submissions/delete
- Endpoint: POST /api/submissions/restore
- Behavior: Hard delete response, restore fails.

## JF-111 | Wrong HTTP status semantics
- Endpoint: /api/health and some error paths return 200 with failure payloads.

## JF-112 | Slow listing performance
- Endpoint: GET /api/submissions/list?limit=500
- Behavior: Intentional per-row delay simulates timeout-prone path.
