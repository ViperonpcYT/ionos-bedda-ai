# RB-VISION-01 — High Vision Fallback / CONFIG / Groq Exhausted

**Severity:** P1 (player-visible cred degradation) · **Owner:** Platform  
**Scope:** Live PvP frame scoring, solo roast vision (Agents 1–3)  
**SLO:** ≥85% non-fallback frames, p95 vision latency ≤8s (15 min window)

---

## 1. When to use this runbook

| Trigger | Alert / signal | Action |
|---------|----------------|--------|
| **High fallback** | `vision_fallback` or `frame_fallback` >30% over 15 min | §3 Diagnose → §4 Mitigate |
| **CONFIG** | Health `failed` includes `groq_api_key_set` or `openrouter_api_key_set`; logs show `CONFIG` | §5 CONFIG recovery |
| **Groq exhausted** | Health `groq_budget.available: false`; logs `GROQ_SKIPPED` | §6 Groq budget recovery |
| **Chain exhausted** | `VISION_CHAIN_EXHAUSTED` in `api/logs/roast-failures.log` | §3 + §6 |

**Client symptoms:** Stranger cred stuck at manifest `starting_score`; overlay shows `vision_hint` / provisional score floor (~54–70); console `frame_fallback: true`.

---

## 2. Vision chain (reference)

```text
Primary   → Groq llama-3.2-11b-vision-preview     (consumes ROAST_GROQ_DAILY_MAX)
Fallback1 → OpenRouter meta-llama/llama-3.2-11b-vision-instruct
Fallback2 → OpenRouter qwen/qwen-2.5-vl-3b-instruct
Terminal  → live_frame_fallback (provisional cred, no cloud vision)
```

Skip Groq when `ROAST_VISION_SKIP_GROQ=1` **or** `used_today >= ROAST_GROQ_DAILY_MAX` (default cap **25**).

Structured outcomes: `api/logs/roast-failures.log` → `code: VISION_OUTCOME` with `backend`, `fallback`, `ms`, `match_id_hash`.

---

## 3. Diagnose (5 min)

### 3.1 Health endpoint

```bash
curl -s "https://onlybikes.shop/api/roast-limited/test-roast-health.php?key=CRON_SECRET" | jq .
```

| Field | Healthy | Problem |
|-------|---------|---------|
| `ok` | `true` | `503` → note `failed[]` |
| `checks.groq_api_key_set` | `true` | CONFIG — §5 |
| `checks.openrouter_api_key_set` | `true` | CONFIG — §5 |
| `checks.groq_budget.available` | `true` | Exhausted — §6 |
| `checks.groq_budget.used_today` | `< daily_max` | At cap — §6 |
| `checks.tmp_dir_writable` | `true` | Fix permissions on `api/cache/roast-tmp/` |

Staging: swap origin to `https://staging.onlybikes.shop`.

### 3.2 Log tail (SSH on IONOS)

```bash
tail -200 api/logs/roast-failures.log | grep -E 'VISION_OUTCOME|VISION_CHAIN|GROQ_SKIPPED|CONFIG'
tail -50  api/logs/pvp-metrics.log
```

Count fallback rate (last 15 min):

```bash
grep VISION_OUTCOME api/logs/roast-failures.log | tail -500 | jq -r '.context.fallback' | sort | uniq -c
```

### 3.3 Live frame smoke

```bash
php api/roast-limited/test-pvp-live-frame.php
php api/roast-limited/test-pvp-opponent-images.php   # keys required; target <30% vision_fallback
```

### 3.4 PvP config sanity

```bash
curl -s "https://onlybikes.shop/api/roast-limited/pvp.php?action=config" | jq .
```

Confirm `apiVersion` matches expected release (v38: `v1.2.6-pvp-cred` or later stamp in `roast-config.php`).

---

## 4. Mitigate high fallback (non-CONFIG)

| Step | Action | Notes |
|------|--------|-------|
| 4.1 | Confirm Groq + OpenRouter status pages | Groq outage → expect FB1/FB2 spike; no code change |
| 4.2 | Check `ROAST_VISION_TIMEOUT_SEC` (default 12) | Raise to 15 temporarily if timeouts dominate logs |
| 4.3 | Verify OpenRouter credits / rate limits | FB1/FB2 failures → `VISION_CHAIN_EXHAUSTED` |
| 4.4 | **Temporary** raise `ROAST_GROQ_DAILY_MAX` | Edit `api/.env`; cap still protects spend — see §6 |
| 4.5 | Enable `ROAST_VISION_SKIP_GROQ=0` if manually set | Forces Groq back into chain when budget allows |
| 4.6 | NPC rounds only | Players still get manifest scores; AI grade at ~11s may also fallback |

**Do not** disable `ROAST_OPENROUTER_API_KEY` — it is required when Groq skips or fails.

After changes: re-run §3.1 and one browser round (`roast-pvp.html?debug=1` → confirm cred updates after ~11s).

---

## 5. CONFIG recovery (missing keys)

**Symptom:** Health `503`, `failed: ["groq_api_key_set"]` and/or `openrouter_api_key_set`; vision returns `CONFIG` before any provider call.

| Step | Action |
|------|--------|
| 5.1 | SSH → edit `/htdocs/api/.env` (never commit) |
| 5.2 | Set `ROAST_GROQ_API_KEY=gsk_...` from [Groq console](https://console.groq.com) |
| 5.3 | Set `ROAST_OPENROUTER_API_KEY=sk-or-...` from [OpenRouter keys](https://openrouter.ai/keys) |
| 5.4 | Confirm `ROAST_PIPELINE_MODE=cloud_first` |
| 5.5 | Re-run health → expect `ok: true` |
| 5.6 | Run `test-pvp-live-frame.php` → `score_frame_ok: true`, `vision_fallback: false` preferred |

**Rotation:** Generate new keys, update `.env`, old keys revoked at provider. No Cloudflare purge needed for key-only changes.

---

## 6. Groq budget exhausted

**Symptom:** `groq_budget.available: false`, `remaining: 0`, vision logs `GROQ_SKIPPED` → OpenRouter primary for that request.

Budget tracked in `cloud_usage_daily.groq_calls` (DB) with file fallback `api/cache/roast-groq-daily.json`.

| Step | Action | Risk |
|------|--------|------|
| 6.1 | **Wait for UTC midnight reset** | Zero cost; Groq resumes automatically |
| 6.2 | Raise `ROAST_GROQ_DAILY_MAX` in `api/.env` (e.g. 25 → 40) | Higher spend — notify owner |
| 6.3 | Set `ROAST_VISION_SKIP_GROQ=1` | Forces OpenRouter-only; saves Groq for judge if needed |
| 6.4 | Verify OpenRouter handles load | If OR also failing → provisional creds only |

Check current usage:

```bash
curl -s "https://onlybikes.shop/api/roast-limited/test-roast-health.php?key=CRON_SECRET" \
  | jq '.checks.groq_budget'
```

**Escalation:** If fallback >30% persists with both keys valid and budget available → open provider ticket; attach last 50 `VISION_OUTCOME` lines.

---

## 7. Verification checklist

- [ ] Health `ok: true`, both API keys set
- [ ] `groq_budget.available: true` (or OpenRouter-only acknowledged)
- [ ] `test-pvp-live-frame.php` → `score_frame_ok: true`
- [ ] Opponent images test: `vision_fallback` <30% on reference set
- [ ] One live NPC round: cred updates after grade delay (~11s)
- [ ] 15 min log sample: fallback rate <30%

---

## 8. Drill procedure (Phase 2 exit — run once per quarter)

| # | Inject | Expected | Pass |
|---|--------|----------|------|
| D1 | Unset `ROAST_GROQ_API_KEY` in staging `.env` | Health 503, `groq_api_key_set` failed | ☐ |
| D2 | Restore key; set `ROAST_GROQ_DAILY_MAX=0` | `GROQ_SKIPPED`, OpenRouter serves frames | ☐ |
| D3 | Restore cap=25; run opponent-images test | Majority non-fallback | ☐ |
| D4 | Document time-to-recover | <15 min on staging | ☐ |

**Drill log:** Record date, operator, inject step, recovery time in team notes. Mark ENTERPRISE-PVP-EXIT §2.5 when complete.

---

## Related

- [RB-DEPLOY-01](./RB-DEPLOY-01.md) — deploy / rollback / cron-tier2
- [STAGING-PVP.md](../STAGING-PVP.md) — staging smoke S1–S8
- [api/ROAST-DEPLOY.md](../../api/ROAST-DEPLOY.md) — full env reference
- `api/lib/roast-cloud-vision.php` — chain implementation

*Last updated: Phase 2 build — RB-VISION-01 initial publish.*
