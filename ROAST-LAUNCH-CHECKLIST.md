# Roast Solo + Live PvP — launch checklist (onlybikes.shop)

## Server `api/.env`

```env
ROAST_EVENT_ENABLED=1
ROAST_EVENT_END=2026-07-01
GROQ_API_KEY=...
OPENROUTER_API_KEY=...
ROAST_DB_HOST=...
ROAST_DB_NAME=...
ROAST_DB_USER=...
ROAST_DB_PASS=...
```

## Database

In the **Roast** MariaDB (see `api/ROAST-DEPLOY.md`), run:

- `api/sql/07-roast-jobs.sql`
- `api/sql/08-roast-pvp.sql`

Tables are also auto-created on first PvP request via `api/lib/roast-pvp.php`.

## Files to upload (IONOS `/htdocs/`)

| Path | Purpose |
|------|---------|
| `roast-pvp.html` | Live PvP UI |
| `roast-pvp.js` | Client (keep in **root**, not `/js/`) |
| `roast-limited.html` + `roast-limited.js` | Solo roast |
| `api/roast-limited/pvp.php` | Matchmaking + WebRTC signals |
| `api/roast-limited/orchestrator.php` | Vision + judge pipeline |
| `api/lib/roast-*.php` | Config, jobs, PvP, cloud APIs |

## Verify

1. `https://onlybikes.shop/api/roast-limited/orchestrator.php?ping=1`  
   → `"event_active": true`
2. Homepage shows green **Limited event** banner + nav **Solo roast** / **Live PvP**
3. `https://onlybikes.shop/roast-pvp.html` → **Start live PvP** → camera prompt
4. `https://onlybikes.shop/api/roast-limited/test-roast-health.php` (if uploaded) → models/API keys OK

## Full pipeline reference

See `api/ROAST-DEPLOY.md` for Groq/OpenRouter model routing and optional local judge (`llama-b9285/`).
