# PvP live-frame golden images

Committed JPEG/WebP fixtures for **offline** CI (`pvp-smoke-offline` job), staging smoke **S6**, and local smoke without vision API keys.

Aligned with **v38 cascade vision**: fixtures validate Tier 1 cred bands and rejection paths; Tier 2 full-agent grades are covered by nightly integration tests, not this directory.

---

## Fixture files

| File | Subject | Expected `visible_subject` | Tier 1 score band (cred) | Notes |
|------|---------|--------------------------|--------------------------|-------|
| `hero-full-bike.jpg` | Full e-bike, top camera, good light | `full_bike` | **75–95** | Primary S6 pass case; should **not** trigger `vision_fallback` when keys present |
| `partial-bike.jpg` | Bike partially in frame, identifiable make | `partial_bike` | **62–74** | Tier 1 provisional; Tier 2 may revise ±5 pts |
| `parts-only.jpg` | Wheel/tire close-up, no full bike | `parts_only` | **≤25** | Solo harsh; PvP Tier 1 may show low provisional |
| `no-bike-face.jpg` | Rider face / no bike | — | rejected (`no_bike`) | `score_ok: false` or `no_bike: true` |
| `low-light-bike.jpg` | Full bike, poor light | `full_bike` or fallback | **54–70** | Expect `vision_fallback: true`; v38 UI hides bare 54 until real vision |

**Status:** Directory scaffolded (`.gitkeep` + this README). Add the five images before enabling `pvp-smoke-offline` as a merge gate.

---

## Cascade alignment (v38)

| Concern | How fixtures map |
|---------|------------------|
| **Tier 0** | Not fixture-tested — NPC `starting_score` / session cache are integration-only |
| **Tier 1 fast path** | `--fixtures` runs Agent 1 identify + cred only (same as live `live_frame` Tier 1) |
| **Tier 2 cron** | Not fixture-tested — requires DB match row + `cron-tier2.php` (staging **S10**) |
| **`ROAST_VISION_SKIP_GROQ=1`** | Offline fixtures skip all cloud APIs; CI uses deterministic cred from parsed identity stubs when `--fixtures` mode supplies mock identity (see test script) |
| **Hash cache** | Re-run `--fixtures` twice; second run should report cache hit when cache module ships |

Expected JSON fields on each fixture row (mirrors live `live_frame` Tier 1):

```json
{
  "score_ok": true,
  "shame_score": 82,
  "visible_subject": "full_bike",
  "vision_fallback": false,
  "score_tier": 1,
  "provisional": true
}
```

When `--fixtures` is not yet wired, the test falls back to `images/products/Baja Headlight.jpg` (single smoke path).

---

## Rules

1. **Size:** 640×480 or smaller; keep each file **< 200 KB** for fast CI.
2. **Format:** `.jpg` or `.webp` only.
3. **Privacy:** No real customer photos — use stock/product shots or synthetic test captures.
4. **Do not** duplicate `images/pvp-opponents/` NPC assets here; golden set is for **generic live-frame behavior**, not roster sync.
5. **Do not** commit API keys — offline mode must pass with zero `ROAST_GROQ_API_KEY` / `ROAST_OPENROUTER_API_KEY`.

---

## Local smoke

```bash
# Score all golden fixtures (no API keys when --fixtures wired)
php api/roast-limited/test-pvp-live-frame.php --fixtures

# Single product smoke (legacy fallback)
php api/roast-limited/test-pvp-live-frame.php
```

Pass criteria for `--fixtures`:

- All five files present
- `hero-full-bike.jpg`: `score_ok: true`, score in 75–95
- `no-bike-face.jpg`: rejected or `no_bike: true`
- `0` rows with unexpected `score_ok: false` on hero/partial

---

## CI usage (`.github/workflows/roast-pvp-ci.yml`)

| Job | Fixture role | Blocks merge |
|-----|--------------|--------------|
| `pvp-unit` | Runs `test-pvp-cred-scores.php` only (deterministic cred math) | Yes |
| `pvp-manifest` | Validates opponent assets, not this dir | Yes |
| `pvp-smoke-offline` | **`test-pvp-live-frame.php --fixtures`** on this directory | Yes (when job + images land) |
| `pvp-vision-integration` | Uses `test-pvp-opponent-images.php` + real keys on `main`/nightly | No (alert only) |

**CI wiring checklist:**

1. Commit all five fixture images under `api/fixtures/pvp-live/`
2. Add `pvp-smoke-offline` job to `roast-pvp-ci.yml`:
   ```yaml
   pvp-smoke-offline:
     name: Golden fixture smoke
     runs-on: ubuntu-latest
     steps:
       - uses: actions/checkout@v4
       - uses: shivammathur/setup-php@v2
         with:
           php-version: '8.2'
       - run: php api/roast-limited/test-pvp-live-frame.php --fixtures
   ```
3. Ensure test script reads `$root/api/fixtures/pvp-live/*.jpg` and fails if any expected file missing

Until step 2–3 ship, CI uses the existing `pvp-unit` job only; staging **S6** is the manual gate.

---

## Staging smoke cross-reference

| Staging test | Fixture tie-in |
|--------------|----------------|
| **S4** | `test-pvp-cred-scores.php` — same cred bands as table above |
| **S6** | `test-pvp-live-frame.php --fixtures` — must pass on server SSH before promotion |
| **S9** | Browser duel — live Tier 1 must match hero band within ~10 pts of fixture score |
| **S10** | Not fixture-scoped — cron + Groq skip |

---

## Updating golden set

When cred scoring logic changes (`api/lib/roast-score.php`):

1. Re-capture expected bands in `test-pvp-cred-scores.php` first
2. Re-shoot or re-export fixtures if live-frame integration drifts
3. Re-run staging **S6** + **S9** after deploy
4. If Tier 2 merge rules change (`roast_pvp_merge_tier_score`), update **Tier 1 vs Tier 2** band notes in the table above — fixtures remain Tier 1 scope

When adding a new fixture category (e.g. `sidecar-mirror.jpg` for VPS path testing), extend this table and add a row to `test-pvp-cred-scores.php` if cred math differs.

---

## Related

- [docs/STAGING-PVP.md](../../docs/STAGING-PVP.md) — cascade smoke S1–S10
- [docs/ENTERPRISE-PVP-EXIT.md](../../docs/ENTERPRISE-PVP-EXIT.md) — Phase 1.3, Phase 3.4 exit rows
- `api/roast-limited/test-pvp-cred-scores.php` — deterministic cred unit tests (no images)
- `api/roast-limited/test-pvp-live-frame.php` — live frame smoke (wire `--fixtures` here)

*Last updated: Phase 3 build — v38 cascade + CI alignment.*
