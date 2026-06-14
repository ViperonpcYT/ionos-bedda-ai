# Cloudflare setup — OnlyBikes (onlybikes.shop)

Step-by-step Cloudflare dashboard configuration for the **Ebike Part selling** site on **IONOS** shared hosting. Same rules as bedda.ca, domain swapped to `onlybikes.shop`.

**Plan note:** Free plan includes **5 custom WAF rules** (this guide uses exactly 5). Rate-limit actions work on Free with per-rule limits; if a rate-limit option is greyed out, upgrade to Pro or use IONOS `.htaccess` rate limits as a fallback.

**Login fix:** If account login returns **403** after setup, Cloudflare is blocking `POST /api/customer-auth.php` (not PHP). See [Login returns 403](#login-returns-403-after-cloudflare-setup) below.

**Origin files in this repo** (upload to IONOS with the site): root `.htaccess`, `api/.htaccess` — mirror WAF blocks and API no-cache at origin.

**Optional:** automated deploy via `scripts/apply-cloudflare-onlybikes.ps1` (needs `CLOUDFLARE_API_TOKEN`). This guide uses the **manual dashboard** instead.

---

## Before you start

1. Log in at [dash.cloudflare.com](https://dash.cloudflare.com)
2. Click **onlybikes.shop**
3. Confirm:
   - **SSL/TLS → Overview** → **Full (strict)**
   - **SSL/TLS → Edge Certificates** → **Always Use HTTPS** → **On**

---

## Part A — Cache Rules

**Caching → Cache Rules**

### Rule 1: Bypass API & admin (must be first)

| Field | Value |
|-------|-------|
| Rule name | Bypass API and admin |
| When incoming requests match… | Custom filter expression |
| Expression | see below |
| Then → Cache eligibility | **Bypass cache** |

**Edit expression → paste:**

```
(http.request.uri.path starts_with "/api/") or (http.request.uri.path starts_with "/email-admin/")
```

Click **Deploy**.

### Rule 2: Cache static assets

| Field | Value |
|-------|-------|
| Rule name | Cache static assets |
| When incoming requests match… | Custom filter expression |
| Expression | see below |
| Then → Cache eligibility | **Eligible for cache** |
| Then → Edge TTL | **Ignore cache-control and use this TTL** → **1 month** |

**Edit expression → paste:**

```
(http.request.uri.path ends_with ".jpg") or (http.request.uri.path ends_with ".png") or (http.request.uri.path ends_with ".webp") or (http.request.uri.path ends_with ".css") or (http.request.uri.path ends_with ".js") or (http.request.uri.path ends_with ".woff2")
```

**Optional** (this repo also serves SVG and MP4 from `/images/`):

```
 or (http.request.uri.path ends_with ".svg") or (http.request.uri.path ends_with ".mp4")
```

Click **Deploy**.

### Rule order (drag to reorder)

1. **Bypass API and admin** (top)
2. **Cache static assets** (below)

API bypass **must** stay above static caching.

---

## Part B — WAF Custom Rules

**Security → WAF → Custom rules**

Create **5 separate rules**, then drag into this order:

### Rule 1: Allow Stripe webhooks (must be #1)

| Field | Value |
|-------|-------|
| Rule name | Allow Stripe webhooks |
| Expression | `(http.request.uri.path eq "/api/stripe-webhook.php")` |
| Action | **Skip** |

Under **Skip**, check **all** of:

- All remaining custom rules
- Rate limiting rules
- Managed rules (WAF)

Click **Deploy**.

### Rule 2: Allow customer login API (must be #2)

Stops Cloudflare from challenging or blocking JSON login/register POSTs. Origin PHP already rate-limits auth (`customer-auth.php`).

| Field | Value |
|-------|-------|
| Rule name | Allow customer auth API |
| Expression | `(http.request.uri.path eq "/api/customer-auth.php" and http.request.method eq "POST")` |
| Action | **Skip** |

Under **Skip**, check:

- Rate limiting rules
- Managed rules (WAF)

Do **not** skip Bot Fight Mode (not skippable on Free anyway — keep Bot Fight **Off** in Part C).

Click **Deploy**.

### Rule 3: Protect admin

| Field | Value |
|-------|-------|
| Rule name | Protect email admin |
| Expression | `(http.request.uri.path contains "/email-admin/")` |
| Action | **Managed Challenge** |

If you get locked out of admin, edit this rule → **JS Challenge**, or temporarily set to **Log** while testing.

Click **Deploy**.

### Rule 4: Block sensitive paths

| Field | Value |
|-------|-------|
| Rule name | Block sensitive paths |
| Expression | `(http.request.uri.path contains "/.env") or (http.request.uri.path contains "/.git") or (http.request.uri.path contains "/secure-config")` |
| Action | **Block** |

Click **Deploy**.

### Rule 5: Rate limit checkout

| Field | Value |
|-------|-------|
| Rule name | Rate limit checkout |
| Expression | `(http.request.uri.path eq "/api/submit-order.php" and http.request.method eq "POST")` |
| Action | **Rate limit** |

Rate limit settings:

| Setting | Value |
|---------|-------|
| Requests | 10 |
| Period | 1 minute |
| Mitigation | Block |
| Duration | 10 minutes |
| With the same characteristics | IP address |

Click **Deploy**.

**Do not add** a separate “Rate limit login” Cloudflare rule — it fights Rule 2 and login already rate-limits in PHP (8 attempts / 15 min per IP).

### Final WAF rule order (top → bottom)

1. Allow Stripe webhooks
2. Allow customer auth API
3. Protect email admin
4. Block sensitive paths
5. Rate limit checkout

Stripe skip **must** stay at the top.

---

## Part C — Security toggles

| Location | Setting | Value |
|----------|---------|-------|
| **Security → Settings** | Security Level | **Medium** |
| **Security → Settings** | Browser Integrity Check | **On** |
| **Security → Bots** | Bot Fight Mode | **Off** (turn On later only if needed) |

Bot Fight Mode blocks `POST /api/customer-auth.php` with **403** (“Just a moment…” page). Login and checkout must work first.

---

## Part D — Origin firewall (skip on IONOS)

No server firewall needed on IONOS shared hosting.

**DNS → Records** — confirm these are **Proxied** (orange cloud):

| Record | Proxy |
|--------|-------|
| `onlybikes.shop` (A) | Proxied |
| `www` (CNAME or A) | Proxied |

Leave **MX** and **TXT** as **DNS only** (grey cloud) — mail must stay unproxied.

That hides your origin IP without a server firewall.

---

## Part E — Verify after rules are live

### Browser checks

| URL | Expected |
|-----|----------|
| https://onlybikes.shop/ | Homepage loads |
| https://onlybikes.shop/api/health.php | JSON response (not cached HTML error) |
| https://onlybikes.shop/products.html | Products page loads |
| https://onlybikes.shop/email-admin/ | Cloudflare challenge, then login page |

### Stripe webhook (Stripe Dashboard)

**Developers → Webhooks → your endpoint**

- URL: `https://onlybikes.shop/api/stripe-webhook.php`
- **Send test webhook** → should succeed (not 403/429)

### Checkout test

Add item to cart → complete a test payment → order should save in DB / email-admin.

### Cache headers (terminal)

```text
curl -I https://onlybikes.shop/main.js
# Second request: cf-cache-status: HIT (after rule 2 is active)

curl -I https://onlybikes.shop/api/health.php
# cf-cache-status: DYNAMIC or BYPASS
```

---

## Login returns 403 after Cloudflare setup

**Symptoms:** Profile modal opens; `GET /api/customer-auth.php?action=me` → **401** (normal when logged out); `POST /api/customer-auth.php` → **403** with HTML “Just a moment…”.

**Cause:** Cloudflare edge security (usually **Bot Fight Mode** and/or **Rate limit login** / managed WAF on credential POSTs). PHP is fine.

**Fix (do in order):**

1. **Security → Bots → Bot Fight Mode → Off**
2. **Security → WAF → Custom rules** — delete **Rate limit login** if you created it
3. Add **Allow customer auth API** (Rule 2 above) if missing
4. Hard-refresh the site and try login again

`401` on `?action=me` is expected until you log in successfully.

---

## If something breaks

| Problem | Fix |
|---------|-----|
| Login POST 403 | Bot Fight Off; add Rule 2 “Allow customer auth API”; remove “Rate limit login” |
| Stripe webhooks failing | Confirm Rule 1 is first; Skip includes managed + rate limit rules |
| Can't access admin | Rule 3: Managed Challenge → JS Challenge, or Log temporarily |
| Checkout blocked | Turn off Bot Fight Mode, retest |
| API returns old/wrong data | Confirm Cache Rule 1 bypasses `/api/` |
| Roast PvP / live camera broken | Confirm `/api/roast-limited/` is covered by API bypass (starts_with `/api/`) |

---

## Quick copy-paste reference

**Cache bypass:**

```
(http.request.uri.path starts_with "/api/") or (http.request.uri.path starts_with "/email-admin/")
```

**Cache static:**

```
(http.request.uri.path ends_with ".jpg") or (http.request.uri.path ends_with ".png") or (http.request.uri.path ends_with ".webp") or (http.request.uri.path ends_with ".css") or (http.request.uri.path ends_with ".js") or (http.request.uri.path ends_with ".woff2")
```

**Stripe:**

```
(http.request.uri.path eq "/api/stripe-webhook.php")
```

**Admin:**

```
(http.request.uri.path contains "/email-admin/")
```

**Block paths:**

```
(http.request.uri.path contains "/.env") or (http.request.uri.path contains "/.git") or (http.request.uri.path contains "/secure-config")
```

**Checkout rate limit:**

```
(http.request.uri.path eq "/api/submit-order.php" and http.request.method eq "POST")
```

**Allow customer auth (WAF skip):**

```
(http.request.uri.path eq "/api/customer-auth.php" and http.request.method eq "POST")
```

---

## Origin files (already in repo)

Upload root `.htaccess` with the site — it sets long `Cache-Control` on static extensions so Cloudflare can cache aggressively at the edge. Sensitive paths (`.git`, `.env`, etc.) are also blocked at origin.

---

## What this does under ad traffic

Cloudflare will:

- Serve most static assets (`/css/`, `/images/`, `/assets/`, root `.js`) without hitting IONOS
- Never cache `/api/*` or `/email-admin/*`
- Let Stripe webhooks through without WAF/rate-limit blocks
- Challenge admin logins and rate-limit checkout/auth POSTs

Checkout (`/api/create-payment-intent.php`, `/api/submit-order.php`) still hits origin — correct behavior. If checkout 503s under real ad load, upgrade IONOS tier next.
