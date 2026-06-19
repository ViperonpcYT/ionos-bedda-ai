# RB-DEPLOY-01 — PvP v38 Deploy, Rollback, Cloudflare Purge, Cron-Tier2

**Severity:** P0 (release) · **Owner:** Platform + QA  
**Scope:** Production `onlybikes.shop` Live PvP (`pvp-inline-v38`)  
**Prerequisite:** Staging smoke S1–S8 pass per [STAGING-PVP.md](../STAGING-PVP.md)

---

## 1. Release identity

| Item | v38 value |
|------|-----------|
| Inline build stamp | `pvp-inline-v38` in `roast-pvp.html` (`PVP_INLINE_BUILD`) |
| Console line | `[Roast PvP] DEPLOY CHECK — build=pvp-inline-v38` |
| Git tag (post-promote) | `pvp-v38-YYYY-MM-DD` |
| API version | `ROAST_PVP_API_VERSION` in `api/lib/roast-config.php` |
| Upload manifest | `ROAST-PVP-UPLOAD.txt`, `FILEZILLA-ROAST-UPLOAD.txt` |

**Rule:** Prod stamp must **not** include `-staging`. Staging uses `pvp-inline-v38-staging`.

---

## 2. Pre-deploy gate

| # | Check | Command / URL | Pass |
|---|-------|---------------|------|
| G1 | CI green | `roast-pvp-ci.yml` → `pvp-unit`, `pvp-manifest` | ☐ |
| G2 | Staging S1–S8 | [STAGING-PVP.md §4](../STAGING-PVP.md) | ☐ |
| G3 | Manifest valid | `php api/roast-limited/validate-pvp-manifest.php` | ☐ |
| G4 | Cred unit | `php api/roast-limited/test-pvp-cred-scores.php` → 0 failed | ☐ |
| G5 | Tag previous prod | Note current tag (e.g. `pvp-v37-*`) for rollback | ☐ |

---

## 3. Deploy to production

### 3.1 FileZilla upload

Remote root: `/htdocs/` (IONOS). Upload **same bytes** as staging except server-only `api/.env`.

**PvP v38 delta** (minimum set — full list in `ROAST-PVP-UPLOAD.txt`):

```text
roast-pvp.html
roast-credits-ui.js
api/lib/roast-score.php
api/lib/roast-pvp.php
api/lib/roast-pvp-npc.php
api/lib/roast-config.php
api/lib/roast-cloud-vision.php
api/lib/runtime-credits.php
api/runtime-credits.php
api/roast-limited/pvp.php
api/roast-limited/test-roast-health.php
api/data/pvp-opponents.json
images/pvp-opponents/          (if manifest assets changed)
```

**Server env** (edit on server — do not commit):

```env
ROAST_EVENT_ENABLED=1
ROAST_PVP_NPC_ENABLED=1
ROAST_PVP_NPC_FALLBACK_SEC=10
ROAST_PVP_NPC_GRADE_DELAY_SEC=11
ROAST_GROQ_DAILY_MAX=25
```

### 3.2 Cloudflare purge (required)

Inline script in `roast-pvp.html` is cached aggressively. **Purge after every prod upload.**

| Method | URLs |
|--------|------|
| **Custom purge (preferred)** | `https://onlybikes.shop/roast-pvp.html` |
| | `https://onlybikes.shop/roast-credits-ui.js` |
| | `https://onlybikes.shop/api/roast-limited/pvp.php` |
| | `https://onlybikes.shop/images/pvp-opponents/*` (if manifest changed) |
| **Nuclear** | Caching → Purge Everything (use only if custom purge fails) |

Steps:

1. Cloudflare dashboard → **Caching** → **Configuration** → **Custom Purge** → **Purge by URL**
2. Paste URLs above (one per line)
3. Hard refresh browser: `Ctrl+Shift+R`
4. Optional: purge staging mirror if promoting from staging cache test

### 3.3 Post-deploy smoke (prod)

| # | Test | Pass criteria |
|---|------|---------------|
| P1 | Build stamp | `https://onlybikes.shop/roast-pvp.html?debug=1` → footer `Build: pvp-inline-v38` |
| P2 | View source | Search `pvp-inline-v38` (NOT v37/v36) |
| P3 | Console | `[Roast PvP] DEPLOY CHECK — build=pvp-inline-v38` |
| P4 | Health | `GET /api/roast-limited/test-roast-health.php?key=CRON_SECRET` → `ok: true` |
| P5 | ICE / TURN | `GET /api/roast-limited/pvp.php?action=ice` → `turnConfigured: true` |
| P6 | Config | `GET /api/roast-limited/pvp.php?action=config` → `ok: true` |
| P7 | Cred CLI | `php api/roast-limited/test-pvp-cred-scores.php` → 0 failed |
| P8 | NPC round | Solo queue ≥12s → NPC video + grade ~11s |

### 3.4 Tag release

```bash
git tag pvp-v38-YYYY-MM-DD
# push tag when ready: git push origin pvp-v38-YYYY-MM-DD
```

---

## 4. Rollback (bad deploy)

**Triggers:** Wrong build stamp after purge, cred regression, ICE `stun_only`, health 503, manifest broken refs.

| Step | Action |
|------|--------|
| R1 | Identify last good tag (e.g. `pvp-v37-2026-06-17`) |
| R2 | Check out tag locally; upload **same file set** as §3.1 from tag tree |
| R3 | **Do not** roll back `api/.env` unless env change caused incident |
| R4 | Cloudflare purge §3.2 URLs |
| R5 | Prod smoke P1–P7 (minimum); P8 if PvP logic changed |
| R6 | If cred scores wrong: confirm `api/lib/roast-score.php` matches tag |
| R7 | Log incident: deploy tag, rollback tag, root cause, duration |

**Time target:** Rollback + verify <20 min.

---

## 5. Cron-tier2 (IONOS WebCron — roast / PvP ops)

**Tier 1** (existing): email-queue, payment-reconcile, points-audit — see `api/IONOS-CRON-PASTE.txt`.

**Tier 2** (roast/PvP — add after v38 deploy):

| Name | Schedule | URL | Purpose |
|------|----------|-----|---------|
| `roast-health` | `*/15 * * * *` | `https://onlybikes.shop/api/roast-limited/test-roast-health.php?key=CRON_SECRET` | 503 → alert; keys, TURN, Groq budget |
| `roast-purge-temp` | `0 */6 * * *` | `https://onlybikes.shop/api/roast-limited/purge-temp.php?key=CRON_SECRET` | Temp frames + stale jobs |
| `roast-pvp-smoke` | `0 8,20 * * *` | Manual curl bundle below | Twice-daily cred + ICE sanity |

**IONOS setup:** Control panel → Cron → WebCron → HTTP GET → paste URL only (no spaces in name).

`CRON_SECRET` must match `api/.env` / `secure-config.php` (generate: `openssl rand -hex 32`).

### 5.1 Twice-daily smoke script (optional SSH cron)

If IONOS cannot run multi-step HTTP, SSH cron on server:

```bash
#!/bin/bash
cd /homepages/.../htdocs
php api/roast-limited/test-pvp-cred-scores.php || logger -t roast-pvp "CRED REGRESSION"
php api/roast-limited/validate-pvp-manifest.php || logger -t roast-pvp "MANIFEST FAIL"
curl -sf "https://onlybikes.shop/api/roast-limited/pvp.php?action=ice" | grep -q '"turnConfigured":true' \
  || logger -t roast-pvp "TURN NOT CONFIGURED"
```

### 5.2 Cron-tier2 alert routing

| Cron | Fail signal | Runbook |
|------|-------------|---------|
| `roast-health` | HTTP 503 or `ok: false` | [RB-VISION-01](./RB-VISION-01.md) if vision keys/budget; else [RB-DEPLOY-01 §4](#4-rollback-bad-deploy) |
| `roast-purge-temp` | HTTP 403 | Fix `CRON_SECRET` mismatch |
| `roast-pvp-smoke` | Non-zero exit / syslog | §4 Rollback if cred or manifest fails |

**Staging:** Duplicate tier2 crons with `staging.onlybikes.shop` origin for pre-promote monitoring.

---

## 6. Deploy drill checklist (Phase 2 exit)

Run once on **staging**, then confirm prod procedure documented.

| # | Step | Staging | Prod doc |
|---|------|---------|----------|
| D1 | Deploy v38-staging stamp | ☐ | — |
| D2 | Purge Cloudflare staging URLs | ☐ | ☐ |
| D3 | S1–S8 smoke pass | ☐ | — |
| D4 | Promote to prod (tabletop — no prod inject) | — | ☐ procedure reviewed |
| D5 | Rollback drill: redeploy v37-staging tag | ☐ | — |
| D6 | Verify cron-tier2 `roast-health` fires (check IONOS log) | ☐ | ☐ |
| D7 | Record drill date + operator | ☐ | ☐ |

Mark ENTERPRISE-PVP-EXIT §2.5 when D1–D7 complete.

---

## Related

- [RB-VISION-01](./RB-VISION-01.md) — vision fallback / Groq exhausted
- [STAGING-PVP.md](../STAGING-PVP.md) — promotion flow §5
- [ENTERPRISE-PVP-EXIT.md](../ENTERPRISE-PVP-EXIT.md) — Phase 2 exit criteria
- `ROAST-PVP-UPLOAD.txt` — prod upload list
- `api/IONOS-CRON-PASTE.txt` — tier1 cron template

*Last updated: Phase 2 build — RB-DEPLOY-01 initial publish (v38 target).*
