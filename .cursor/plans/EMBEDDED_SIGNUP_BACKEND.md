# Embedded Signup — Full Backend Flow
> Move the entire WhatsApp onboarding sequence server-side.  
> Frontend sends **one request**, backend runs all 6 steps in order, returns final status.

---

## Why Move Everything to Backend

Currently the frontend calls the onboarding backend step-by-step (step 1, step 2, … step 4 separately).  
The goal is one single endpoint:

```
POST /api/messaging/onboarding/complete
Body: { "code": "<oauth_code_from_fb_popup>" }
```

Backend handles everything internally — no more individual step calls from the browser.

---

## Architecture (separate from Meta proxy)

Keep onboarding logic **out of** [`MetaProxyController`](app/Http/Controllers/Meta/MetaProxyController.php). Use dedicated classes:

| File | Role |
|------|------|
| [`app/Http/Controllers/Messaging/OnboardingController.php`](app/Http/Controllers/Messaging/OnboardingController.php) | Thin HTTP: validate request, return JSON |
| [`app/Services/Meta/EmbeddedSignupService.php`](app/Services/Meta/EmbeddedSignupService.php) | Runs steps 0–4, **saves to DB after each successful step** |
| [`MetaGraphClient`](app/Services/Meta/MetaGraphClient.php) | All Meta HTTP (reuse existing) |

`POST /api/messaging/connect` (step 0 only, returns `access_token` to client — see [`MetaProxyController::connect`](app/Http/Controllers/Meta/MetaProxyController.php)) can stay for legacy or be deprecated once frontend uses `complete`. **`complete` must not return `meta_access_token`** — token stays server-side in `userconfigs` only.

---

## Incremental DB saves (fix “value not stored” issue)

**Problem today:** Frontend often saves everything **at the end**. If a late step fails, earlier values (token, WABA id, phone id) are lost even though Meta already returned them.

**Backend approach:** After **each successful Meta step**, persist what you have so far for `auth()->user()`:

| After step | Save to `userconfigs` | Set `onboarding_status` |
|------------|------------------------|-------------------------|
| 0 | `meta_access_token`, `app_id` | `token_exchanged` |
| 1 | `whatsapp_business_account_id` | `waba_resolved` |
| 2 | `userconfigs.whatsapp_phone_id` + **`users.whatsapp_number`** (digits only) | `phone_resolved` |
| 3 | (no new Meta fields) | `phone_registered` |
| 4 | (no new Meta fields) | `completed` |

If step 3 or 4 fails: **keep partial data** + status shows where it stopped (e.g. `phone_resolved`). User/UI can retry `complete` without losing token/WABA/phone (if token still valid).

**Migration:** add nullable `onboarding_status` string to `userconfigs`.

**Do not** accept `meta_access_token`, `user_id`, or WABA ids from the frontend on this flow — only OAuth `code`.

---

## Database schema (this codebase)

### Table `userconfigs` ([`UserConfig`](app/Models/Settings/UserConfig.php))

| Column | Used in onboarding | Notes |
|--------|-------------------|--------|
| `id` | — | PK |
| `user_id` | Yes | `bigint` (see migration `2026_04_29_000006`); FK to `users.id` |
| `app_id` | Yes | Set from `env('META_APP_ID')` |
| `whatsapp_business_account_id` | Yes | WABA id from debug_token (step 1) |
| `meta_access_token` | Yes | From OAuth exchange (step 0) |
| `whatsapp_phone_id` | Yes | Meta phone number id (step 2) |
| `business_account_id` | Optional | Set `null` (same as [`UserConfigController::create`](app/Http/Controllers/Settings/UserConfigController.php)) |
| `business_number`, `business_id` | No | Legacy columns from original migration; leave unchanged |
| `onboarding_status` | **New** | Migration required — not in DB yet |

There is **no** `whatsapp_number` column on `userconfigs`.

### Table `users` ([`User`](app/Models/User.php))

| Column | Used in onboarding |
|--------|-------------------|
| `whatsapp_number` | Yes — set in step 2 (`bigInteger`, nullable). Used by reports/campaigns via `display_phone_number` ↔ `whatsapp_number` joins |

### Eloquent

- `User::userConfig()` → `hasOne(UserConfig::class)` ([`User.php`](app/Models/User.php) line 109)
- Persist with `UserConfig::updateOrCreate(['user_id' => $user->id], $attributes)` inside a DB transaction per step

---

## Existing routes (do not confuse)

| Route | Purpose | Keep? |
|-------|---------|--------|
| `POST /api/messaging/connect` | Step 0 only via [`MetaProxyController`](app/Http/Controllers/Meta/MetaProxyController.php) | Legacy until frontend migrates |
| `POST /api/update-credential` | Admin/manual save — accepts token + ids **from request body** ([`UserConfigController`](app/Http/Controllers/Settings/UserConfigController.php)) | Keep for settings UI; **not** for embedded signup |
| `GET /api/get-credential/{id}` | Read config | Unchanged |

Embedded signup must use the **new** endpoint only, not `update-credential`.

---

## Auth & route registration

- **Guard:** Passport — `auth:api` (not Sanctum)
- **Register route in:** [`routes/api/meta.php`](routes/api/meta.php) inside existing `Route::prefix('messaging')->middleware(['auth:api'])` group (file is required from [`routes/api.php`](routes/api.php))
- **Full URL:** `POST /api/messaging/onboarding/complete`

```php
Route::post('onboarding/complete', [OnboardingController::class, 'complete']);
```

---

## The Single Frontend Call (After Migration)

```js
// Embedded.js — the only thing the frontend sends
const response = await apiClient.post('/api/messaging/onboarding/complete', {
  code: code   // OAuth code from the Facebook login popup
});

// Response shape
{
  "ok": true,
  "step_completed": 6,
  "onboarding_status": "completed",
  "waba_id": "123456789",
  "phone_id": "987654321",
  "phone_number": "919876543210"
}
```

The frontend only needs to:
1. Open the Facebook OAuth popup
2. Grab the `code` from the popup response
3. Call `POST /api/messaging/onboarding/complete` with that `code`
4. Show the step-by-step progress from the response

---

## All 6 Steps the Backend Must Run (In Order)

---

## Quick Sequence Summary (6 Steps / 5 Meta API Calls)

| Step | Type | Method | Meta Endpoint | Purpose | Input | Output |
|------|------|--------|---------------|---------|-------|--------|
| 0 | Meta API | GET | `/oauth/access_token` | Exchange OAuth `code` → `access_token` | `code` | `access_token` |
| 1 | Meta API | GET | `/debug_token` | Validate token, extract WABA ID | `access_token` | `waba_id` |
| 2 | Meta API | GET | `/{waba_id}/phone_numbers` | Get phone list, pick latest | `waba_id`, `access_token` | `phone_number_id`, `phone_number` |
| 3 | Meta API | POST | `/{phone_number_id}/register` | Register phone, set PIN | `phone_number_id`, `access_token` | `{ success: true }` |
| 4 | Meta API | POST | `/{waba_id}/subscribed_apps` | Subscribe app to webhooks | `waba_id`, `access_token` | `{ success: true }` |
| 5 | Internal | DB | `userconfigs` | Set `onboarding_status = completed` | — | Full row persisted incrementally |

> **Backend total:** 5 Meta API calls + **incremental DB writes after steps 0–4** (not one blob at the end).

---

### Step 0 — OAuth Code Exchange
**What:** Exchange the short-lived OAuth `code` for a long-lived `access_token`.

**Meta API Call:**
```
GET https://graph.facebook.com/v25.0/oauth/access_token
  ?client_id={META_APP_ID}
  &client_secret={META_APP_SECRET}
  &code={code}
```

**Env vars used:** `META_APP_ID`, `META_APP_SECRET`

**Input:** `code` from request body  
**Output:** `access_token` (used for ALL subsequent steps)

**On failure:** Return immediately — cannot proceed without a token.

```json
// Meta success response
{
  "access_token": "EAANGg6lYZBA...",
  "token_type": "bearer"
}
```

**Laravel (use existing [`MetaGraphClient::getWithQuery`](app/Services/Meta/MetaGraphClient.php)):**
```php
$base = rtrim((string) env('META_API_BASE', 'https://graph.facebook.com/v25.0'), '/');
$result = $this->graph->getWithQuery("{$base}/oauth/access_token", [
    'client_id'     => (string) env('META_APP_ID'),
    'client_secret' => (string) env('META_APP_SECRET'),
    'code'          => $code,
]);
$accessToken = $result['body']['access_token'] ?? null;
// Then persist: meta_access_token, app_id, onboarding_status = token_exchanged
```

---

### Step 1 — Debug Token → Get WABA ID
**What:** Validate the access token and extract the `whatsapp_business_account_id` (WABA ID).

**Meta API Call:**
```
GET https://graph.facebook.com/v25.0/debug_token
  ?input_token={access_token}
Authorization: Bearer {access_token}
```

**Input:** `access_token` from Step 0  
**Output:** `waba_id` (first target ID under `whatsapp_business_management` scope)

**On failure:** Return `{ ok: false, failed_at: 1, error: "..." }`

**Data extraction logic:**
```php
$data        = $response->json('data');
$scopes      = collect($data['granular_scopes']);
$wbmScope    = $scopes->firstWhere('scope', 'whatsapp_business_management');
$wabaId      = $wbmScope['target_ids'][0];  // take the first target ID
```

**Full Meta response shape:**
```json
{
  "data": {
    "granular_scopes": [
      {
        "scope": "whatsapp_business_management",
        "target_ids": ["123456789012345"]
      }
    ]
  }
}
```

---

### Step 2 — Get Phone Numbers → Extract Phone ID
**What:** Fetch all phone numbers linked to the WABA. Pick the **most recently onboarded** one.

**Meta API Call:**
```
GET https://graph.facebook.com/v25.0/{waba_id}/phone_numbers
Authorization: Bearer {access_token}
```

**Input:** `waba_id` from Step 1, `access_token` from Step 0  
**Output:** `phone_number_id`, `phone_number` (digits only, no spaces/dashes)

**On failure:** Return `{ ok: false, failed_at: 2, error: "No phone numbers found" }`

**Data extraction logic:**
```php
$numbers = collect($response->json('data'));

// Pick the most recently onboarded number
$latest = $numbers->sortByDesc(function ($item) {
    return strtotime($item['last_onboarded_time'] ?? '1970-01-01');
})->first();

$phoneNumberId = $latest['id'];
$phoneNumber   = preg_replace('/[^0-9]/', '', $latest['display_phone_number']);
// persistStep: whatsapp_phone_id on userconfigs + users.whatsapp_number → onboarding_status = phone_resolved
```

**Full Meta response shape:**
```json
{
  "data": [
    {
      "id": "987654321098765",
      "display_phone_number": "+91 98765 43210",
      "last_onboarded_time": "2024-12-01T10:00:00+0000",
      "verified_name": "My Business"
    }
  ]
}
```

---

### Step 3 — Register Phone (Set PIN)
**What:** Register the phone number for WhatsApp Cloud API and set the 6-digit PIN.

**Meta API Call:**
```
POST https://graph.facebook.com/v25.0/{phone_number_id}/register
Authorization: Bearer {access_token}
Content-Type: application/json

{
  "messaging_product": "whatsapp",
  "pin": "401402"
}
```

> **Note:** PIN `401402` is currently hardcoded. Move to `WHATSAPP_REGISTER_PIN` in `.env` so it can be changed without a code deploy.

**Input:** `phone_number_id` from Step 2, `access_token` from Step 0  
**Output:** `{ "success": true }`

**On failure:** Return `{ ok: false, failed_at: 3, error: "..." }` — do NOT save data if this fails.

```php
$result = $this->graph->post("{$base}/{$phoneId}/register", $accessToken, [
    'messaging_product' => 'whatsapp',
    'pin'               => (string) env('WHATSAPP_REGISTER_PIN', '401402'),
]);
// onboarding_status = phone_registered
```

---

### Step 4 — Subscribe App to WABA Webhook
**What:** Subscribe the Meta app to receive webhook events from this WABA.

**Meta API Call:**
```
POST https://graph.facebook.com/v25.0/{waba_id}/subscribed_apps
Authorization: Bearer {access_token}
```

**Input:** `waba_id` from Step 1, `access_token` from Step 0  
**Output:** `{ "success": true }`

**On failure:** Return `{ ok: false, failed_at: 4, error: "..." }`

```php
$result = $this->graph->post("{$base}/{$wabaId}/subscribed_apps", $accessToken);
// onboarding_status = completed
```

---

### Step 5 — Mark onboarding complete (Internal — No Meta API)
**What:** Final status only — credentials were already saved incrementally after steps 0–2.

**Internal DB Operation:**
```
UPDATE userconfigs
SET onboarding_status = 'completed'
WHERE user_id = {authenticated_user_id}
```

**On failure:** Return `{ ok: false, failed_at: 5, step_label: 'Finalize onboarding', error: '...' }` — earlier steps remain in DB.

---

## New Laravel route

Add to [`routes/api/meta.php`](routes/api/meta.php) (inside the existing `messaging` + `auth:api` group):

```php
use App\Http\Controllers\Messaging\OnboardingController;

Route::post('onboarding/complete', [OnboardingController::class, 'complete']);
```

---

## Implementation structure (backend standard)

Do **not** put all logic in the controller. Match existing patterns (`MetaGraphClient`, `CampaignSendService`, etc.).

### Files to create

```
app/Http/Controllers/Messaging/OnboardingController.php   # thin
app/Services/Meta/EmbeddedSignupService.php                # orchestration + incremental saves
database/migrations/xxxx_add_onboarding_status_to_userconfigs_table.php
```

### `OnboardingController` (thin)

```php
public function complete(Request $request, EmbeddedSignupService $service): JsonResponse
{
    $request->validate(['code' => 'required|string']);

    return response()->json(
        $service->complete(auth()->user(), $request->string('code')->toString()),
        // status code from result['http_status'] ?? 200
    );
}
```

### `EmbeddedSignupService` (core) — STRICT SEQUENTIAL EXECUTION

**The #1 rule:** Each Meta API call waits for the previous one to complete and persist before starting. No parallelism, no fire-and-forget. This is a synchronous pipeline — one fails, the whole chain stops.

**Why:** On the frontend, steps were fired in parallel / rapid succession causing race conditions (step 2 running before step 1 returned, values lost if step 3 failed because DB hadn't saved step 2 output yet). The backend eliminates this by making it a single blocking chain.

```php
public function complete(User $user, string $code): array
{
    $base = rtrim((string) env('META_API_BASE', 'https://graph.facebook.com/v25.0'), '/');

    // ─── Step 0: Exchange code ──────────────────────────────────────
    $result = $this->graph->getWithQuery("{$base}/oauth/access_token", [...]);
    $accessToken = $result['body']['access_token'] ?? null;
    if (!$accessToken) return $this->fail(0, 'OAuth Token Exchange', $result);
    $this->persistStep($user, ['meta_access_token' => $accessToken, 'app_id' => env('META_APP_ID')], null, 'token_exchanged');

    // ─── Step 1: Debug token → WABA ────────────────────────────────
    $result = $this->graph->get("{$base}/debug_token?input_token={$accessToken}", $accessToken);
    $wabaId = /* extract from granular_scopes */;
    if (!$wabaId) return $this->fail(1, 'Get WABA ID', $result);
    $this->persistStep($user, ['whatsapp_business_account_id' => $wabaId], null, 'waba_resolved');

    // ─── Step 2: Phone numbers ─────────────────────────────────────
    $result = $this->graph->get("{$base}/{$wabaId}/phone_numbers", $accessToken);
    $phone = /* sort by last_onboarded_time, pick first */;
    if (!$phone) return $this->fail(2, 'Get Phone Numbers', $result);
    $this->persistStep($user, ['whatsapp_phone_id' => $phone['id']], $phone['number'], 'phone_resolved');

    // ─── Step 3: Register ──────────────────────────────────────────
    $result = $this->graph->post("{$base}/{$phone['id']}/register", $accessToken, [...]);
    if (!($result['body']['success'] ?? false)) return $this->fail(3, 'Register Phone', $result);
    $this->persistStep($user, [], null, 'phone_registered');

    // ─── Step 4: Subscribe ─────────────────────────────────────────
    $result = $this->graph->post("{$base}/{$wabaId}/subscribed_apps", $accessToken, []);
    if (!($result['body']['success'] ?? false)) return $this->fail(4, 'Subscribe Webhook', $result);
    $this->persistStep($user, [], null, 'completed');

    return ['ok' => true, 'step_completed' => 5, 'onboarding_status' => 'completed', ...];
}
```

**Key design points:**

1. **Synchronous chain** — PHP is blocking by default; each `$this->graph->get/post()` blocks until response arrives. No async, no queues, no promises.
2. **Persist BEFORE next step** — `persistStep()` commits a `DB::transaction` to disk before the next Meta call starts. If the server crashes mid-flow, the DB has everything up to the last successful step.
3. **Fail fast, fail clearly** — the `fail()` helper returns immediately with `failed_at`, `step_label`, last `onboarding_status` (from DB, proving it was saved), and the Meta error message. No further steps execute.
4. **No retry inside the service** — if Meta returns an error, return it to the frontend. The user clicks "retry" which calls `complete` again. Because steps 0-N were persisted, a re-run with a **new code** is safe (idempotent `updateOrCreate`).
5. **Single access_token flows through** — captured once in step 0, used for steps 1–4 as a local variable. Never re-fetched.

### `persistStep` implementation

```php
private function persistStep(User $user, array $configAttrs, ?string $phoneNumber, string $status): void
{
    DB::transaction(function () use ($user, $configAttrs, $phoneNumber, $status) {
        UserConfig::updateOrCreate(
            ['user_id' => $user->id],
            array_merge($configAttrs, ['onboarding_status' => $status])
        );

        if ($phoneNumber !== null) {
            $user->update(['whatsapp_number' => (int) $phoneNumber]);
        }
    });
}
```

### `fail` helper

```php
private function fail(int $step, string $label, array $result): array
{
    return [
        'ok'                => false,
        'failed_at'         => $step,
        'step_label'        => $label,
        'onboarding_status' => $this->lastStatus ?? null,
        'error'             => $result['body']['error']['message'] ?? 'Unknown error',
        'http_status'       => $result['status'] >= 400 ? 422 : 502,
    ];
}
```

### After onboarding

[`ResolvesMetaCredentials`](app/Traits/ResolvesMetaCredentials.php) (used by proxy) expects `userConfig` with `meta_access_token`, `whatsapp_phone_id`, `whatsapp_business_account_id` — all set by step 2. Once `onboarding_status === 'completed'`, the proxy routes work immediately — no restart needed.

---


## New `.env` Variables to Add

```env
# Already added from BACKEND_PROXY_PLAN.md
META_APP_ID=921956225972226
META_APP_SECRET=your_app_secret_here
META_API_BASE=https://graph.facebook.com/v25.0

# New — PIN used when registering phone number on Cloud API
WHATSAPP_REGISTER_PIN=401402
```

---

## Success / Error Response Shapes

### Success
```json
{
  "ok": true,
  "step_completed": 6,
  "waba_id": "123456789012345",
  "phone_id": "987654321098765",
  "phone_number": "919876543210"
}
```

### Failure (example: Step 2 failed)
```json
{
  "ok": false,
  "failed_at": 2,
  "step_label": "Get Phone Numbers",
  "error": "No phone numbers found for this WABA"
}
```

The frontend uses `failed_at` to show the user exactly which step failed in the progress modal.

---

## Frontend Changes After This (Embedded.js)

Remove all the individual step functions and replace `executeSequentialSteps` with:

```js
// Single call — backend handles steps 0 → 5
const handleCompleteOnboarding = async (code) => {
  setOpen(true);
  try {
    const response = await apiClient.post('/api/messaging/onboarding/complete', { code });
    const data = response.data;

    if (data.ok) {
      // All 6 steps done — update UI
      setLoadingState({ step1: true, step2: true, step3: true, step4: true });
      setWabaID(data.waba_id);
      setPhoneNumberId(data.phone_id);
      setPhoneNumber(data.phone_number);
      setComepleted(true);
      setDisable(false);
      message.success('WhatsApp account connected successfully!');
    } else {
      // Show which step failed
      message.error(`Setup failed at Step ${data.failed_at}: ${data.error}`);
    }
  } catch (error) {
    message.error('An unexpected error occurred during setup.');
  }
};

// In exchangeCodeForAccessToken:
const exchangeCodeForAccessToken = async (code) => {
  await handleCompleteOnboarding(code);
};
```

**Functions to delete from `Embedded.js` after backend is ready:**
- `testAPI()`
- `getWABAId()`
- `getPhoneId()`
- `setWBAPin()`
- `SubWebhook()`
- `HandleSaveData()`
- `executeSequentialSteps()`
- All individual `/api/messaging/onboarding` step calls

---

## Step Flow Diagram

```
Frontend                        Backend                         Meta API
   │                               │                               │
   │  POST /onboarding/complete    │                               │
   │  { code }                     │                               │
   │ ─────────────────────────────>│                               │
   │                               │  Step 0: /oauth/access_token  │
   │                               │ ─────────────────────────────>│
   │                               │<─────────────────────────────│
   │                               │  access_token                 │
   │                               │                               │
   │                               │  Step 1: /debug_token         │
   │                               │ ─────────────────────────────>│
   │                               │<─────────────────────────────│
   │                               │  waba_id                      │
   │                               │                               │
   │                               │  Step 2: /{waba_id}/phone_numbers
   │                               │ ─────────────────────────────>│
   │                               │<─────────────────────────────│
   │                               │  phone_id, phone_number       │
   │                               │                               │
   │                               │  Step 3: /{phone_id}/register │
   │                               │ ─────────────────────────────>│
   │                               │<─────────────────────────────│
   │                               │  { success: true }            │
   │                               │                               │
   │                               │  Step 4: /{waba_id}/subscribed_apps
   │                               │ ─────────────────────────────>│
   │                               │<─────────────────────────────│
   │                               │  { success: true }            │
   │                               │                               │
   │                               │  (after each Meta step:       │
   │                               │   UPDATE userconfigs + users) │
   │                               │                               │
   │<─────────────────────────────│                               │
   │  { ok: true, waba_id, ... }   │                               │
```

---

## Rules for Implementation (non-negotiable)

1. **STRICT SEQUENTIAL** — step N+1 must NOT start until step N has (a) received Meta response, (b) validated it, (c) persisted to DB. PHP's synchronous nature guarantees this — do NOT use queues/events/async for this flow.
2. **PERSIST BEFORE PROCEED** — `persistStep()` inside `DB::transaction` commits before the next `$this->graph->...()` call. This is the fix for the frontend bug where values were lost.
3. **FAIL FAST** — first Meta error = immediate return. No retries, no skipping steps, no "best effort".
4. **ONE transaction per step** — NOT one big transaction wrapping all 5 steps. Reason: if step 4 fails, steps 0–3 data is already committed and safe.
5. **PIN from `.env`** (`WHATSAPP_REGISTER_PIN`) — never hardcoded.
6. **Phone number selection** — if a WABA has multiple numbers, pick the one with the most recent `last_onboarded_time`.
7. **`auth()->user()`** — identify user from Passport token, never from request body.
8. **No token in response** — `meta_access_token` stays in DB only. Unlike `connect`, `complete` does not return it to the client.
9. **Idempotent on retry** — `updateOrCreate` means calling `complete` again with a new code safely overwrites previous partial data.
10. **Block messaging** for tenants where `onboarding_status !== 'completed'` (optional middleware, follow-up PR).
