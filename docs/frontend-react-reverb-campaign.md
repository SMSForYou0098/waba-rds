# React — campaign progress over WebSockets (Echo + Reverb)

Guide for a **standalone React app** (e.g. **Create React App**, no Vite). Use **`REACT_APP_*`** env vars and `process.env`.

---

## 1. What you wire up

| Concern | Frontend usage |
|--------|----------------|
| REST API | `REACT_APP_API_BASE` — e.g. `POST …/validate-campaign`, `POST …/send-campaign` with `Authorization: Bearer <accessToken>` |
| Channel auth | `POST {REACT_APP_LARAVEL_ORIGIN}/broadcasting/auth` — Echo calls this automatically; same Bearer token as API |
| Live updates | **laravel-echo** + **pusher-js** → `wsHost` / `wsPort` / `key` from env (Reverb uses the Pusher protocol) |

**Subscription:** private channel name `campaign.{userId}` (Echo: `Echo.private(\`campaign.${userId}\`)`).

**Events to listen for:** `.campaign.progress` and `.campaign.completed` (leading **`.`** on the name is required for these custom event names in Echo).

**Payload shapes (for TypeScript):**

- Progress: `{ campaign_id, sent, total, percent }`
- Completed: `{ campaign_id, total_sent, failed_count }`

---

## 2. Environment (`.env.local` in the React repo)

Use **literal values** in the React project (CRA does not read the Laravel repo).

```bash
# API (include /api if that is how your app is hosted)
REACT_APP_LARAVEL_ORIGIN=https://waba.smsforyou.biz
REACT_APP_API_BASE=https://waba.smsforyou.biz/api

# WebSocket client settings (must match what the backend documents for Echo)
REACT_APP_REVERB_APP_KEY=avc2m9ivvladmbnnlxgq
REACT_APP_REVERB_HOST=192.168.0.174
REACT_APP_REVERB_PORT=8080
REACT_APP_REVERB_SCHEME=http
```

**CRA rules**

- Only **`REACT_APP_`** variables are embedded at build time.
- Restart **`npm start`** after changing `.env*`.
- Never commit real production secrets; use `.env.local` (gitignored) where possible.

**Host / TLS**

- `REACT_APP_REVERB_HOST` must be reachable **from the user’s browser** (not `127.0.0.1` unless the browser runs on the same machine as the WebSocket server).
- If the React site is **HTTPS** and the socket is **HTTP**, the browser may block the connection (mixed content) — align scheme/proxy with your deployment.

---

## 3. Install

```bash
npm install laravel-echo pusher-js
```

Optional types:

```bash
npm install -D @types/pusher-js
```

---

## 4. Echo instance — `src/echo/createCampaignEcho.ts`

```typescript
import Echo from "laravel-echo";
import Pusher from "pusher-js";

declare global {
  interface Window {
    Pusher: typeof Pusher;
  }
}

function requireEnv(name: string): string {
  const v = process.env[name];
  if (!v) throw new Error(`Missing ${name} in React .env`);
  return v;
}

export function createCampaignEcho(authToken: string): Echo {
  window.Pusher = Pusher;

  const scheme = requireEnv("REACT_APP_REVERB_SCHEME");
  const useTls = scheme === "https";
  const host = requireEnv("REACT_APP_REVERB_HOST");
  const port = Number(requireEnv("REACT_APP_REVERB_PORT"));
  const laravelOrigin = requireEnv("REACT_APP_LARAVEL_ORIGIN").replace(/\/$/, "");

  return new Echo({
    broadcaster: "pusher",
    key: requireEnv("REACT_APP_REVERB_APP_KEY"),
    cluster: process.env.REACT_APP_REVERB_APP_CLUSTER || "mt1",
    wsHost: host,
    wsPort: port,
    wssPort: port,
    forceTLS: useTls,
    encrypted: useTls,
    disableStats: true,
    enabledTransports: ["ws", "wss"],
    authEndpoint: `${laravelOrigin}/broadcasting/auth`,
    auth: {
      headers: {
        Authorization: `Bearer ${authToken}`,
        Accept: "application/json",
      },
    },
  });
}
```

---

## 5. Hook — `src/hooks/useCampaignProgress.ts`

```typescript
import { useEffect, useState } from "react";
import type Echo from "laravel-echo";
import { createCampaignEcho } from "../echo/createCampaignEcho";

export type CampaignProgressPayload = {
  campaign_id: number;
  sent: number;
  total: number;
  percent: number;
};

export type CampaignCompletedPayload = {
  campaign_id: number;
  total_sent: number;
  failed_count: number;
};

type Options = {
  userId: number;
  authToken: string;
  /** If set, ignore events for other campaigns */
  campaignId?: number;
};

export function useCampaignProgress(
  { userId, authToken, campaignId }: Options,
  enabled: boolean
) {
  const [progress, setProgress] = useState<CampaignProgressPayload | null>(null);
  const [completed, setCompleted] = useState<CampaignCompletedPayload | null>(null);

  useEffect(() => {
    if (!enabled || !authToken || !userId) return;

    let echo: Echo;
    try {
      echo = createCampaignEcho(authToken);
    } catch {
      return;
    }

    const channel = echo.private(`campaign.${userId}`);

    channel.listen(".campaign.progress", (payload: CampaignProgressPayload) => {
      if (campaignId != null && payload.campaign_id !== campaignId) return;
      setProgress(payload);
    });

    channel.listen(".campaign.completed", (payload: CampaignCompletedPayload) => {
      if (campaignId != null && payload.campaign_id !== campaignId) return;
      setCompleted(payload);
    });

    return () => {
      channel.stopListening(".campaign.progress");
      channel.stopListening(".campaign.completed");
      echo.disconnect();
    };
  }, [userId, authToken, campaignId, enabled]);

  return { progress, completed };
}
```

**Leading dot (`".campaign.progress"`):** Echo listens with a leading dot so the event name is not prefixed with the default namespace.

---

## 6. REST — validate and send

```typescript
const apiBase = process.env.REACT_APP_API_BASE!.replace(/\/$/, "");

const headers = (token: string) => ({
  "Content-Type": "application/json",
  Accept: "application/json",
  Authorization: `Bearer ${token}`,
});
```

**Validate** — `POST ${apiBase}/validate-campaign`  
Body: whatever your UI collects (`user_id`, `campaign_type`, counts, template fields, etc.).  
Typical success JSON: `{ data: { valid, balance_required, current_balance, can_proceed, message? }, status: number }`.

**Send** — `POST ${apiBase}/send-campaign`  
Same headers. Body: full campaign payload including `numbers: string[]`.  
Typical success: `{ data: { campaign_id }, status: 200 }`.

After send succeeds, store `campaign_id` and pass it into `useCampaignProgress` (optional filter).

---

## 7. Example flow in a component

```tsx
import { useState } from "react";
import { useCampaignProgress } from "./hooks/useCampaignProgress";

const apiBase = process.env.REACT_APP_API_BASE!;

const headers = (token: string) => ({
  "Content-Type": "application/json",
  Accept: "application/json",
  Authorization: `Bearer ${token}`,
});

export function CampaignLauncher({
  userId,
  authToken,
}: {
  userId: number;
  authToken: string;
}) {
  const [campaignId, setCampaignId] = useState<number | null>(null);

  const { progress, completed } = useCampaignProgress(
    { userId, authToken, campaignId: campaignId ?? undefined },
    campaignId != null
  );

  async function sendCampaign(body: Record<string, unknown>) {
    const res = await fetch(`${apiBase}/send-campaign`, {
      method: "POST",
      headers: headers(authToken),
      body: JSON.stringify(body),
    });
    const json = await res.json();
    if (!res.ok) throw new Error(json?.data?.message ?? "Send failed");
    const id = json?.data?.campaign_id as number | undefined;
    if (id != null) setCampaignId(id);
  }

  return (
    <div>
      {/* call sendCampaign(payload) from your UI */}
      {progress != null && (
        <p>
          {progress.sent} / {progress.total} ({progress.percent}%)
        </p>
      )}
      {completed != null && (
        <p>
          Done — sent {completed.total_sent}, failed {completed.failed_count}
        </p>
      )}
    </div>
  );
}
```

---

## 8. Troubleshooting (frontend-focused)

| Symptom | What to verify on the client |
|--------|------------------------------|
| `401` on `/broadcasting/auth` | `authToken` is the same valid Bearer token used for API calls; `authEndpoint` URL is correct (`REACT_APP_LARAVEL_ORIGIN` + `/broadcasting/auth`). |
| `403` on subscribe | `userId` in `campaign.${userId}` matches the user that token represents. |
| WebSocket fails immediately | `REACT_APP_REVERB_HOST` / port / scheme reachable from the browser; corporate VPN/firewall; mixed HTTPS page + `http` socket. |
| No events after connect | `campaignId` filter in the hook; subscription `enabled` flag; you actually called send and received a `campaign_id`. |
| Stale env in dev | Restart the dev server after `.env` changes. |

---

## 9. Vite later

Rename vars to `VITE_REVERB_*` / `VITE_APP_*` and read them with `import.meta.env.VITE_*` instead of `process.env.REACT_APP_*`. Echo setup stays the same.
