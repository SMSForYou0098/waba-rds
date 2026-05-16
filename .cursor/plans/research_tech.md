# TASK: High-Throughput WhatsApp Campaign Sender (Meta Cloud API) — **reference spec**

**Purpose:** Single source of truth for *behavior and architecture* until the module ships.  
**Note:** This file describes **logic**, not copy-paste production code. Implement in the repo using real model/table names (`campaign_reports` vs `campaign_messages`, etc.).

If this spec conflicts with existing code, **stop and reconcile** (do not assume the spec wins).

---

## 1. CONTEXT

- **LIVE multi-tenant** system; ship conservatively.
- Stack: **Laravel** (match `composer.json`), **Horizon** on **Redis**, **MySQL/MariaDB**, **Meta WhatsApp Cloud API**.
- Send endpoint: `POST https://graph.facebook.com/{version}/{phone-number-id}/messages`

---

## 2. GOAL

Send **10,000+** messages per campaign at the highest **sustainable** rate under a **20 messages/second per `phone_number_id`** operational cap, with:

- No duplicate sends (at most one Meta send per `(campaign, recipient)`).
- Per-recipient retry and failure tracking.
- Circuit breaker during sustained Meta **5xx**.
- Multi-tenant token / phone id resolution (existing resolver — no hardcoding).
- **Dry-run** for staging (`META_DRY_RUN=true`): no real HTTP, deterministic fake success.

---

## 3. HARD CONSTRAINTS

1. **Idempotency** — retries, crashes, double `start()`, or duplicate jobs must not produce a second successful Meta send for the same recipient in the same campaign.
2. **Throughput target** — implement **18 tokens/sec** with bucket capacity **18** (headroom under 20 mps). Tune only after measuring real Meta limits for the number.
3. **Global limiter** — one shared limiter per **`phone_number_id`** across **all** workers/hosts: **Redis + atomic Lua** (not Laravel `RateLimiter` / `Redis::throttle` for v1 — fixed-window edge bursts).
4. **Isolation** — one recipient’s failure must not block others (one row / one job).
5. **Multi-tenant** — resolve `access_token` and Meta phone id per tenant/campaign via **existing** code paths.
6. **Idempotent dispatcher** — `start($campaign)` safe to call more than once: no duplicate report rows; no duplicate sends.
7. **Circuit breaker** — on sustained Meta **5xx** (details in §5.6).
8. **No** `Http::pool()`, ReactPHP, Swoole, fibers, or Octane-only APIs in v1.
9. **Dry-run** must short-circuit before HTTP and still update DB like a success (fake `wamid`).

**Clarification — “burst”:** Starting with a **full bucket of 18** allows up to **18 sends in the first second** if workers keep the pipe full; that stays under a **20 mps** ceiling. It is not “unlimited burst.”

---

## 4. PRE-FLIGHT (read codebase before coding)

Report explicitly:

- [ ] `Campaign` model + table; recipient/contact model + table.
- [ ] **Campaign report table** real name and columns (this repo may use e.g. `campaign_reports` — verify).
- [ ] Tenant → **`access_token`** / **`phone_number_id`** resolver location.
- [ ] Existing **template / payload builder** for Meta JSON body.
- [ ] Existing **campaign job / service** (`CampaignProcessJob`, `CampaignSendService`, …) — **do not rip out** without an explicit migration plan; new design may be **additive** or a **v2 path** behind a flag.
- [ ] Horizon present? Redis queue connection name.

### 4.1 Schema expectations (verify; do not migrate without approval)

The report row must support at least:

- Identity: `id`, `campaign_id`, `recipient_id` (or equivalent unique pair with campaign).
- Routing: destination phone; Meta **`phone_number_id`** on row **or** guaranteed derivable from campaign (if only on campaign, dispatcher/job must still key limiter by that id).
- Payload: serialized Meta request body (JSON).
- Lifecycle: `status`, `attempts`, errors, `wamid`, `sent_at`, timestamps.

**Critical:** `UNIQUE(campaign_id, recipient_id)` (or equivalent) for `INSERT … ON DUPLICATE` / `insertOrIgnore` idempotency.

**Indexes:** `status`; composite helpful for sweeps: `(status, …)` / `(campaign_id, status)` depending on queries.

If the unique key is missing, **stop** — spec cannot guarantee idempotency.

---

## 5. ARCHITECTURE (corrected logic)

### 5.1 Token bucket (Redis Lua)

- **Key:** `meta:tb:{phone_number_id}`
- **Parameters:** capacity `18`, refill **`18` tokens per wall second**.
- **Script (single round-trip):** read stored `tokens` + last `ts` (ms); refill by elapsed time; cap at capacity; if `tokens >= 1`, subtract 1 and persist; else compute `wait_ms` until one token is available, persist refilled state **without** consuming.
- **Return to PHP:** `0` = token acquired; `> 0` = wait that many **milliseconds** before a retry **or** use release strategy (§5.4).
- **EXPIRE** on key (e.g. 60s idle) is fine — idle numbers reset bucket state.

**Implementation note:** Use `Redis::eval` with correct argument order for your Laravel Redis client (`numkeys`, then keys, then argv).

### 5.2 Job granularity — **one job per report row**

- Payload **pre-serialized** at enqueue/dispatch time so the job does not re-build template JSON from Eloquent.
- **Atomic claim:**  
  `UPDATE report SET status='sending', updated_at=… WHERE id=? AND status='pending'`  
  - If **0 rows** affected → **return immediately** (another worker, already sent, or duplicate job). **This is the main dedupe for duplicate queue entries.**

### 5.3 **ShouldBeUnique — do not rely on it in v1**

**Problem:** `ShouldBeUnique` + `$this->release()` interacts badly with Laravel’s unique lock/cache in some versions (re-dispatch, overlapping locks, TTL).

**Recommendation:** **Omit `ShouldBeUnique`.** Duplicate jobs for the same `report_id` are **harmless**: the second run fails the claim and exits. You may see extra queue noise; that is acceptable and simpler than debugging unique locks.

*(Optional later: `WithoutOverlapping` per id with short TTL — only if profiling shows duplicate jobs as a real cost.)*

### 5.4 Token wait policy (avoid tying up workers)

After `acquire()`:

- `wait_ms == 0` → proceed to send.
- `0 < wait_ms <= 1500` → `usleep(wait_ms * 1000)` then send (same process).
- `wait_ms > 1500` → **revert row to `pending`**, `$this->release(ceil(wait_ms / 1000))`, return (free the worker).

### 5.5 HTTP send + error classification

- **Retryable:** HTTP **429**, **5xx**; Meta codes **`130429`**, **`131056`**, **`368`**; connect/timeouts you classify as transient (e.g. `ConnectionException`, request timeout) — **document** what you treat as retryable.
- **Non-retryable:** 4xx (except 429), template/parameter errors, auth — mark **`failed`** (or **`skipped`** if business rules say so).
- **`130429` (throughput):** use delay **`max(5, your_backoff_step)`** seconds (Meta often needs seconds of headroom).
- **Backoff schedule** for generic retries (after recording attempt): e.g. `[2, 4, 8, 16, 30, 30, 30, 30]` seconds, **max 8 attempts** — align **job `$tries`** with this (Laravel counts **`release()`** toward attempts).

**On retryable failure:** persist error fields, increment `attempts`, set row back to **`pending`**, `$this->release($delay)`.

**On non-retryable or exhausted attempts:** set **`failed`**, log.

### 5.6 Circuit breaker (5xx storm)

- **Key:** `meta:cb:{phone_number_id}:fails`, **TTL 60s**.
- **Increment** only on **retryable** outcomes that are **HTTP ≥ 500** (not 429 unless you choose otherwise — **default: do not** increment on 429).
- **Reset** (delete key) on **successful** Meta response in dry-run or live.
- If counter **`> 50`**: revert row to **`pending`**, `release(15)`, **do not** call Meta (prevents hammering a dead endpoint).

### 5.7 Horizon / worker tuning

- Dedicated queue e.g. **`campaign-meta`**.
- **Several processes** (e.g. 4–8) help **hide HTTP latency**; **effective** send rate is still capped by the **token bucket**, not by process count.
- **Supervisor `tries`:** keep **`1`** only if the job **`handle()` never throws** on expected paths — failures must use **`release()`** or DB updates. If anything can throw unexpectedly, set Horizon **`tries` to 2** (or wrap `handle` in try/catch that converts to `failed` row + log) so one bug does not silently drop work.

**Align:** `public $tries` on the job class with your **release**-based retry budget (include backoff mapping).

### 5.8 Dispatcher — two passes

**Pass 1 — rows:** For each recipient chunk, build rows; **`insertOrIgnore`** (or equivalent) relying on **`UNIQUE(campaign_id, recipient_id)`**. No duplicate rows on repeated `start()`.

**Pass 2 — jobs:** Chunk **`pending`** rows for this `campaign_id` and **`dispatch` job** with `report_id` + `phone_number_id`.

**Repeated `start()`:** Pass 1 no-ops for existing pairs; Pass 2 enqueues **another** job per still-`pending` row. **Safe** because only one job wins the claim. *Optional optimization:* dispatch only rows **created** in Pass 1 (requires tracking inserted ids or a “dispatched_at” flag — **not required** for correctness).

### 5.9 Credentials in the job

Resolve **`access_token`** (and validate phone id) inside the job **after** claim, using the **existing** tenant resolver — token must match the campaign’s WABA/tenant.

---

## 6. FILES / MODULES (checklist)

Implement with real namespaces; names below are illustrative.

| Piece | Responsibility |
|--------|----------------|
| `TokenBucket` | Lua acquire; returns wait ms |
| `MetaMessageSender` | Dry-run gate; POST JSON; normalized result array |
| `SendCampaignMessageJob` | Claim → CB → bucket → send → persist; `release()` retries |
| `CampaignDispatcher` | `insertOrIgnore` chunks + dispatch jobs |
| `config/services.php` | `meta.api_version`, `meta.dry_run` |
| `config/horizon.php` | `campaign-meta` supervisor |

**Config example (illustrative):**

```php
'meta' => [
    'api_version' => env('META_API_VERSION', 'v25.0'),
    'dry_run' => filter_var(env('META_DRY_RUN', false), FILTER_VALIDATE_BOOL),
],
```

**Horizon supervisor (illustrative):** queue `campaign-meta`, `balance`, `minProcesses` / `maxProcesses`, `timeout`, `tries` per §5.7.

---

## 7. WIRING TASKS

1. Map **report table** and column names to this spec.
2. Wire **`resolveAccessToken`** (and phone id if needed) to existing services.
3. Wire **`buildPayload`** to existing Meta payload builder.
4. Align with current **`CampaignSendService` / `CampaignProcessJob`** — either feature flag a new path or plan replacement; avoid two conflicting senders in production without coordination.

---

## 8. WHAT NOT TO DO (v1)

- No HTTP parallel pools / async stacks listed in §3.
- No Laravel fixed-window rate limiter as the **primary** Meta throttle.
- No loading full recipient lists into memory.
- No hardcoded tokens or phone ids.
- No new migrations for the report table **without** explicit approval if columns are missing.

---

## 9. VERIFICATION CHECKLIST

- [ ] Schema: unique `(campaign_id, recipient_id)` exists.
- [ ] `META_DRY_RUN=true`: full campaign completes with `sent` + fake wamids, **zero** HTTP to Meta.
- [ ] Double `start()`: **no** extra report rows; **no** duplicate sends.
- [ ] Kill worker mid-job: **no** duplicate send for a row that reached `sent` (see §10 for stuck `sending`).
- [ ] Horizon shows `campaign-meta` workers.
- [ ] Non-retryable errors → `failed` + log context.

---

## 10. STUCK `sending` ROWS (required for production completeness)

If a worker dies **after** claim **`pending→sending`** but **before** final status, the row can stay **`sending`** forever.

**Options (pick one when implementing “done”):**

1. **Scheduled command** — reset `sending` older than **N minutes** to `pending` (N agreed with ops).
2. **Lease column** — `locked_at` / `lease_expires_at`; claim only if lease expired (more precise, slightly more schema).

Until that exists, document that **campaigns may stall** on hard kills. This is a **known gap** for “module complete,” not optional long-term.

---

## 11. IMPLEMENTATION DELIVERABLES (when finished)

1. Pre-flight report (models, table, resolver, payload builder, overlap with existing jobs).
2. List of files touched.
3. Assumptions and open questions.
4. Manual test steps (dry-run, double start, sample failure).

---

## 12. REVISION NOTES (logic fixes applied in this doc)

| Topic | Fix |
|--------|-----|
| `ShouldBeUnique` | Removed as default; **atomic claim** handles duplicate jobs. |
| Horizon `tries=1` | Clarified: only safe if job does not throw; consider `tries=2` or top-level try/catch. |
| Dispatcher Pass 2 | Documented duplicate job **noise** vs correctness; optional optimization called out. |
| Throughput “burst” | Clarified: capacity 18 ⇒ at most ~18 in first second, under 20 mps cap. |
| Snippets | Replaced error-prone pasted PHP with **contracts / algorithms**; implement in IDE. |
| Stuck `sending` | Elevated as **production requirement** with recovery options. |
