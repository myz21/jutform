# Ticket Test Cases (Runbook)

This runbook executes one validation per ticket in `PRACTICE_TASKS.md`.

## Prerequisites
- Containers running: `docker compose up -d`
- `curl` and `python3` installed
- Run from project root

## Run All Ticket Tests

```bash
bash scripts/run_ticket_tests.sh
```

Optional custom URL:

```bash
BASE_URL=http://localhost:8081 bash scripts/run_ticket_tests.sh
```

## What PASS Means
- Each test case checks the expected post-fix behavior for one ticket.
- If a ticket test is `PASS`, that ticket is likely solved.
- If a ticket test is `FAIL`, inspect endpoint behavior and DB/queue state for that ticket.

## Ticket Mapping
- JF-101: duplicate submit protection
- JF-102: health endpoint stability
- JF-103: Turkish search consistency
- JF-104: logs filtering and pagination metadata
- JF-105: queue fairness (old jobs not starved)
- JF-106: CSV escaping correctness
- JF-107: 30-day zero-filled stats
- JF-108: reset token future expiry
- JF-109: strict request validation
- JF-110: soft delete + restore flow
- JF-111: HTTP status semantics
- JF-112: large list performance threshold
