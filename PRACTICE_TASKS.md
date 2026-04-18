# JutForm Practice Tasks (Fake Assessment Set)

This file contains fake practice tickets that mimic the JutForm assessment format.
Each ticket includes only:
- User/Internal Report
- Expected Outcome

No solution or root cause is included.

## Ticket JF-101 | Bug | Medium
### User/Internal Report
When I submit the same form twice quickly, I sometimes get two confirmation emails and two records. I only clicked once. This happened on mobile with weak internet.

### Expected Outcome
Duplicate submissions are prevented for rapid repeat requests, and only one persisted submission plus one confirmation email is created per logical user action.

---

## Ticket JF-102 | Bug | Easy-Medium
### User/Internal Report
The status page says everything is healthy, but our internal monitor occasionally gets 500 from the health endpoint for 1-2 minutes and then it recovers.

### Expected Outcome
Health endpoint returns stable and accurate status codes, degrades gracefully when a dependency is briefly unavailable, and includes clear machine-readable error details.

---

## Ticket JF-103 | Bug | Medium
### User/Internal Report
Search by submitter name fails for Turkish characters. Ipek and Ipek with Turkish uppercase/lowercase variants produce inconsistent results, and sorting looks wrong.

### Expected Outcome
Search and ordering for non-English characters are consistent and locale-safe, with predictable behavior for case-insensitive matching.

---

## Ticket JF-104 | Feature | Medium
### User/Internal Report
Support team needs a way to filter submission logs by date range and event type in the admin log viewer. Right now we can only page through everything.

### Expected Outcome
Admin log query supports date range plus event type filters with pagination, and response metadata includes total count for UI pagination.

---

## Ticket JF-105 | Bug | Hard
### User/Internal Report
During traffic spikes, some background email jobs never send. Queue depth rises but workers do not clear old jobs unless we restart services.

### Expected Outcome
Queue processing is reliable under load, stuck jobs are retried according to policy, failed jobs are observable, and no silent drops occur.

---

## Ticket JF-106 | Bug | Medium
### User/Internal Report
CSV export occasionally has broken rows when fields contain commas, quotes, or line breaks. Finance says imported data shifts columns.

### Expected Outcome
CSV export is RFC-compliant for escaping and quoting, preserves multiline content safely, and always produces valid column alignment.

---

## Ticket JF-107 | Feature | Easy-Medium
### User/Internal Report
We need an endpoint to return daily submission counts for the last 30 days per form for dashboard charts.

### Expected Outcome
New API endpoint returns last-30-day time series per form with zero-filled missing days and efficient query performance.

---

## Ticket JF-108 | Bug | Medium
### User/Internal Report
Occasionally users receive a password reset email with an expired link immediately after requesting reset.

### Expected Outcome
Reset token lifecycle is correct, token validity window is consistent, and race conditions do not invalidate newly issued tokens.

---

## Ticket JF-109 | Security Bug | Hard
### User/Internal Report
Internal security check flagged that some endpoints accept unexpected query parameters and still execute operations. They want strict validation.

### Expected Outcome
Input validation rejects unknown and invalid parameters with proper 4xx responses, and sensitive operations enforce allowlisted request schemas.

---

## Ticket JF-110 | Feature | Medium-Hard
### User/Internal Report
Product wants soft-delete for submissions with a 30-day restore window. Deleted items should be excluded from default lists but recoverable.

### Expected Outcome
Soft-delete and restore flows are implemented with correct default filtering, restore capability within retention window, and permanent purge rules after expiry.

---

## Ticket JF-111 | Bug | Medium
### User/Internal Report
API sometimes returns 200 with an error message in body instead of proper HTTP status. Integrations cannot reliably detect failures.

### Expected Outcome
API uses consistent HTTP semantics, error payload format is standardized, and non-success scenarios use correct non-2xx codes.

---

## Ticket JF-112 | Performance Bug | Hard
### User/Internal Report
Submission listing endpoint is fast with small datasets but times out with larger customers. DB CPU spikes heavily when filtering by date and status.

### Expected Outcome
Endpoint remains responsive at realistic scale, query performance is optimized, and timeouts are eliminated for supported filter combinations.
