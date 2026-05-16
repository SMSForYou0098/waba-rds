---
name: Implementation Plan (4 vCPU / 8 GB Instance 1)
overview: ""
todos:
  - id: add-feeder-job
    content: Create CampaignFeederJob with round-robin, lock, and backpressure checks
    status: pending
  - id: wire-dispatcher
    content: Update CampaignDispatcher to enqueue feeder instead of flooding campaign-meta
    status: pending
  - id: add-feature-flag
    content: Gate fair-feeder path with config/env toggle for safe rollback
    status: pending
  - id: define-runbook
    content: Document worker commands and baseline process counts for Instance 1
    status: pending
  - id: run-concurrency-test
    content: Execute 5x100 concurrent campaign test and tune feeder slice/workers
    status: pending
isProject: false
---

# Implementation Plan (4 vCPU / 8 GB Instance 1)

## Goal

Support concurrent campaign runs with fair progress across users, targeting ~20s per 100 messages (instead of serial campaign completion), while keeping API/Reverb stable on the same instance.

## Architecture Decision

- Keep current per-message send job + token bucket.
- Add a **fair feeder scheduler** so one campaign cannot flood FIFO queue.
- Split workers by queue role (campaign vs webhook on separate instance).

## Target Runtime Topology

- **Instance 1 (API + Reverb + Campaign):**
  - API/PHP-FPM + Reverb
  - `campaign-meta` workers: start at 14, tune to 18 max
  - `campaign-feeder` worker: 1
- **Instance 2 (Webhook):** webhook workers only (already planned separately)
- Shared: Redis + RDS

## Code Changes

- **Add `CampaignFeederJob`** in [app/Jobs](app/Jobs)
  - Queue: `campaign-feeder`
  - Every tick (~1s), select active campaigns with `pending` rows
  - Round-robin dispatch limited rows per campaign (e.g. 10–20)
  - Re-dispatch self if pending still exists
  - Use cache lock (`campaign-feeder:lock`) to prevent overlapping feeders

- **Update `CampaignDispatcher::start`** in [app/Services/Campaign/CampaignDispatcher.php](app/Services/Campaign/CampaignDispatcher.php)
  - Keep row creation logic
  - Remove direct “dispatch all pending rows” flood behavior
  - Trigger one `CampaignFeederJob` instead

- **Keep `SendCampaignMessageJob` unchanged for send semantics** in [app/Jobs/SendCampaignMessageJob.php](app/Jobs/SendCampaignMessageJob.php)
  - Atomic claim, token bucket, retry/backoff, circuit-breaker remain source of truth

- **Queue config update** in [config/queue.php](config/queue.php) (if needed)
  - Ensure `campaign-feeder` and `campaign-meta` are explicit queues in runtime commands/supervisor config

## Scheduling Policy (Fairness)

- Per tick dispatch budget:
  - Global max dispatch per tick (e.g. 120)
  - Per-campaign slice (e.g. 15)
- Campaign selection order:
  - oldest `pending` activity first
  - then round-robin
- Backpressure:
  - If `jobs` table already has high `campaign-meta` depth (e.g. >300), feeder pauses that tick

## Recommended Worker Start Commands

- Campaign workers (Instance 1):
  - `php artisan queue:work redis --queue=campaign-meta --tries=1 --timeout=30 --sleep=0`
  - Run 14 processes initially
- Feeder worker (Instance 1):
  - `php artisan queue:work redis --queue=campaign-feeder --tries=1 --timeout=60 --sleep=1`
  - Run 1 process

## Load Test Plan

- Test set: 5 concurrent campaigns x 100 recipients each
- Success criteria:
  - All campaigns begin progress within first few seconds (no long starvation)
  - Each campaign completes around target band (~20–35s on first pass, then tune)
  - No sustained queue growth for `campaign-meta`
  - No burst of `130429`/429 errors

## Metrics to Watch During Tuning

- Queue depth: `campaign-meta`, `campaign-feeder`
- Campaign completion time per campaign
- Send attempts and retry ratio (`attempts > 1`)
- API latency and PHP-FPM response p95 on Instance 1
- Reverb timeout/errors

## Tuning Sequence

1. Start with per-campaign slice = 10, global tick budget = 100
2. If campaigns still serialize, increase per-campaign slice to 15
3. If API latency spikes, reduce campaign workers by 2
4. If queue lag grows with stable API, increase workers by 2 (max 18 on this instance)

## Rollback Safety

- Keep old behavior behind a feature flag toggle in dispatcher (`campaign_fair_feeder_enabled`)
- If issues appear, disable flag to return to current direct-dispatch path immediately

## Deliverables

- New feeder job + dispatcher wiring
- Feature flag for safe rollout
- Worker runbook commands
- Short load-test report with before/after timings