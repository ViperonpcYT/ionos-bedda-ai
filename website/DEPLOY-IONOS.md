# Fix Bedda production (bedda.ca)

## The config file (correct name)

Your project uses:

**`api/secure-config.php`**

(on your PC: `Ionos Bedda Website\api\secure-config.php`)

That is the right file — **not** `config.local.php` (that was my mistake).

On the live server, `secure-config.php` **already exists** (browser gets 403 Forbidden, which is normal — secrets must not be public). The error **`DB config missing`** means PHP can’t load **valid database credentials from that file** — usually because:

1. The copy on Ionos has **empty or placeholder** DB host/name/user/password  
2. Your **local** file is correct but **was never uploaded** (or an old version is on the server)  
3. Values don’t match **Ionos → Databases** (wrong host, database name, or user)  
4. File **permissions** on Ionos stop PHP from reading it (try 640, owner = web user)

## Fix on Ionos

1. Open **Ionos → File Manager → `api/`**
2. Open **`secure-config.php`** on the server and your local copy side by side
3. Ensure the **MySQL section** matches Ionos database panel exactly:
   - Host (often `dbXXXXXXXX.hosting-data.io`, not always `localhost`)
   - Database name  
   - Username  
   - Password  
4. Ensure **Stripe secret key** and **hCaptcha secret** are filled (not placeholders)
5. **Save** and re-upload if you edited locally
6. Test:

```text
https://bedda.ca/api/customer-auth.php?action=me
```

Should return JSON like `{"success":false,...}` or guest 401 — **not** `"DB config missing"`.

## Other fixes in `website/` (frontend)

Upload to web root if not already on Ionos:

| File | Fix |
|------|-----|
| `bedda-ai.js` | FAQ answers when backend is down |
| `main.js` | Cart modal + `?openCart=1` |
| `cart.html`, `.htaccess` | `/cart` no longer 404 |
| Legal `*-policy.html` pages | Were missing |
| Updated `*.html` footers | Links to legal pages |

## Best setup for Cursor

Open the **`Ionos Bedda Website`** folder in Cursor (not `ionos-bedda-ai`) so agents can read `secure-config.php` structure and deploy safely.
