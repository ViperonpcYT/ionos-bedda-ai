# Enterprise PvP — Exit Criteria Checklist

Use this checklist before declaring Live PvP **enterprise-grade**. Current stack: **v38 cascade vision** (`pvp-inline-v38` in `roast-pvp.html`).

**Cascade model:** Tier 0 instant seed (0 ms) → Tier 1 fast OR-Qwen / sidecar / hash cache (1–2 s, **no Groq**) → Tier 2 full Agents 1+2+3 via `cron-tier2.php` (15–60 s, Groq 11B reserved).

**Verdict today:** Not enterprise-ready (cascade Tier 1/2 + observability gaps).  
**Target:** All **Required** items checked; **Recommended** items at ≥80%.

---

## Phase 0 — Deploy discipline

| # | Criterion | Required | Status |
|---|-----------|----------|--------|
| 0.1 | Single source of truth for build version; prod stamp `pvp-inline-v38` matches git tag `pvp-v38-*` | Yes | ☐ upload docs still v36; code v37/v38 drift |
| 0.2 | `ROAST_EVENT_ENABLED=1` on prod when event live | Yes | ☐ verify server `api/.env` |
| 0.3 | NPC env set: `ROAST_PVP_NPC_ENABLED`, `FALLBACK_SEC=10`, `GRADE_DELAY_SEC=11` | Yes | ☐ |
| 0.4 | TURN configured (`turnConfigured: true`, not `stun_only`) | Yes | ☐ ICE smoke |
| 0.5 | Manifest assets valid (no broken refs, Talaria ref image, Stark filename) | Yes | ☐ Talaria `reference_image` empty |
| 0.6 | `validate-pvp-manifest.php` exists and passes | Yes | ☑ script exists; CI wired |
| 0.7 | Phase 0 smoke runbook executed on prod after each release | Yes | ☐ manual only |
| 0.8 | **`ROAST_OPENROUTER_API_KEY` set** — Tier 1 requires OR-Qwen when Groq skipped | Yes | ☐ |
| 0.9 | **`ROAST_VISION_SKIP_GROQ=1`** on prod until Tier 1/2 code split verified (Groq reserved for Tier 2 cron) | Yes | ☐ default `0` in `.env.example` |
| 0.10 | **`cron-tier2.php` registered** in IONOS WebCron with `CRON_SECRET` | Yes | ☐ script pending / not registered |
| 0.11 | Client timers v38: `AUTO_JUDGE_FIRST_MS=2000`, `AUTO_JUDGE_INTERVAL_MS=5000` | Yes | ☐ prod still v37 (10 s / 12 s) |

---

## Phase 1 — Reliability (cascade Tier 0/1)

| # | Criterion | Required | Status |
|---|-----------|----------|--------|
| 1.1 | Vision structured logging (`match_id`, `ms`, `backend`, `fallback`, `score_tier`) | Yes | ☐ flat `roast-failures.log` only |
| 1.2 | Versioned SQL migrations for `tier2_pending`, `tier2_ready`, `tier2_frame_path` (no silent `ALTER` in request path) | Yes | ☐ |
| 1.3 | Golden image fixture set committed under `api/fixtures/pvp-live/` | Yes | ☐ dir + README; images pending |
| 1.4 | Client surfaces `vision_hint`, `frame_fallback`, `frame_skipped`, **`score_tier`**, **`provisional`**, **`display_score`** | Yes | ☐ v37 partial; v38 tier UI pending |
| 1.5 | NPC grade idempotent / `grade_pending` clears within 15 s | Yes | ☐ monitor in staging |
| 1.6 | **Tier 0 seed split from `live_count`** — join/job_id seed does not increment frame count | Yes | ☐ seed still increments count |
| 1.7 | **Tier 1 fast path:** `roast_pvp_score_frame_fast()` OR-Qwen-first; **zero Groq calls on `live_frame`** | Yes | ☐ today Groq-first in `roast_cloud_vision()` |
| 1.8 | **Image-hash cache** (5 min TTL) for repeat frames | Recommended | ☐ not built |
| 1.9 | **First provisional score ≤3 s** of duel start (S9 staging smoke) | Yes | ☐ v37 first frame ~10 s |
| 1.10 | **`roast_pvp_merge_tier_score()`** — monotonic Tier 1→2 merge with hysteresis | Yes | ☐ NPC-only max today |
| 1.11 | Vision SLO: Tier 1 ≥85% non-fallback, p95 ≤3 s; Tier 2 p95 ≤60 s | Recommended | ☐ |

---

## Phase 2 — Observability

| # | Criterion | Required | Status |
|---|-----------|----------|--------|
| 2.1 | Metrics emitted: `tier1_latency_ms`, `tier2_latency_ms`, `vision_cache_hit`, `score_tier`, `groq_budget_remaining` on live_frame, tier2 cron, npc_grade | Yes | ☐ `roast_log_pvp_metric` partial |
| 2.2 | Dashboard: Tier 1 success %, Tier 2 completion %, fallback %, queue depth, Groq budget | Yes | ☐ |
| 2.3 | Alert: Tier 1 vision fallback >30% (15 min window) | Yes | ☐ |
| 2.4 | Alert: health 503, TURN `stun_only`, Groq budget exhausted, **`tier2_pending` backlog >10** | Recommended | ☐ |
| 2.5 | Runbooks published (`docs/runbooks/`) and drill-tested once | Yes | ☐ RB-* pending |
| 2.6 | Log retention policy enforced (30 d failures, 14 d PHP errors, 7 d `pvp-metrics.log`) | Recommended | ☐ |
| 2.7 | **`ROAST_PVP_METRICS_LOG`** configured in prod `.env` | Yes | ☐ in `.env.example` only |
| 2.8 | **`ROAST_VISION_VPS_URL` sidecar** documented (`docs/VISION-SIDECAR.md`) and wired in `roast-cloud-vision.php` | Recommended | ☐ env defined, not wired |

---

## Phase 3 — Testing & CI

| # | Criterion | Required | Status |
|---|-----------|----------|--------|
| 3.1 | `.github/workflows/roast-pvp-ci.yml` on `push` + `pull_request` | Yes | ☑ exists (`pvp-unit` job) |
| 3.2 | CI job `pvp-unit` runs `test-pvp-cred-scores.php` — **blocks cred regression** | Yes | ☑ |
| 3.3 | CI job `pvp-manifest` runs `validate-pvp-manifest.php` | Yes | ☑ same job as 3.2 |
| 3.4 | CI job `pvp-smoke-offline` uses `api/fixtures/pvp-live/` (no secrets) | Yes | ☐ fixtures dir + README; job + images pending |
| 3.5 | CI job `pvp-vision-integration` on `main`/nightly with Groq/OR secrets | Recommended | ☐ |
| 3.6 | Staging environment documented and provisioned | Yes | ☑ `docs/STAGING-PVP.md` |
| 3.7 | Staging smoke **S1–S10** pass before prod promotion (cascade gate) | Yes | ☐ S9/S10 not yet exercised |
| 3.8 | Post-deploy smoke automated (health + cred + ICE + tier fields) | Recommended | ☐ Ionos cron or GH scheduled |
| 3.9 | **`cron-tier2.php` unit smoke** — manual `?key=CRON_SECRET` returns JSON with `ok: true` | Yes | ☐ script pending |
| 3.10 | **`ROAST_VISION_SKIP_GROQ=1` verified on staging** — Tier 1 responses never `groq_*` backend | Yes | ☐ S10 gate in STAGING-PVP §4 |

### Phase 3 exit gate (minimum bar)

All must be ☑ before Phase 3 is **complete**:

- [ ] **3.1** — `roast-pvp-ci.yml` exists
- [ ] **3.2** — cred unit test blocks merge on failure
- [ ] **3.3** — manifest validation blocks merge on failure
- [ ] **3.4** — offline fixture smoke blocks merge
- [ ] **3.6** — `staging.onlybikes.shop` live with separate DB/env
- [ ] **3.7** — promotion flow exercised once with **S1–S10** pass
- [ ] **3.9** — `cron-tier2.php` smoke passes
- [ ] **3.10** — Groq skip verified on staging Tier 1

---

## Phase 4 — Scale & cost

| # | Criterion | Required | Status |
|---|-----------|----------|--------|
| 4.1 | NPC manifest pre-graded (`identity`/`inspect` in JSON) — instant Tier 0, no 11 s grade delay | Recommended | ☐ |
| 4.2 | Groq budget monitored with escalation (`ROAST_GROQ_DAILY_MAX=25`); **Tier 2 cron only** | Yes | ☐ cap=25; Tier 1 still consumes today |
| 4.3 | Metered TURN usage tracked; monthly cap alert | Recommended | ☐ |
| 4.4 | Cost caps documented with RB-COST-01 runbook (OR credits + Groq Tier 2 volume) | Yes | ☐ |
| 4.5 | Scale-out path documented if queue depth or `tier2_pending` exceeds SLO | Recommended | ☐ |
| 4.6 | **Hash cache + sidecar** reduce OR API calls at 5 s Tier 1 cadence | Recommended | ☐ |
| 4.7 | Golden fixtures + nightly pre-grade lookup table for distillation | Recommended | ☐ see `api/fixtures/pvp-live/` |

---

## Master exit — enterprise Y/N

Check **all Required rows** across phases:

| Area | Required items | Met |
|------|----------------|-----|
| Deploy / cascade env | 0.1–0.7, 0.8–0.10, 0.11 | 1 / 11 |
| Reliability (Tier 0/1/2) | 1.1–1.5, 1.6–1.7, 1.9–1.10 | 0 / 9 |
| Observability | 2.1–2.3, 2.5, 2.7 | 0 / 5 |
| Testing / CI / staging | 3.1–3.4, 3.6–3.7, 3.9–3.10 | 3 / 8 |
| Cost | 4.2, 4.4 | 0 / 2 |

**Enterprise-ready when:** 35/35 Required ☑ (Recommended items tracked separately).

---

## Quick smoke reference (prod or staging)

```bash
# Cred regression (no API keys)
php api/roast-limited/test-pvp-cred-scores.php

# Manifest
php api/roast-limited/validate-pvp-manifest.php

# Live frame — golden fixtures (offline, no keys)
php api/roast-limited/test-pvp-live-frame.php --fixtures

# Opponent vision (keys required)
php api/roast-limited/test-pvp-opponent-images.php
```

```text
GET /api/roast-limited/test-roast-health.php?key=CRON_SECRET
GET /api/roast-limited/pvp.php?action=ice
GET /api/roast-limited/cron-tier2.php?key=CRON_SECRET
```

Browser cascade checks (S9):

- `roast-pvp.html?debug=1` → confirm build stamp `pvp-inline-v38`
- Start duel → first `live_frame` within **2 s** → `score_tier >= 1`, `provisional: true`
- After 60 s + cron → poll shows `score_tier: 2`, `tier2_ready: true`

Env verify (S10):

```env
ROAST_VISION_SKIP_GROQ=1    # Tier 1 must not call Groq
ROAST_OPENROUTER_API_KEY=... # Tier 1 required
ROAST_GROQ_API_KEY=...       # Tier 2 cron only
```

---

## Related

- [STAGING-PVP.md](./STAGING-PVP.md) — staging setup, cascade env vars, **S1–S10** promotion flow
- [api/fixtures/pvp-live/README.md](../api/fixtures/pvp-live/README.md) — golden image spec + CI alignment
- `FILEZILLA-ROAST-UPLOAD.txt` — deploy file list (sync to v38)
- `api/IONOS-CRON-PASTE.txt` — WebCron incl. `cron-tier2.php`
- `api/ROAST-DEPLOY.md` — full env reference + vision cascade fallback chain

*Last updated: Phase 3 build — v38 cascade criteria across all phases.*
