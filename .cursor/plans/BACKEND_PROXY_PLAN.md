# Backend Proxy Plan — Messaging API Migration
> Laravel Backend | All Meta/WhatsApp Graph API calls move server-side

---

## 1. Why This Migration

Currently the React frontend calls `graph.facebook.com` directly — exposing `wa_token`, `phone_id`, and even the **App Secret** in the browser's network tab. This migration moves every Meta API call to the Laravel backend. The frontend will only ever call `/api/messaging/...` on your own server.

**Security gains:**
- `wa_token` never leaves the server
- `META_APP_SECRET` never in frontend source code
- Meta API version (`v25.0`) becomes a backend-only detail
- Easy to add rate limiting, logging, and abuse prevention in one place

---

## 2. Existing `.env` Meta URLs (Already in Backend)

```env
WA_API_ANALYTICS='https://graph.facebook.com/v25.0/{{wapid}}?fields=conversation_analytics.start({{start_unix}}).end({{realtime_unix}}).granularity(DAILY).phone_numbers([]).dimensions([%22CONVERSATION_CATEGORY%22,%22CONVERSATION_TYPE%22,%22COUNTRY%22,%22PHONE%22])&access_token={{wa_token}}'

WA_API_MESSAGES='https://graph.facebook.com/v25.0/{{whatsapp_phone_id}}/messages'

WA_API_MEDIA='https://graph.facebook.com/v25.0/{{whatsapp_phone_id}}/media'

WA_API_TEMPLATES='https://graph.facebook.com/v25.0/{{whatsapp_business_account_id}}/message_templates?access_token={{wa_token}}&limit=500'

WA_API_READ_MESSAGE='https://graph.facebook.com/v25.0/{{whatsapp_phone_id}}/messages'
```

---

## 3. New `.env` Variables to Add

Add these to your Laravel `.env` (and `.env.example`):

```env
# -----------------------------------------------
# Meta App Credentials (move OUT of frontend code)
# -----------------------------------------------
META_APP_ID=921956225972226
META_APP_SECRET=your_app_secret_here        # REMOVE from React source code immediately

# -----------------------------------------------
# Meta Graph API Base
# -----------------------------------------------
META_API_BASE=https://graph.facebook.com/v25.0

# -----------------------------------------------
# New Meta URL Templates (missing from current env)
# -----------------------------------------------

# Flows
WA_API_FLOWS='https://graph.facebook.com/v25.0/{{whatsapp_business_account_id}}/flows'
WA_API_FLOW_ACTION='https://graph.facebook.com/v25.0/{{flow_id}}'         # DELETE / GET
WA_API_FLOW_PUBLISH='https://graph.facebook.com/v25.0/{{flow_id}}/publish'

# Templates (submit / delete — separate from fetch)
WA_API_TEMPLATE_SUBMIT='https://graph.facebook.com/v25.0/{{whatsapp_business_account_id}}/message_templates'
WA_API_TEMPLATE_DELETE='https://graph.facebook.com/v25.0/{{whatsapp_business_account_id}}/message_templates?name={{name}}&hsm_id={{hsm_id}}'

# Phone Numbers
WA_API_PHONE_NUMBERS='https://graph.facebook.com/v25.0/{{whatsapp_business_account_id}}/phone_numbers'

# Media Download (get CDN URL by media ID)
WA_API_MEDIA_DOWNLOAD='https://graph.facebook.com/v25.0/{{media_id}}?phone_number_id={{whatsapp_phone_id}}'

# Onboarding / Embedded Signup
WA_API_ME='https://graph.facebook.com/v25.0/me'
WA_API_DEBUG_TOKEN='https://graph.facebook.com/v25.0/debug_token?input_token={{input_token}}'
WA_API_REGISTER_PHONE='https://graph.facebook.com/v25.0/{{whatsapp_phone_id}}/register'
WA_API_SUBSCRIBED_APPS='https://graph.facebook.com/v25.0/{{whatsapp_business_account_id}}/subscribed_apps'
WA_API_OAUTH_TOKEN='https://graph.facebook.com/v25.0/oauth/access_token'
```

---

## 4. New Laravel Routes

All routes live under the `messaging` prefix and are protected by your existing auth middleware.

```php
// routes/api.php

Route::prefix('messaging')->middleware(['auth:sanctum'])->group(function () {

    // ── Messages ──────────────────────────────────────────────
    Route::post('send',                     [MessagingController::class, 'send']);

    // ── Templates ─────────────────────────────────────────────
    Route::get('templates',                 [TemplateController::class, 'index']);
    Route::post('templates',                [TemplateController::class, 'store']);
    Route::delete('templates/{name}',       [TemplateController::class, 'destroy']);

    // ── Flows ──────────────────────────────────────────────────
    Route::get('flows',                     [FlowController::class, 'index']);
    Route::post('flows',                    [FlowController::class, 'store']);
    Route::post('flows/{flowId}/publish',   [FlowController::class, 'publish']);
    Route::delete('flows/{flowId}',         [FlowController::class, 'destroy']);

    // ── Media ──────────────────────────────────────────────────
    Route::post('media/upload',             [MediaController::class, 'upload']);
    Route::get('media/{mediaId}',           [MediaController::class, 'download']);

    // ── Account ────────────────────────────────────────────────
    Route::get('phone-numbers',             [AccountController::class, 'phoneNumbers']);
    Route::get('analytics',                 [AccountController::class, 'analytics']);

    // ── Onboarding / Embedded Signup ───────────────────────────
    Route::post('connect',                  [OnboardingController::class, 'connect']);
    Route::post('onboarding',               [OnboardingController::class, 'setup']);

});
```

---

## 5. Token Resolution — How Every Controller Gets the Token

Create a reusable trait `ResolvesMessagingCredentials` so no controller duplicates this logic.

```php
// app/Traits/ResolvesMessagingCredentials.php

trait ResolvesMessagingCredentials
{
    /**
     * Returns the authenticated user's WhatsApp credentials from DB.
     * Throws 403 if config is missing.
     */
    protected function messagingCredentials(): array
    {
        $user   = auth()->user();
        $config = $user->userConfig; // adjust relation name to match your model

        abort_if(!$config || !$config->wa_token, 403, 'Messaging credentials not configured.');

        return [
            'wa_token'    => $config->wa_token,
            'phone_id'    => $config->whatsapp_phone_id,
            'waba_id'     => $config->whatsapp_business_account_id,
            'wap_id'      => $config->whatsapp_business_account_id, // used for analytics
        ];
    }

    /**
     * Standard Bearer auth header for Meta API calls.
     */
    protected function metaHeaders(string $waToken): array
    {
        return [
            'Authorization' => 'Bearer ' . $waToken,
            'Content-Type'  => 'application/json',
        ];
    }
}
```

Use it in every controller:

```php
use App\Traits\ResolvesMessagingCredentials;

class MessagingController extends Controller
{
    use ResolvesMessagingCredentials;

    public function send(Request $request)
    {
        $creds = $this->messagingCredentials();
        // $creds['wa_token'], $creds['phone_id'] etc. available here
    }
}
```

---

## 6. Controller Details

### 6.1 MessagingController — Send Messages

**Route:** `POST /api/messaging/send`

**Frontend sends:** The exact same Meta message payload it builds today (no change to payload shape).

```php
public function send(Request $request)
{
    $creds = $this->messagingCredentials();

    $url = str_replace(
        '{{whatsapp_phone_id}}',
        $creds['phone_id'],
        env('WA_API_MESSAGES')
    );

    $response = Http::withHeaders($this->metaHeaders($creds['wa_token']))
        ->post($url, $request->all());

    return response()->json($response->json(), $response->status());
}
```

> **Note:** The payload shape from the frontend does NOT change — the backend simply forwards it. This means zero refactoring on the payload-building side.

---

### 6.2 TemplateController — Fetch / Create / Delete

**Routes:**
- `GET  /api/messaging/templates`
- `POST /api/messaging/templates`
- `DELETE /api/messaging/templates/{name}?hsm_id={id}`

```php
// GET — Fetch all approved templates
public function index()
{
    $creds = $this->messagingCredentials();

    $url = str_replace(
        ['{{whatsapp_business_account_id}}', '{{wa_token}}'],
        [$creds['waba_id'], $creds['wa_token']],
        env('WA_API_TEMPLATES')
    );

    $response = Http::withHeaders($this->metaHeaders($creds['wa_token']))->get($url);
    return response()->json($response->json(), $response->status());
}

// POST — Create / update a template
public function store(Request $request)
{
    $creds = $this->messagingCredentials();

    $url = str_replace(
        '{{whatsapp_business_account_id}}',
        $creds['waba_id'],
        env('WA_API_TEMPLATE_SUBMIT')
    );

    $response = Http::withHeaders($this->metaHeaders($creds['wa_token']))
        ->post($url, $request->all());

    return response()->json($response->json(), $response->status());
}

// DELETE — Delete template by name + hsm_id
public function destroy(Request $request, string $name)
{
    $creds  = $this->messagingCredentials();
    $hsmId  = $request->query('hsm_id', '');

    $url = str_replace(
        ['{{whatsapp_business_account_id}}', '{{name}}', '{{hsm_id}}'],
        [$creds['waba_id'], $name, $hsmId],
        env('WA_API_TEMPLATE_DELETE')
    );

    $response = Http::withHeaders($this->metaHeaders($creds['wa_token']))->delete($url);
    return response()->json($response->json(), $response->status());
}
```

---

### 6.3 FlowController — List / Create / Publish / Delete

**Routes:**
- `GET    /api/messaging/flows`
- `POST   /api/messaging/flows`
- `POST   /api/messaging/flows/{flowId}/publish`
- `DELETE /api/messaging/flows/{flowId}`

```php
public function index()
{
    $creds = $this->messagingCredentials();

    $url = str_replace('{{whatsapp_business_account_id}}', $creds['waba_id'], env('WA_API_FLOWS'));

    $response = Http::withHeaders($this->metaHeaders($creds['wa_token']))->get($url);
    return response()->json($response->json(), $response->status());
}

public function store(Request $request)
{
    $creds = $this->messagingCredentials();

    $url = str_replace('{{whatsapp_business_account_id}}', $creds['waba_id'], env('WA_API_FLOWS'));

    $response = Http::withHeaders($this->metaHeaders($creds['wa_token']))
        ->post($url, $request->all());

    return response()->json($response->json(), $response->status());
}

public function publish(string $flowId)
{
    $creds = $this->messagingCredentials();

    $url = str_replace('{{flow_id}}', $flowId, env('WA_API_FLOW_PUBLISH'));

    $response = Http::withHeaders($this->metaHeaders($creds['wa_token']))
        ->post($url, ['status' => 'PUBLISHED']);

    return response()->json($response->json(), $response->status());
}

public function destroy(string $flowId)
{
    $creds = $this->messagingCredentials();

    $url = str_replace('{{flow_id}}', $flowId, env('WA_API_FLOW_ACTION'));

    $response = Http::withHeaders($this->metaHeaders($creds['wa_token']))->delete($url);
    return response()->json($response->json(), $response->status());
}
```

---

### 6.4 MediaController — Upload / Download

**Routes:**
- `POST /api/messaging/media/upload`
- `GET  /api/messaging/media/{mediaId}`

```php
// Upload — accepts multipart/form-data (file)
public function upload(Request $request)
{
    $creds = $this->messagingCredentials();

    $url = str_replace('{{whatsapp_phone_id}}', $creds['phone_id'], env('WA_API_MEDIA'));

    $response = Http::withHeaders(['Authorization' => 'Bearer ' . $creds['wa_token']])
        ->attach('file', $request->file('file')->get(), $request->file('file')->getClientOriginalName())
        ->post($url, [
            'messaging_product' => 'whatsapp',
            'type'              => $request->input('type', 'image/jpeg'),
        ]);

    return response()->json($response->json(), $response->status());
}

// Download — returns the CDN URL for a media ID
public function download(string $mediaId)
{
    $creds = $this->messagingCredentials();

    $url = str_replace(
        ['{{media_id}}', '{{whatsapp_phone_id}}'],
        [$mediaId, $creds['phone_id']],
        env('WA_API_MEDIA_DOWNLOAD')
    );

    $response = Http::withHeaders($this->metaHeaders($creds['wa_token']))->get($url);
    return response()->json($response->json(), $response->status());
}
```

---

### 6.5 AccountController — Phone Numbers & Analytics

**Routes:**
- `GET /api/messaging/phone-numbers`
- `GET /api/messaging/analytics`

```php
public function phoneNumbers()
{
    $creds = $this->messagingCredentials();

    $url = str_replace(
        '{{whatsapp_business_account_id}}',
        $creds['waba_id'],
        env('WA_API_PHONE_NUMBERS')
    );

    $response = Http::withHeaders($this->metaHeaders($creds['wa_token']))->get($url);
    return response()->json($response->json(), $response->status());
}

public function analytics(Request $request)
{
    $creds     = $this->messagingCredentials();
    $startUnix = $request->query('start');    // passed by frontend
    $endUnix   = $request->query('end');

    $url = str_replace(
        ['{{wapid}}', '{{start_unix}}', '{{realtime_unix}}', '{{wa_token}}'],
        [$creds['wap_id'], $startUnix, $endUnix, $creds['wa_token']],
        env('WA_API_ANALYTICS')
    );

    $response = Http::withHeaders($this->metaHeaders($creds['wa_token']))->get($url);
    return response()->json($response->json(), $response->status());
}
```

---

### 6.6 OnboardingController — OAuth & Embedded Signup

**Routes:**
- `POST /api/messaging/connect`   — OAuth code exchange
- `POST /api/messaging/onboarding` — Embedded signup steps (me, debugToken, registerPhone, subscribedApps)

```php
// OAuth token exchange — App Secret stays server-side only
public function connect(Request $request)
{
    $code = $request->input('code');

    $response = Http::get(env('META_API_BASE') . '/oauth/access_token', [
        'client_id'     => env('META_APP_ID'),
        'client_secret' => env('META_APP_SECRET'),  // NEVER sent to frontend
        'code'          => $code,
    ]);

    // Only return access_token to frontend — never expose secret or full response
    $data = $response->json();
    return response()->json([
        'access_token' => $data['access_token'] ?? null,
        'success'      => isset($data['access_token']),
    ], $response->status());
}

// Embedded signup steps — frontend sends { step, token, ...params }
public function setup(Request $request)
{
    $step  = $request->input('step');
    $token = $request->input('token'); // temporary user token from OAuth popup

    $base    = env('META_API_BASE');
    $headers = ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'];

    switch ($step) {
        case 'me':
            $response = Http::withHeaders($headers)->get("{$base}/me");
            break;

        case 'debug_token':
            $response = Http::withHeaders($headers)->get("{$base}/debug_token", [
                'input_token' => $token,
            ]);
            break;

        case 'phone_numbers':
            $wabaId   = $request->input('waba_id');
            $response = Http::withHeaders($headers)->get("{$base}/{$wabaId}/phone_numbers");
            break;

        case 'register_phone':
            $phoneId  = $request->input('phone_id');
            $response = Http::withHeaders($headers)->post("{$base}/{$phoneId}/register", [
                'messaging_product' => 'whatsapp',
                'pin'               => $request->input('pin'),
            ]);
            break;

        case 'subscribe_app':
            $wabaId   = $request->input('waba_id');
            $response = Http::withHeaders($headers)->post("{$base}/{$wabaId}/subscribed_apps");
            break;

        default:
            return response()->json(['error' => 'Unknown onboarding step'], 400);
    }

    return response()->json($response->json(), $response->status());
}
```

---

## 7. Security Checklist

| # | Item | Status |
|---|------|--------|
| 1 | `META_APP_SECRET` only in backend `.env` — remove from React source | 🔴 Do immediately |
| 2 | `wa_token` only read from DB — never accepted from request body | ✅ By design above |
| 3 | All `/api/messaging/*` routes behind `auth:sanctum` (or equivalent) | ✅ In route group |
| 4 | No Meta URL or token returned to frontend in any response | ✅ By design above |
| 5 | Rate limit `/api/messaging/send` to prevent campaign abuse | 🟡 Recommended |
| 6 | Log all outgoing Meta API calls server-side for audit trail | 🟡 Recommended |
| 7 | Return only necessary fields from Meta responses (avoid leaking full Meta error details) | 🟡 Recommended |

---

## 8. Migration Priority

### Phase 1 — Critical (Do First)
| Route | Reason |
|---|---|
| `POST /api/messaging/send` | Campaign + chat messages — highest volume, token exposed most |
| `GET/POST/DELETE /api/messaging/templates` | Token exposed on every page load |
| `POST /api/messaging/connect` | **App Secret is hardcoded in React source code right now** |

### Phase 2 — Important
| Route | Reason |
|---|---|
| `GET/POST/DELETE/POST /api/messaging/flows` | Token exposed on flow management |
| `POST /api/messaging/media/upload` | Token exposed on every file upload |
| `GET /api/messaging/media/:id` | Token exposed on media downloads |
| `GET /api/messaging/phone-numbers` | Token exposed on quality checks |

### Phase 3 — Complete the Migration
| Route | Reason |
|---|---|
| `GET /api/messaging/analytics` | Token in query string in current env var |
| `POST /api/messaging/onboarding` | One-time setup but still exposes token |

---

## 9. Suggested File Structure in Laravel

```
app/
  Http/
    Controllers/
      Messaging/
        MessagingController.php     ← send
        TemplateController.php      ← templates CRUD
        FlowController.php          ← flows CRUD + publish
        MediaController.php         ← upload + download
        AccountController.php       ← phone numbers + analytics
        OnboardingController.php    ← connect (OAuth) + setup steps
  Traits/
    ResolvesMessagingCredentials.php
```

---

## 10. Notes for Frontend Team

Once a backend route is live, the frontend team will:
1. Replace `metaPost(metaUrls.X(...), payload, waToken)` → `apiClient.post('/api/messaging/X', payload)`
2. Remove `waToken` parameter from every migrated call
3. Remove `metaApiClient` and `metaUrls` imports from that file

Full frontend migration steps are documented in `FRONTEND_PROXY_MIGRATION.md`.
