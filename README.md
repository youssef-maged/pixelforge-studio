# PixelForge Studio

Image generation on top of Pixelforge, an async API that bills per token. Users see the most an image can cost
before they click, pay Pixelforge's real cost plus 25 % when it finishes, and pay nothing when it fails.

**Live:** https://pixelforge-studio-production-m7ehhs.laravel.cloud (Laravel Cloud, Frankfurt).
Reviewer and demo logins are in the submission form.

Laravel 13 · Inertia 3 · Vue 3.5 · shadcn-vue · Tailwind v4 · Postgres 17 · Redis + Horizon · Pest 4 · Larastan.

## What it does

- **Studio**: prompt, size, quality and up to 4 reference images (upload or link). The composer shows
  "up to $X" live and blocks Generate when the balance can't cover it. Generations appear at once and update
  live (Waiting for a slot → Queued → Generating → done) until they settle.
- **Receipt**: each finished image shows tokens × rates per line, Pixelforge's cost, the 25 % fee and the charge.
- **Credits**: balance, money held by generations in progress, pretend top-ups ($5 / $10 / $25) and a statement.
- **Operator console** (admins): a per-UTC-day report reconciled against Pixelforge's `GET /v1/usage`
  ("matches Pixelforge, to the nanodollar"), drill-down by user, charts and CSV export; plus generations,
  users with balances, the webhook log, and Horizon.

## How the money works

- **Integer nanodollars everywhere.** Pixelforge's rates are fractions of a cent per token; floats would drift.
  The price is `ceil(cost × 1.25)` in nanodollars, so rounding never undercharges.
- **Hold, then charge.** Submitting holds the worst-case price (the "up to" figure, from the live pricing list and
  the token caps). Success turns the hold into the real charge; any failure releases it. The ledger
  (`credit_transactions`) records only purchases and charges, so the balance always equals the sum of the ledger.
- **If Pixelforge's cost is above the hold** (rates changed mid-flight), we still charge the true cost: the
  balance may dip slightly negative and new generations are blocked until topped up. Pixelforge billed it, so we do.
- **Limits**: 3 generations in flight per user and 10 across the app (Pixelforge allows 3 concurrent per account;
  extra ones wait politely), 10 submissions a minute, top-ups capped at a $50 balance, 3 sign-ups an hour per IP.

## How it stays correct with an async provider

- **One idempotency key per generation**: our generation UUID is the `Idempotency-Key` and the metadata, so a
  retried POST can never create a second Pixelforge generation, and webhooks map back even before we know their id.
- **Submitting** is a queued job, dispatched after commit. Busy answers (429 concurrency/rate limit, 503 overloaded)
  are retried after `Retry-After` for up to 15 minutes, then failed for free. A timeout or 5xx is an
  *unknown outcome*: Pixelforge may have created it, so we never fail it locally; we re-POST with the same key
  until Pixelforge answers definitely.
- **Webhooks** are verified (HMAC-SHA256 over timestamp + raw body, constant-time compare, recent timestamps only),
  then recorded and applied in one transaction. The event id is unique, so a duplicate delivery is recorded once
  and ignored, and status only moves forward (a late `processing` after `succeeded` changes nothing).
  Locks are always taken user → generation.
- **A sweep every minute** re-sends anything stuck in `pending` (covers a job lost between commit and queue push),
  polls generations whose webhooks went quiet, and retries image copies. It is time-boxed and skips a bad row.
- **Images** are copied into a private bucket after success and served with 30-minute signed links, to the owner
  and admins only. Until the copy lands, Pixelforge's own URL is shown.
- **The report** groups by Pixelforge's `completed_at` (UTC), the day Pixelforge bills, so daily totals can match
  `/v1/usage` exactly. Each environment uses its own Pixelforge key, so the usage report contains only its numbers.

## Running it locally

Needs PHP 8.4, Composer, Node 24 and Docker.

```bash
docker compose up -d          # Postgres 17 on 54360 (+ pixelforge_test for tests), Redis on 46379
cp .env.example .env          # already points at those ports
composer setup                # install, app key, migrate, build
php artisan db:seed           # demo + reviewer accounts; passwords from DEMO_PASSWORD / ADMIN_PASSWORD, or printed once
composer dev                  # server on :48760, Horizon, scheduler, Vite, logs, and a cloudflared tunnel
```

Create a Pixelforge key for local use, put it in `PIXELFORGE_API_KEY` and its signing secret in
`PIXELFORGE_WEBHOOK_SECRET`, and point its webhook at the tunnel's printed `https://….trycloudflare.com`
address plus `/webhooks/pixelforge` (needs `cloudflared` installed).

Pixelforge's prompt keywords exercise the sad paths: `[fail]`, `[blocked]`, `[slow]`, `[duplicate]`,
`[overloaded]`. The composer's **Test** menu adds them.

## Tests and checks

```bash
composer test                 # Pint, Larastan (level 7), Pest
npm run check && npm run types:check
```

114 Pest tests run against a real Postgres database (`pixelforge_test`), including race tests with two database
connections (two success webhooks at once, a double-clicked purchase). They cover pricing and holds, submit
retries and give-ups, webhook signatures, duplicates and ordering, purchases, the admin report and CSV, reference
uploads, account deletion and the abuse limits.

## Deploying (Laravel Cloud)

App cluster with hibernation off, Horizon as a background process (`php artisan horizon`) and the scheduler on;
Serverless Postgres 17, Valkey, and a private bucket attached as disk `private`. Extra environment:

```
IMAGES_DISK=private
SESSION_SECURE_COOKIE=true
PIXELFORGE_API_KEY=…            # a key made for production
PIXELFORGE_WEBHOOK_SECRET=…     # that key's signing secret
```

Deploys run `php artisan migrate --force`; run `php artisan db:seed --force` once. Point the production key's
webhook at `https://<app>/webhooks/pixelforge` and use Pixelforge's **Send test event** to confirm a 204.
