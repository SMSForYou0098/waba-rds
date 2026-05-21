---
# Balance archive

## 1. Migration: `balances_archive`

Same columns as `balances`:

`user_id`, `total_credits`, `alert_credit`, `new_credit`, `payment_type`, `account_manager_id`, `manual_deduction`, `auto_deduction`, `report_id`, `remarks`, `duplicate_count`, `created_at`, `updated_at`

Plus:

- `id` — new surrogate PK (bigIncrements)
- `original_id` — bigint, **unique** (was `balances.id`)
- `archived_at` — timestamp, default now

**Indexes on `balances`** (if missing):

- `(user_id, id DESC)`
- `(created_at)`

**Indexes on `balances_archive`:**

- `UNIQUE(original_id)`
- `(user_id, created_at)`

PostgreSQL only.

---

## 2. Config

`config/billing.php`:

- `archive.retention_days` → `BALANCE_ARCHIVE_RETENTION_DAYS` (default **90**)
- `archive.batch_size` → `BALANCE_ARCHIVE_BATCH_SIZE` (default **5000**)

---

## 3. Command: `balances:archive`

**File:** `app/Console/Commands/Billing/ArchiveBalancesCommand.php`

**Options:** `--dry-run`, `--batch=`

**Eligible rows** (all must match):

1. `created_at < now() - retention_days`
2. `id NOT IN (SELECT MAX(id) FROM balances GROUP BY user_id)` — never archive latest row per user

**Per batch** (transaction):

1. `INSERT INTO balances_archive` — copy row + `original_id = balances.id`, `archived_at = now()`
2. `DELETE FROM balances WHERE id IN (...)`

`--dry-run`: count only, no writes.

**Schedule** in `Kernel.php`: daily `02:00`, `withoutOverlapping()`.

---

## 4. BalanceController (minimal)

- **Admin `allHistory`:** paginate hot `balances` + `balances_archive` (union), don’t load full table.
- **Latest balance / user history:** no change (latest row stays in hot table).

---
