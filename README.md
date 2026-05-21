# Waba

WhatsApp Business API (WABA) backend for **SMS For You** — campaigns, messaging, billing, Meta onboarding, and chat APIs.

- **API:** `https://waba.smsforyou.biz` (typical)
- **Frontend:** React app at `https://web.smsforyou.biz` (separate repository)
- **Status webhooks / failed-message refunds:** sibling app [`waba_webhook`](../waba_webhook) (shared PostgreSQL)

## Stack

- Laravel 13, PHP 8.3
- PostgreSQL, Redis queues
- Laravel Passport + Spatie permissions
- Meta Graph API (Guzzle), Laravel Reverb

## Full project guide

**Read this first** (architecture, billing, campaign flow, split with webhook app):

→ **[docs/WABA_PROJECT.md](docs/WABA_PROJECT.md)**

Additional docs:

- [docs/META_MESSAGING_PROXY_API.md](docs/META_MESSAGING_PROXY_API.md)
- [docs/frontend-react-reverb-campaign.md](docs/frontend-react-reverb-campaign.md)

## Billing (short)

| When | Where | What |
|------|--------|------|
| User sends campaign | **Waba** — `CampaignSendService::persistCampaignWithDeduction()` | Debit `recipients × price` once; `balances.report_id = null`; `remarks = Campaign debit #<id>` |
| Meta reports `failed` / `cancelled` | **waba_webhook** | Credit per failed message (pricing model); `balance_refunds` prevents duplicates |

## Local setup

```bash
composer install
cp .env.example .env   # configure PostgreSQL, Redis, Meta keys
php artisan key:generate
php artisan migrate
php artisan queue:work redis --queue=campaign-meta,campaign-feeder
```

## Main API entry points

| Endpoint | Purpose |
|----------|---------|
| `POST /api/send-campaign` | Create campaign, debit balance, queue Meta sends |
| `POST /api/validate-campaign` | Price / balance check only |
| `GET/POST /api/send-messages` | API-key message send |
| `POST /api/messaging/send` | Authenticated Meta proxy |

---

For AI assistants: attach or reference **`docs/WABA_PROJECT.md`** for full system context.
