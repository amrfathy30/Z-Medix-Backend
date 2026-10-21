# Plans & Subscriptions — Student API

Endpoint reference for frontend integration, plus the rules the admin side
follows. The generated OpenAPI document is at **`/docs/api`**; this file adds
the response shapes and the business rules behind them.

## How it works

Payment is **manual and happens outside the app, on WhatsApp**. The student never
uploads proof.

1. The student sees the plans (`GET plans`) and asks for one
   (`POST subscription-requests`). Nothing is charged and nothing is unlocked.
2. The app opens WhatsApp using the `contact.whatsapp_url` in the response.
3. The admin takes payment on WhatsApp, then in the dashboard opens
   **Subscription Requests → Approve & record payment** and uploads the payment
   screenshot. That records a **period** (an invoice) and activates the plan.
4. Renewals are further periods on the same subscription, added from the
   subscription's page. A student has one subscription; it has many periods.

A student **sees and can use only the features of their active plan**. With no
active subscription they reach nothing but the three endpoints below.

## Conventions

Same as the rest of the student API (see `fellowship-api.md`): base
`/api/student`, `Authorization: Bearer <token>`, `Accept-Language: en|ar` (or
`?lang=`), and the `{ success, message, data }` / `{ success, message, errors, code }`
envelopes.

### Error codes

| Code | Status | Meaning |
|---|---|---|
| `SUBSCRIPTION_REQUIRED` | 403 | The student has no active subscription (never subscribed, expired, cancelled, or the first period has not started). Returned by every gated endpoint. |
| `FEATURE_NOT_IN_PLAN` | 403 | The student is subscribed, but their plan does not include this feature. |
| `PLAN_UNAVAILABLE` | 422 | The plan is inactive or deleted. |
| `BILLING_CYCLE_NOT_OFFERED` | 422 | The plan is not sold on that billing cycle (its price for it is empty). |

The gate runs **before** route lookup, so a student without the feature gets the
same `403` for every id, existing or not.

## Gated features

| Feature key | Guards |
|---|---|
| `library` | everything under `study/*`, and `private-books/*` |
| `question_bank` | `fellowships/*` and `fellowship-sessions/*` |
| `case_report` | `report-cases` |
| `ai` | no endpoints yet; future AI routes use the same gate |

`plans`, `subscription` and `subscription-requests` are never gated.

## Endpoints

### `GET /api/student/plans`

Active plans in the admin's order, for the pricing screen. Build the
Monthly/Yearly toggle from `prices`.

```json
{
  "success": true,
  "message": "Success.",
  "data": [
    {
      "id": 2,
      "slug": "premium",
      "name": "Premium",
      "description": null,
      "currency": "USD",
      "prices": { "monthly": 9.99, "yearly": 99.99 },
      "features": [
        { "key": "library", "label": "Library", "unlimited": false, "limit": null },
        { "key": "question_bank", "label": "Question Bank", "unlimited": false, "limit": null },
        { "key": "case_report", "label": "Case Report", "unlimited": false, "limit": null },
        { "key": "ai", "label": "AI Assistant", "unlimited": false, "limit": 100 }
      ],
      "is_current": false
    }
  ]
}
```

- A price is `null` when the plan is not sold on that cycle.
- `limit` / `unlimited` only mean something for `ai`: `unlimited: true` has
  `limit: null`; a limited plan has `limit` = credits per paid period.
- `is_current` marks the plan the student is on right now.

### `GET /api/student/subscription`

Where the student stands. **`features` is only ever the current plan's own
features, and is empty without an active subscription**, so an app that renders
from it can never show something the student cannot use.

```json
{
  "success": true,
  "message": "Success.",
  "data": {
    "status": "active",
    "plan": { "id": 1, "slug": "basic", "name": "Basic" },
    "billing_cycle": "monthly",
    "starts_at": "2026-10-14T10:00:00+00:00",
    "ends_at": "2026-11-14T10:00:00+00:00",
    "days_remaining": 30,
    "features": [
      { "key": "library", "label": "Library", "unlimited": false, "limit": null },
      { "key": "question_bank", "label": "Question Bank", "unlimited": false, "limit": null }
    ],
    "pending_request": null,
    "contact": { "whatsapp_number": "+20 100 123 4567", "whatsapp_url": "https://wa.me/201001234567" }
  }
}
```

`status` is one of:

| Status | Meaning | `plan`, dates, `features` |
|---|---|---|
| `active` | a paid period covers now | filled |
| `expired` | had a subscription, no period covers now | `null` / `[]` |
| `cancelled` | the admin cancelled it (access is withdrawn at once, even inside a paid period) | `null` / `[]` |
| `none` | never subscribed | `null` / `[]` |

`pending_request` is the student's open request (`id`, `status`, `billing_cycle`,
`plan`, `created_at`) or `null`. `contact` values are `null` until the admin sets
the WhatsApp number in Settings.

### `POST /api/student/subscription-requests`

```json
{ "plan_id": 2, "billing_cycle": "yearly" }
```

`billing_cycle` is `monthly` or `yearly`. Returns **`201`** for a new request and
**`200`** when the student already had one pending: a student has at most one
pending request, and asking again changes it rather than adding a second.

```json
{
  "success": true,
  "message": "Your request was sent. We will contact you on WhatsApp to complete the payment.",
  "data": {
    "request": {
      "id": 7, "status": "pending", "billing_cycle": "yearly",
      "plan": { "id": 2, "slug": "premium", "name": "Premium" },
      "created_at": "2026-10-15T09:30:00+00:00"
    },
    "contact": {
      "whatsapp_number": "+20 100 123 4567",
      "whatsapp_url": "https://wa.me/201001234567?text=Hello%2C%20I%20would%20like%20to%20subscribe%20to%20the%20Premium%20plan%20(Yearly)."
    }
  }
}
```

Open `contact.whatsapp_url` to start the chat with the message already typed.
A subscribed student may also request a renewal or a different plan.

## Admin rules

- **Where**: the Filament dashboard, nav group **Subscriptions** — *Plans*,
  *Subscription Requests* (with a pending-count badge) and *Subscriptions*.
- **A period is an invoice**: plan, billing cycle, start/end, amount and currency
  actually paid, when it was paid, notes, the admin who recorded it, and the
  **required payment screenshot** (JPG/PNG/WebP). The amount starts at the plan
  price but is whatever was agreed; later price edits never change old periods.
- **Dates**: a monthly period lasts one month and a yearly one twelve (a period
  starting on Jan 31 ends Feb 28). A renewal starts where the student's coverage
  ends, or now if it has lapsed. The admin may set the start explicitly, e.g. to
  start an upgrade immediately; when periods overlap, **the one that started last
  decides the plan**.
- **Periods are append-only.** A mistake is fixed by cancelling the subscription
  or adding the right period. Recording a payment re-activates a cancelled
  subscription.
- **Screenshots are private.** They are stored on the private disk and only
  served to a logged-in admin with `subscriptions.view`, through
  `subscription-periods/{period}/proof`.
- **Permissions**: `plans.*` (pricing and features) and `subscriptions.*`
  (requests, subscriptions, periods). `super_admin` has all; `admin` can read
  plans and handle subscriptions; `content_manager` has neither.
- **Deleting a plan** soft-deletes it, because periods keep pointing at the plan
  they were paid for. It disappears from `GET plans` and cannot be requested.
- **Seeding**: `php artisan db:seed --class=PlansSeeder` creates Basic, Premium
  and Ultra only when their slugs are missing, so it never overwrites edits. The
  deploy workflow does not run it; run it once, or create the plans in the
  dashboard. The seeded prices and Premium's AI credits are placeholders.

## Not covered yet

- No AI endpoints or credit consumption: `ai` is a feature flag and an allowance
  stored on the plan, nothing spends it yet.
- No activation/expiry notifications (business doc §19): they need a scheduler
  and a sending channel that do not exist yet.
- Report-case and platform-book PDFs live on a public disk, so a direct file URL
  is not covered by the plan gate.
