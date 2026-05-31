# Fix Bedda production (bedda.ca)

## Urgent: all `/api/*` return Apache 500 HTML

If the browser shows generic **“500 Internal Server Error”** HTML (not JSON), the whole `api/` folder is broken at the **Apache** layer — usually a bad **`api/.htaccess`** (forbidden `php_value` / `php_flag` or broken `RewriteRule` on Ionos).

### Fix (5 minutes)

1. Ionos → **File Manager** → `api/`
2. **Delete** the current `api/.htaccess` (or rename to `.htaccess.broken`)
3. Upload the new **`website/api/.htaccess`** from this repo (minimal, Ionos-safe)
4. Upload **`website/api/health.php`** and test:

```text
https://bedda.ca/api/health.php
```

Expected: JSON like `{"ok":true,"db_configured":true,...}` — **not** HTML 500.

5. Then test auth:

```text
https://bedda.ca/api/customer-auth.php?action=me
```

Expected: JSON `401` for guests — **not** HTML 500.

6. Test logging:

```bash
curl -sS -X POST https://bedda.ca/api/log-event.php \
  -H "Content-Type: application/json" \
  -d '{"events":[{"event_type":"ping","session_id":"test","user_id":"test","page":"/","data":{}}]}'
```

Expected: `{"success":true,"stored":1}` (or `stored:0` if DB down — still JSON 200).

### PHP files on the server

If APIs already worked before (you saw JSON like `"DB config missing"`), **do not replace** your existing PHP endpoints — only fix `.htaccess` first.

If `api/` is missing PHP files, upload everything under `website/api/` **except** `secure-config.test.php` and `data/`.

---

## The config file (correct name)

Your project uses:

**`api/secure-config.php`**

(on your PC: `Ionos Bedda Website\api\secure-config.php`)

Legacy name `config.local.php` is still supported as a fallback.

On the live server, `secure-config.php` **must not** be public (browser should get **403 Forbidden** — that is correct).

The error **`DB config missing`** (JSON **503**) means PHP runs but MySQL fields inside `secure-config.php` are empty or wrong:

1. Copy on Ionos has **placeholder** DB host/name/user/password
2. **Local** file was never uploaded
3. Values don’t match **Ionos → Databases**
4. File **permissions** block PHP (try `640`)

### Fix database config on Ionos

1. Open **Ionos → File Manager → `api/`**
2. Edit **`secure-config.php`** with values from Ionos **Databases** + Stripe + hCaptcha
3. Save and re-test `customer-auth.php?action=me`

---

## Run API tests locally (before uploading)

From the repo:

```bash
chmod +x website/scripts/test-api.sh
./website/scripts/test-api.sh
```

This starts PHP’s built-in server and verifies `health.php`, `log-event.php`, and `customer-auth.php`.

---

## Other frontend files in `website/`

Upload to web root when changed:

| File | Purpose |
|------|---------|
| `bedda-ai.js` | AI widget + offline FAQ fallback |
| `main.js` | Cart, auth, checkout |
| `cart.html`, `.htaccess` | `/cart` redirect |
| Legal `*-policy.html` | Policy pages |

## Best setup for Cursor

Open the **`Ionos Bedda Website`** folder (full PHP tree) **or** this repo after `website/api/` is committed, so agents can edit and test APIs safely.
