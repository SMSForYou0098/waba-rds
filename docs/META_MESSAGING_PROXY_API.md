# Meta Messaging Proxy API (Frontend)

Backend proxy for WhatsApp Graph API calls. The React app must **not** call `graph.facebook.com` directly.

**Base path:** `/api/messaging`  
**Auth:** `Authorization: Bearer <passport_token>` on every request (`auth:api`)  
**Credentials:** `wa_token`, `phone_id`, and `waba_id` are read from the logged-in user’s DB config. **Do not send them** in headers, query, or body.

---

## Global rules

| Rule | Detail |
|------|--------|
| Auth header | `Authorization: Bearer <token>` |
| Content-Type | `application/json` for JSON POSTs |
| Meta token | Never include `access_token` / `wa_token` in requests |
| Phone / WABA IDs | Never include `whatsapp_phone_id` or WABA id unless noted (path/query for resource ids only) |
| Errors | Most endpoints return Meta’s JSON body (sanitized: `fbtrace_id` removed). HTTP status matches Meta when possible. |
| Rate limit | `POST /api/messaging/send` — **60 requests/minute** per user |

---

## Endpoints

### 1. Send message

| | |
|--|--|
| **Method** | `POST` |
| **URL** | `/api/messaging/send` |
| **Body** | **Same as [Meta Send Messages API](https://developers.facebook.com/docs/whatsapp/cloud-api/reference/messages)** — forward the exact JSON you would POST to `/{phone-number-id}/messages` |

**Backend validation:**

| Field | Rule |
|-------|------|
| `to` | Required string |
| `billing_category` | Optional. One of: `marketing`, `utility`, `authentication`, `service`. Overrides template category for pricing when set. |

**Billing:** On successful Meta send, the backend creates one `out_reports` row and deducts one message from the user balance (same pricing rules as campaigns). Webhook billing is skipped when `billable` is already set.

**Example body** (template message):

```json
{
  "messaging_product": "whatsapp",
  "to": "919876543210",
  "type": "template",
  "template": {
    "name": "hello_world",
    "language": { "code": "en_US" }
  }
}
```

**Success response** (wrapped — not raw Meta):

```json
{
  "ok": true,
  "wamid": "wamid.HBgM...",
  "out_report_id": 12345,
  "deducted": 0.85,
  "balance_after": 99.15
}
```

**Error responses:**

Meta failure:

```json
{
  "ok": false,
  "error": "Human readable message from Meta",
  "code": "131026"
}
```

Insufficient credits (HTTP 422):

```json
{
  "ok": false,
  "error": "Insufficient credits to send this message.",
  "code": "INSUFFICIENT_CREDITS",
  "current_balance": 0.5,
  "required": 0.85
}
```

Pricing not configured (HTTP 422): `code`: `PRICING_NOT_CONFIGURED`

Already billed / duplicate wamid (HTTP 409): `code`: `ALREADY_BILLED`

---

### 2. List templates

| | |
|--|--|
| **Method** | `GET` |
| **URL** | `/api/messaging/templates` |
| **Path params** | None |
| **Query params** | None |
| **Body** | None |

**Response:** Same JSON shape as Meta `GET /{waba-id}/message_templates` (e.g. `{ "data": [ ... ] }`).

---

### 3. Create template

| | |
|--|--|
| **Method** | `POST` |
| **URL** | `/api/messaging/templates` |
| **Body** | **Same as direct Meta API** — payload for `POST /{waba-id}/message_templates` |

See: [Message Templates API](https://developers.facebook.com/docs/whatsapp/business-management-api/message-templates)

---

### 4. Delete template

| | |
|--|--|
| **Method** | `DELETE` |
| **URL** | `/api/messaging/templates/{name}` |

| Param | Where | Required | Description |
|-------|--------|----------|-------------|
| `name` | URL path | Yes | Template name |
| `hsm_id` | Query string | No | Template HSM id (pass when Meta requires it) |

**Example:** `DELETE /api/messaging/templates/my_template_name?hsm_id=123456`

**Body:** None

**Response:** Same as Meta delete template response.

---

### 5. OAuth connect (embedded signup)

| | |
|--|--|
| **Method** | `POST` |
| **URL** | `/api/messaging/connect` |
| **Body** | App-specific — **not** the full Meta OAuth response |

```json
{
  "code": "<authorization_code_from_fb_login_redirect>"
}
```

**Success response** (backend only returns token — never app secret):

```json
{
  "ok": true,
  "access_token": "EAA..."
}
```

---

### 6. Upload media

| | |
|--|--|
| **Method** | `POST` |
| **URL** | `/api/messaging/media/upload` |
| **Content-Type** | `multipart/form-data` |

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `file` | file | Yes | Media file |
| `type` | string | No | MIME type (defaults to file’s MIME, e.g. `image/jpeg`) |

Backend adds `messaging_product: whatsapp` when calling Meta.

**Response:** Same as Meta `POST /{phone-number-id}/media` (e.g. `{ "id": "media_id..." }`).

---

### 7. Get media (download URL)

| | |
|--|--|
| **Method** | `GET` |
| **URL** | `/api/messaging/media/{mediaId}` |

| Param | Where | Required | Description |
|-------|--------|----------|-------------|
| `mediaId` | URL path | Yes | Meta media id |

**Example:** `GET /api/messaging/media/1234567890`

**Body:** None

**Response:** Same as Meta media node lookup (includes `url` for CDN download when applicable).

---

### 8. List flows

| | |
|--|--|
| **Method** | `GET` |
| **URL** | `/api/messaging/flows` |
| **Path / query / body** | None |

**Response:** Same as Meta `GET /{waba-id}/flows`.

---

### 9. Create flow

| | |
|--|--|
| **Method** | `POST` |
| **URL** | `/api/messaging/flows` |
| **Body** | **Same as direct Meta API** — payload for `POST /{waba-id}/flows` |

See: [WhatsApp Flows API](https://developers.facebook.com/docs/whatsapp/flows)

---

### 10. Publish flow

| | |
|--|--|
| **Method** | `POST` |
| **URL** | `/api/messaging/flows/{flowId}/publish` |

| Param | Where | Required | Description |
|-------|--------|----------|-------------|
| `flowId` | URL path | Yes | Flow id from Meta |

**Body:** None required — backend sends `{ "status": "PUBLISHED" }` to Meta.

**Response:** Same as Meta publish flow response.

---

### 11. Delete flow

| | |
|--|--|
| **Method** | `DELETE` |
| **URL** | `/api/messaging/flows/{flowId}` |

| Param | Where | Required | Description |
|-------|--------|----------|-------------|
| `flowId` | URL path | Yes | Flow id |

**Body:** None

**Response:** Same as Meta delete flow response.

---

### 12. List phone numbers

| | |
|--|--|
| **Method** | `GET` |
| **URL** | `/api/messaging/phone-numbers` |
| **Path / query / body** | None |

**Response:** Same as Meta `GET /{waba-id}/phone_numbers`.

---

### 13. Complete embedded signup (recommended)

| | |
|--|--|
| **Method** | `POST` |
| **URL** | `/api/messaging/onboarding/complete` |
| **Body** | `{ "code": "<oauth_code_from_facebook_popup>" }` |

**What the backend does (strictly one step after another):**

1. Exchange OAuth `code` → `access_token` (server uses `META_APP_SECRET`)
2. `debug_token` → WABA id
3. `/{waba_id}/phone_numbers` → latest phone by `last_onboarded_time`
4. `/{phone_id}/register` with `WHATSAPP_REGISTER_PIN` from `.env`
5. `/{waba_id}/subscribed_apps`
6. If role is `Viewer`, detach and assign `User` (same as `GET /change-viewer-role/{id}`)
7. Add starter balance (`ONBOARDING_STARTER_BALANCE`, default `10`) if the user has no balance row yet
8. Create/update default pricing model from `ONBOARDING_*_PRICE` env vars

After **each** successful Meta step, credentials are saved to `userconfigs` (and `users.whatsapp_number` after step 2). Post-Meta setup runs only after all Meta steps succeed. If a post-Meta step fails, WhatsApp credentials remain saved; `failed_at` is `5` (role), `6` (balance), or `7` (pricing).

**Do not** send `meta_access_token`, WABA id, or phone id in the request. **Do not** use `POST /api/update-credential` for embedded signup.

**Success response:**

```json
{
  "ok": true,
  "step_completed": 8,
  "onboarding_status": "completed",
  "waba_id": "123456789012345",
  "phone_id": "987654321098765",
  "phone_number": "919876543210",
  "role_changed": true,
  "role": "User",
  "starter_balance": 10,
  "balance_skipped": false,
  "pricing_configured": true
}
```

**Failure response (example — failed at register):**

```json
{
  "ok": false,
  "failed_at": 3,
  "step_label": "Register Phone",
  "onboarding_status": "phone_resolved",
  "error": "Meta error message here"
}
```

`failed_at` is 0–4 (Meta), `5` (role), `6` (starter balance), `7` (pricing). `onboarding_status` reflects the last Meta step that was saved.

**Legacy:** `POST /api/messaging/connect` only runs step 0 and returns `access_token` to the client — prefer `onboarding/complete` for new frontend code.

---

## Migration cheat sheet (frontend)

| Old (direct Meta) | New (backend) |
|-------------------|---------------|
| `POST graph.../messages` + Bearer waToken | `POST /api/messaging/send` + Bearer passport token, same JSON body |
| `GET graph.../message_templates` | `GET /api/messaging/templates` |
| `POST graph.../message_templates` | `POST /api/messaging/templates` (same body) |
| `DELETE graph.../message_templates?name=...` | `DELETE /api/messaging/templates/{name}?hsm_id=...` |
| OAuth + full embedded signup in browser | `POST /api/messaging/onboarding/complete` with `{ "code" }` only |
| OAuth step 0 only (legacy) | `POST /api/messaging/connect` with `{ "code" }` only |
| `POST graph.../media` multipart | `POST /api/messaging/media/upload` multipart `file` |
| `GET graph.../{media-id}` | `GET /api/messaging/media/{mediaId}` |
| Flows CRUD on graph | `/api/messaging/flows` routes above |
| `GET graph.../phone_numbers` | `GET /api/messaging/phone-numbers` |

---

## Not covered by this proxy (unchanged routes)

| Use case | Route |
|----------|--------|
| Tenant public API (apikey) | `GET/POST /api/send-messages`, `POST /api/send-media-messages` |
| Bulk campaign | `POST /api/send-campaign`, `GET /api/campaign-progress/{id}` |
| Dashboard weekly analytics | `GET /api/dashboard-weekly-report/{id}` |

---

## Reference

- [WhatsApp Cloud API — Messages](https://developers.facebook.com/docs/whatsapp/cloud-api/reference/messages)
- [Business Management API — Message Templates](https://developers.facebook.com/docs/whatsapp/business-management-api/message-templates)
- [WhatsApp Flows](https://developers.facebook.com/docs/whatsapp/flows)
