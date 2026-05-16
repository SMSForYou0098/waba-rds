---
name: Fair Campaign Feeder
overview: Replace direct job flooding in CampaignDispatcher with a fair-share feeder that round-robins across all active campaigns, so multiple concurrent campaigns utilize their full per-phone-number throughput simultaneously.
todos:
  - id: create-feeder-job
    content: Create CampaignFeederJob with round-robin logic, queue depth check, and self-re-dispatch
    status: pending
  - id: update-dispatcher
    content: Remove direct job dispatch from CampaignDispatcher; dispatch CampaignFeederJob instead
    status: pending
  - id: verify-fairness
    content: Test with 2+ concurrent campaigns and confirm interleaved progress
    status: pending
isProject: false
---

# Fair-Share Campaign Feeder

## Problem

`CampaignDispatcher::start()` pushes ALL per-message jobs into one FIFO Redis queue. When 5 campaigns run concurrently, campaign 1 monopolizes workers while campaigns 2-5 wait. Each idle campaign's phone number wastes its full 18/s token bucket capacity.

## Solution: Two-tier queue architecture

### Tier 1 -- Feeder (new)

A single `CampaignFeederJob` running on its own queue (`campaign-feeder`) that:
- Runs in a continuous loop (re-dispatches itself every ~1 second)
- Finds all campaigns with `pending` report rows
- Checks current `campaign-meta` queue depth; only feeds if below a threshold (e.g. 100 pending jobs)
- **Round-robins** across campaigns: picks up to `N` rows per campaign per tick (e.g. 20 per campaign)
- Dispatches `SendCampaignMessageJob` for those rows into `campaign-meta`
- When no campaigns have pending rows left, stops re-dispatching

### Tier 2 -- Workers (unchanged)

Existing `SendCampaignMessageJob` on `campaign-meta` queue: atomic claim, token bucket, Meta send, retry/backoff. No changes needed.

## File changes

### 1. New file: `app/Jobs/CampaignFeederJob.php`

- Queue: `campaign-feeder`
- `$tries = 1`, `$timeout = 60`
- `handle()` logic:
  1. Query distinct `campaign_id` values from `campaign_reports` where `status = 'pending'`
  2. For each campaign, pick up to 20 `pending` rows (ordered by `id`)
  3. Dispatch `SendCampaignMessageJob` for each
  4. If any campaigns still have pending rows, `self::dispatch()->delay(now()->addSecond())`
- Single-instance guard: use `Cache::add('campaign-feeder:running', ...)` with short TTL to prevent overlapping feeders

### 2. Modify: [app/Services/Campaign/CampaignDispatcher.php](app/Services/Campaign/CampaignDispatcher.php)

- **Remove** Pass 2 (the loop that dispatches `SendCampaignMessageJob` directly)
- After inserting report rows, dispatch **one** `CampaignFeederJob` (feeder is idempotent; if already running, the cache guard skips the duplicate)

### 3. Queue worker setup

- Keep existing `campaign-meta` workers (8+) for sending
- Add 1 worker for `campaign-feeder`:
  ```
  php artisan queue:work redis --queue=campaign-feeder --tries=1 --timeout=60 --sleep=1
  ```

## How fairness works

With 5 campaigns active and `N=20` rows per campaign per tick:
- Each tick: 100 jobs dispatched (20 per campaign), interleaved
- Workers process jobs from mixed campaigns concurrently
- Each phone number's token bucket is utilized simultaneously
- No campaign starves; throughput scales with worker count

## What stays unchanged

- `SendCampaignMessageJob` -- no changes
- `TokenBucket` -- no changes
- `MetaMessageSender` -- no changes
- `CampaignSendService` -- no changes (calls `CampaignDispatcher::start()` same as now)
- Reverb progress/completion broadcasting -- no changes
