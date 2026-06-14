# OnlyBikes — Enterprise database layout (IONOS)

Same **five-database** model as Bedda. Create **five** MariaDB databases in IONOS, then run **one SQL file per database** in phpMyAdmin.

| IONOS database | Host | SQL file | PHP accessor | Tables |
|----------------|------|----------|--------------|--------|
| **UserClicks** `dbs15747041` | `db5020604262.hosting-data.io` | `sql/01-analytics-userclicks.sql` | `getAnalyticsDatabase()` | `events` |
| **Auto mail-in orders** `dbs15747042` | `db5020604265.hosting-data.io` | `sql/02-orders-mail-in.sql` | `getOrderDatabase()` | `orders`, `order_items`, `products`, `webhook_events` |
| **News Letter** `dbs15747044` | `db5020604270.hosting-data.io` | `sql/03-newsletter.sql` | `getNewsletterDatabase()`, `getDatabase()` (mail queue) | `newsletter_subscribers`, `email_queue`, … |
| **Coupon Codes** `dbs15747045` | `db5020604271.hosting-data.io` | `sql/04-coupons.sql` | `getCouponDatabase()` | `coupon_codes` |
| **User Profiles** `dbs15747049` | `db5020604277.hosting-data.io` | `sql/05-customers-rewards.sql` | `getCustomersDatabase()` | `customers` |
| **Roast Account Details** (6th DB) | `db5020689219.hosting-data.io` | `sql/09-runtime-credits.sql` | `getRuntimeCreditsDatabase()` | `runtime_balances`, `runtime_ledger`, `ad_unlock_tokens`, `ad_claim_log`, `cloud_usage_daily` |

Stock on the storefront uses `api/data/store-stock.json` (not MySQL). Optional `products` / `order_items` in the **orders** DB are for shipping admin inventory sync.

If `RUNTIME_CREDITS_DB_*` is unset in `api/.env`, runtime credits tables are created on the **Customers** database (local dev fallback).

## Setup steps

1. IONOS → **Databases** → create all **5** databases (you already have these names).
2. For each database: **Open** → phpMyAdmin → select DB → **SQL** → paste the matching file from `api/sql/` → **Go**.
3. If **email-admin** errors with missing columns on the **News Letter** DB, run the matching migration (or reload admin — PHP attempts the same `ALTER` automatically):
   - `newsletter_subscribers.status` → `api/sql/03b-migrate-newsletter-subscribers.sql`
   - `email_queue.send_after` / `status` → `api/sql/03c-migrate-email-queue.sql`
   - `orders.order_date` (shipping admin) → `api/sql/02b-migrate-orders-order-date.sql` in the **Auto mail-in orders** database
   - Coupon admin 500 / legacy columns → upload `api/lib/coupons-schema.php` + `api/lib/security-helpers.php` (`AdminBruteProtect`), or run `api/sql/04b-migrate-coupon-codes.sql` in **Coupon Codes** DB
   - `coupon_codes.type` / `min_total` / `deleted` (coupon admin 500) → `api/sql/04b-migrate-coupon-codes.sql` in the **Coupon Codes** database — or open **email-admin → Coupons** once (PHP runs the same `ALTER` via `api/lib/coupons-schema.php`)
4. On the server, copy `api/.env.example` → `api/.env` and fill **all** `*_DB_*` blocks (see below).
5. Copy `api/secure-config.php.example` → `api/secure-config.php` on the server (never commit real `secure-config.php`).

## `api/.env` keys (all five required)

```env
ANALYTICS_DB_HOST=
ANALYTICS_DB_NAME=
ANALYTICS_DB_USER=
ANALYTICS_DB_PASS=

ORDERS_DB_HOST=
ORDERS_DB_NAME=
ORDERS_DB_USER=
ORDERS_DB_PASS=

NEWSLETTER_DB_HOST=
NEWSLETTER_DB_NAME=
NEWSLETTER_DB_USER=
NEWSLETTER_DB_PASS=

COUPON_DB_HOST=
COUPON_DB_NAME=
COUPON_DB_USER=
COUPON_DB_PASS=

CUSTOMERS_DB_HOST=
CUSTOMERS_DB_NAME=
CUSTOMERS_DB_USER=
CUSTOMERS_DB_PASS=

RUNTIME_CREDITS_DB_HOST=
RUNTIME_CREDITS_DB_NAME=
RUNTIME_CREDITS_DB_USER=
RUNTIME_CREDITS_DB_PASS=
```

Each host is usually `db5019xxxx.hosting-data.io`; user/password are **per database** on IONOS.

## What uses which database

| Feature | Endpoint / file | Database |
|---------|-----------------|----------|
| Page clicks, cart analytics | `logger.js` → `api/log-event.php` | UserClicks |
| Analytics API / admin charts | `api/get-analytics.php`, `get-summary.php` | UserClicks |
| Checkout save order | `api/submit-order.php` | Orders |
| Stripe webhooks | `api/stripe-webhook.php` | Orders |
| Shipping labels | `api/shipping.php` | Orders |
| Newsletter signup | `api/newsletter-subscribe.php` | Newsletter |
| Email queue / cron | `api/process-queue.php`, `mail-queue.php` | Newsletter |
| Discount codes | `api/validate-coupon.php`, `api/manage-coupons.php` | Coupons |
| Login / points / rewards | `api/customer-auth.php` | Customers |
| Points on checkout | `api/submit-order.php` | Customers |
| Reward coupons | `api/generate-reward-coupon.php` | Customers + Coupons |
| Roast runtime credits / ad unlock | `api/runtime-credits.php`, roast orchestrator | Roast Account Details (or Customers fallback) |
| Groq daily budget counter | `api/lib/roast-cloud-budget.php` | Roast Account Details (or Customers fallback) |

## Verify

After upload + `.env` + `secure-config.php`:

- `https://yourdomain.com/api/test-mail.php` — newsletter + orders connections
- `https://yourdomain.com/api/test-config.php` — customer DB defined
- Place a test order (Stripe test mode) — row appears in **orders** DB only
