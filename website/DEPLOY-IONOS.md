# Fix Bedda production (bedda.ca)

## Root cause

Production is missing **`/api/config.local.php`**. The server has `config.php` (locked) but not the local secrets file, so **all** backend features fail:

- Login / account dashboard
- Checkout & Stripe payments
- Ask Bedda AI chat (server-side)
- Newsletter, coupons, order logging

## One-time fix on Ionos

1. Log in to **Ionos** → your hosting package → **File Manager** (or SFTP).
2. Open the **`api/`** folder on the server (same level as `customer-auth.php`).
3. Copy `config.local.php.example` → **`config.local.php`**.
4. Fill in MySQL credentials from **Ionos → Databases**.
5. Fill in **Stripe secret key** and **hCaptcha secret** (must match keys in your Stripe/hCaptcha dashboards).
6. Save. Test: `https://bedda.ca/api/customer-auth.php?action=me` should return JSON (401 for guests), **not** `DB config missing`.

## Upload frontend fixes from this folder

Upload these files to the web root on Ionos (overwrite):

| File | Fix |
|------|-----|
| `bedda-ai.js` | FAQ fallback when backend is down |
| `main.js` | Cart URL param + “Continue to Cart” opens cart modal |
| `cart.html` | Fixes `/cart` 404 → opens cart |
| `.htaccess` | Redirect `/cart` → `cart.html` |
| `privacy-policy.html`, `terms-of-service.html`, `shipping-policy.html`, `return-policy.html` | Legal pages (were 404) |
| All `*.html` | Footer links to legal pages |

After upload, hard-refresh the browser (Ctrl+Shift+R) or bump `bedda-ai.js?v=4` in HTML.

## Verify

```bash
curl -s "https://bedda.ca/api/customer-auth.php?action=me"
curl -s -X POST "https://bedda.ca/api/ai-engine.php?ajax=1" \
  -H "Content-Type: application/json" \
  -d '{"intent":"faq","prompt":"What is tallow soap?","history":[]}'
```

Both should **not** return `DB config missing` after config is deployed.

## Cursor Cloud agents

Open the **`d-Ionos-Bedda-Website`** repo (not `ionos-bedda-ai`) and add Ionos SFTP + DB secrets so agents can deploy automatically.
