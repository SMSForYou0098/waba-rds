# Backend Changes Required — Excel Campaign Merge

## Context

The Standard Campaign page now supports two input modes:

| Mode | `campaign_source` value | How numbers & variables are supplied |
|---|---|---|
| **Manual** | `"manual"` | Numbers typed/pasted by user; variables entered manually per `{{1}}`, `{{2}}` |
| **Excel** | `"excel"` | Numbers + per-row variable values all come from an uploaded `.xlsx` file |

Manual mode behaviour is **identical to before** — no backend changes needed for it.  
Excel mode introduces **two new payload fields** and **changes the shape of `numbers`**.

---

## Endpoint: `POST validate-campaign`

**No changes required.**

The frontend still sends `numbers_count` as a plain integer (count of rows in the Excel file).  
All other fields remain the same.

```json
{
  "user_id": 1,
  "campaign_type": "template",
  "template_name": "wabaportalurl",
  "custom_text": "",
  "numbers_count": 250,
  "template_category": "UTILITY"
}
```

---

## Endpoint: `POST send-campaign`

### New / changed fields

| Field | Type | Manual mode | Excel mode |
|---|---|---|---|
| `campaign_source` | `string` | `"manual"` | `"excel"` |
| `numbers` | `array` | Flat array of phone number integers (existing behaviour) | **Array of objects** `{ number, value[] }` |
| `excel_data` | `array \| null` | `null` | Same array of objects as `numbers` |
| `body_values` | `array` | Array of manual variable values (existing) | **Empty array `[]`** — values come per-row from `numbers` |

> `excel_data` and `numbers` carry the same data in Excel mode. You can use either field;
> `excel_data` is the explicit signal while `numbers` keeps the payload shape consistent
> with the rest of the campaign logic.

---

### Payload shape — Manual mode (unchanged)

```json
{
  "name": "My Campaign",
  "user_id": 1,
  "campaign_source": "manual",
  "numbers": [919876543210, 919876543211, 919876543212],
  "campaign_type": "template",
  "template_name": "wabaportalurl",
  "template_language": "en",
  "template_category": "UTILITY",
  "header_type": null,
  "header_media_url": "",
  "body_values": ["https://newportal.com", "https://oldportal.com"],
  "button_type": null,
  "button_value": [],
  "excel_data": null
}
```

---

### Payload shape — Excel mode (new)

```json
{
  "name": "My Excel Campaign",
  "user_id": 1,
  "campaign_source": "excel",
  "numbers": [
    { "number": "919876543210", "value": ["https://newportal.com", "https://oldportal.com"] },
    { "number": "919876543211", "value": ["https://newportal2.com", "https://oldportal2.com"] },
    { "number": "919876543212", "value": ["https://newportal3.com", "https://oldportal3.com"] }
  ],
  "campaign_type": "template",
  "template_name": "wabaportalurl",
  "template_language": "en",
  "template_category": "UTILITY",
  "header_type": null,
  "header_media_url": "",
  "body_values": [],
  "button_type": null,
  "button_value": [],
  "excel_data": [
    { "number": "919876543210", "value": ["https://newportal.com", "https://oldportal.com"] },
    { "number": "919876543211", "value": ["https://newportal2.com", "https://oldportal2.com"] },
    { "number": "919876543212", "value": ["https://newportal3.com", "https://oldportal3.com"] }
  ]
}
```

---

## How to process `excel_data` / `numbers` in Excel mode

### Variable substitution (per row)

Each object in the array maps one recipient to their own variable values:

```
row.number          → recipient phone number
row.value[0]        → replaces {{1}} in the template body
row.value[1]        → replaces {{2}} in the template body
row.value[N-1]      → replaces {{N}} in the template body
```

**Example** — template body: `"New URL: {{1}} | Old URL: {{2}}"`

| `row.number` | `row.value[0]` | `row.value[1]` | Message sent |
|---|---|---|---|
| `919876543210` | `https://newportal.com` | `https://oldportal.com` | `New URL: https://newportal.com \| Old URL: https://oldportal.com` |
| `919876543211` | `https://newportal2.com` | `https://oldportal2.com` | `New URL: https://newportal2.com \| Old URL: https://oldportal2.com` |

### Processing logic (pseudocode)

```python
if payload["campaign_source"] == "excel":
    for row in payload["excel_data"]:        # or payload["numbers"]
        phone   = row["number"]              # e.g. "919876543210"
        values  = row["value"]               # e.g. ["val1", "val2"]

        # Build personalised body for this recipient
        body = template.body_text
        for i, val in enumerate(values):
            body = body.replace("{{" + str(i + 1) + "}}", str(val))

        send_whatsapp_message(phone, body)

else:
    # existing manual mode logic — body_values applied uniformly
    for phone in payload["numbers"]:
        body = template.body_text
        for i, val in enumerate(payload["body_values"]):
            body = body.replace("{{" + str(i + 1) + "}}", str(val))

        send_whatsapp_message(phone, body)
```

---

## Phone number format in `excel_data`

`row.number` comes directly from column 1 of the user's Excel file.  
It will typically be a 10-digit or 12-digit string/integer.  
Apply the same normalisation you already use for manual-mode numbers:

- 10 digits → prefix `91` → `"9198XXXXXXXX"`
- 12 digits → use as-is → `"919876543210"`

---

## URL button variables (`button_value`)

URL button variable values are **not** per-row — they come from the manual input
fields and are the same for every recipient regardless of mode.  
`button_value` is handled identically in both modes.

---

## Campaign progress (WebSocket / Reverb)

No changes. The `campaign_id` returned by `send-campaign` and the existing
`useCampaignProgress` WebSocket channel work the same way for both modes.

---

## Summary checklist for backend

- [ ] `send-campaign`: read new field `campaign_source` (`"manual"` | `"excel"`)
- [ ] `send-campaign`: when `campaign_source === "excel"`, treat `numbers` (or `excel_data`) as `[{ number, value[] }]` instead of a flat array
- [ ] `send-campaign`: per-row variable substitution — `value[0]` → `{{1}}`, `value[1]` → `{{2}}`, etc.
- [ ] `send-campaign`: ignore `body_values` in Excel mode (it will be an empty array)
- [ ] `send-campaign`: normalise `row.number` the same way manual phone numbers are normalised
- [ ] `validate-campaign`: **no changes needed** — `numbers_count` is still a plain integer
- [ ] Campaign progress channel: **no changes needed**
