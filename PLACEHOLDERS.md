# OnlyBikes Launch Placeholders

Everything below is intentionally a placeholder until IONOS hosting, domain, email, and Stripe are ready.

- `js/site-config.js`: `siteOrigin`, `supportEmail`, social links.
- `api/.env.example`: copy to `api/.env` on IONOS — **five** database blocks (see `api/IONOS-DATABASES.md`).
- `api/sql/*.sql`: run one file per IONOS database in phpMyAdmin.
- `api/secure-config.php.example`: copy to `api/secure-config.php` on the server.
- `api/public-config.php`: exposes only safe public values to the browser.
- `images/onlybikes-og.jpg`: add final Open Graph image before launch.
- Stripe webhook: set `STRIPE_WEBHOOK_SECRET` in `api/.env` after creating endpoint → `/api/stripe-webhook.php`.
- Cloudflare (same rules as bedda.ca): see `CLOUDFLARE-ONLYBIKES.md`. One-command deploy: `scripts/apply-cloudflare-onlybikes.ps1` (needs `CLOUDFLARE_API_TOKEN`).
- Product images: current pages use visual placeholders until supplier/product photos are ready.
- Legal pages: have `privacy.html`, `terms.html`, and `returns.html` reviewed before launch.

Do not commit `api/.env`.
