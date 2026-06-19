# Vision Sidecar — Tier 1 Live PvP (Phase 2)

Self-hosted small VLM for **fast provisional bike identification** during Live PvP duels. IONOS runs PHP orchestration only; vision inference runs on a **home PC** or **Hetzner CX22** (or similar VPS).

**Related:** [STAGING-PVP.md](./STAGING-PVP.md) · [ENTERPRISE-PVP-EXIT.md](./ENTERPRISE-PVP-EXIT.md) · [api/ROAST-DEPLOY.md](../api/ROAST-DEPLOY.md)

---

## Why IONOS cannot host a VLM

| Constraint | IONOS shared hosting | Sidecar host |
|------------|---------------------|--------------|
| RAM | ~640 MB PHP ceiling; OOM on multimodal GGUF | 4–16 GB (CX22 or desktop) |
| Binary | Text-only [`llama-cli`](../api/lib/roast-local-inference.php) for Agent 4 judge | Ollama / llama.cpp with **mmproj** |
| Agents 1–3 | Explicit **cloud only** in [`roast-config.php`](../api/lib/roast-config.php) | Moondream or Qwen2-VL-2B |
| PHP wall | 120 s max; no background daemons | Long-running inference service |
| Outbound | Origin pulls sidecar over HTTPS | Sidecar accepts POST from IONOS |

Do **not** upload VLM weights to `/htdocs/models/` on IONOS — shared hosting will OOM and kill the process.

---

## Role in the cascade

Tier 1 live-frame chain (implemented in [`roast-cloud-vision.php`](../api/lib/roast-cloud-vision.php) as `roast_pvp_vision_t1()`):

```
1. Image-hash cache (sha256, 5 min TTL)     → ~0 ms
2. ROAST_VISION_VPS_URL sidecar (this doc)  → 0.5–2 s, free if self-hosted
3. OpenRouter Qwen2.5-VL-3B                 → 1–3 s, paid
4. OpenRouter Llama 3.2 11B vision          → 2–4 s, backup
5. Degraded identity fallback               → instant
```

**Never Groq on live PvP frames.** Groq 11B vision is reserved for Tier 2 cron (solo roast + background refinement). Set `ROAST_VISION_SKIP_GROQ=1` on IONOS until Tier 1/2 split is fully deployed.

---

## Recommended models

| Model | Size | RAM (quantized) | Latency (640px JPEG) | Notes |
|-------|------|-----------------|----------------------|-------|
| **Ollama `moondream`** | ~1.7 GB | ~2 GB | 0.5–1.5 s | Best fit for CX22; good enough for make/model + `visible_subject` |
| **Qwen2-VL-2B-Instruct** | ~2–4 GB Q4 | ~3–4 GB | 1–2 s | Stronger JSON adherence; use if Moondream schema violations are frequent |

Both are sufficient for **Agent 1 identify only** (Tier 1). Agents 2–3 (inspect) stay on Groq/OpenRouter in Tier 2 cron.

---

## Host options

### Option A — Home PC (free inference)

1. Install [Ollama](https://ollama.com) and pull a model: `ollama pull moondream` or `ollama pull qwen2-vl:2b`.
2. Run the sidecar wrapper (§4) on `127.0.0.1:8787`.
3. Expose to IONOS via **Cloudflare Tunnel** or **Tailscale Funnel** — do not port-forward raw HTTP without TLS.
4. Set `ROAST_VISION_VPS_URL=https://vision.yourdomain.com/v1/identify` on IONOS.

**Pros:** Zero VPS cost. **Cons:** PC must stay online during events; home upload bandwidth.

### Option B — Hetzner CX22 (~€4/mo)

| Spec | CX22 |
|------|------|
| vCPU | 2 |
| RAM | 4 GB |
| Disk | 40 GB |

1. Ubuntu 24.04, enable UFW: allow `22`, `443` only.
2. Install Docker + docker-compose (§6).
3. Point DNS `vision.onlybikes.shop` (or subdomain) to the VPS; terminate TLS with Caddy or nginx + Let's Encrypt.
4. Generate secret: `openssl rand -hex 32` → set on VPS **and** IONOS `api/.env`.

**Pros:** Always on, predictable latency. **Cons:** Small monthly cost.

---

## HTTP API contract (`ROAST_VISION_VPS_URL`)

IONOS POSTs to the full URL in `ROAST_VISION_VPS_URL` (include path, e.g. `https://vision.example.com/v1/identify`).

### Authentication

Every request **must** include:

```http
Authorization: Bearer <ROAST_VISION_VPS_SECRET>
```

Reject with `401` if missing or wrong. Use a 32+ byte random secret (`openssl rand -hex 32`). Never commit the secret; set only in `api/.env` on IONOS and in the sidecar environment.

Optional hardening:

- Allowlist IONOS egress IP(s) at the reverse proxy.
- Rate limit: 30 req/min per source IP (Tier 1 cadence ≈ 12 req/min per active duel).

### `POST /v1/identify`

**Request** (`Content-Type: application/json`):

```json
{
  "phase": "identify",
  "context": "live_frame",
  "image_base64": "data:image/jpeg;base64,/9j/4AAQ...",
  "prompt": "Identify e-moto / electric dirt bike content...",
  "max_tokens": 300,
  "temperature": 0.1,
  "timeout_ms": 3000
}
```

| Field | Required | Description |
|-------|----------|-------------|
| `phase` | yes | Always `"identify"` for Tier 1 |
| `context` | yes | `"live_frame"` for PvP; `"solo"` for optional sidecar use on uploads |
| `image_base64` | yes | Data-URI or raw base64 JPEG/WebP (≤ 1024 px long edge after IONOS normalize) |
| `prompt` | no | Live-frame Agent 1 prompt from [`agent1-identify.php`](../api/roast-limited/agents/agent1-identify.php); sidecar may use built-in default |
| `max_tokens` | no | Default `300` |
| `temperature` | no | Default `0.1` |
| `timeout_ms` | no | Client abort hint; default matches `ROAST_PVP_T1_TIMEOUT_SEC` (3000 ms) |

**Success response** (`200`, `Content-Type: application/json`):

```json
{
  "ok": true,
  "data": {
    "make": "Surron",
    "model": "Light Bee X",
    "confidence": 0.82,
    "is_complete_ebike": true,
    "visible_subject": "full_bike"
  },
  "ms": 1240,
  "model": "moondream"
}
```

`data` must validate against `roast_validate_identity()` in [`roast-cloud-vision.php`](../api/lib/roast-cloud-vision.php):

| Field | Type | Values |
|-------|------|--------|
| `make` | string | Brand or `"Unknown"` |
| `model` | string | Model or `"Unknown"` |
| `confidence` | number | 0.0–1.0 |
| `is_complete_ebike` | boolean | `true` only when frame + seat + motor/battery visible |
| `visible_subject` | string | `full_bike`, `partial_bike`, `parts_only`, `not_an_ebike`, `unclear` |

The model should return **only** this JSON object (no markdown fences). Sidecar may strip fences before responding.

**Error response** (`4xx`/`5xx` or `200` with `"ok": false`):

```json
{
  "ok": false,
  "error": {
    "code": "TIMEOUT",
    "message": "Inference exceeded 3000 ms"
  },
  "ms": 3010
}
```

| `error.code` | When | IONOS behavior |
|--------------|------|----------------|
| `TIMEOUT` | Inference > `timeout_ms` | Fall through to OpenRouter Qwen |
| `SCHEMA_VIOLATION` | JSON invalid / missing fields | Fall through |
| `UNAUTHORIZED` | Bad secret | Log + fall through (do not retry) |
| `MODEL_ERROR` | Ollama/GPU failure | Fall through |

IONOS treats non-2xx, `ok: false`, schema failure, or curl timeout as **sidecar miss** and continues the Tier 1 chain.

### `GET /health`

Optional but recommended for monitoring and deploy smoke.

```http
GET /health
Authorization: Bearer <ROAST_VISION_VPS_SECRET>
```

```json
{
  "ok": true,
  "model": "moondream",
  "loaded": true,
  "version": "1.0.0"
}
```

Use in uptime checks; IONOS does not call this on the hot path.

---

## IONOS configuration

Add to production `api/.env` (see [`api/.env.example`](../api/.env.example)):

```env
ROAST_VISION_VPS_URL=https://vision.example.com/v1/identify
ROAST_VISION_VPS_SECRET=<same-secret-as-sidecar>
ROAST_PVP_T1_TIMEOUT_SEC=3
ROAST_VISION_SKIP_GROQ=1
ROAST_OPENROUTER_API_KEY=<required-for-fallback>
```

Leave `ROAST_VISION_VPS_URL` **empty** to skip the sidecar (OpenRouter-only Tier 1).

### Smoke from IONOS shell

```bash
# Replace URL and secret; use a small test JPEG as base64
curl -sS -X POST "$ROAST_VISION_VPS_URL" \
  -H "Authorization: Bearer $ROAST_VISION_VPS_SECRET" \
  -H "Content-Type: application/json" \
  -d '{"phase":"identify","context":"live_frame","image_base64":"data:image/jpeg;base64,..."}' \
  --max-time 5
```

Expect `"ok": true` and a populated `data.make` within ~3 s.

### PHP integration (reference)

When wired, [`roast-cloud-vision.php`](../api/lib/roast-cloud-vision.php) calls the sidecar roughly as:

```php
// Pseudocode — actual implementation in roast_vision_vps_identify()
$payload = [
    'phase' => 'identify',
    'context' => 'live_frame',
    'image_base64' => $b64,
    'prompt' => $prompt,
    'max_tokens' => ROAST_VISION_MAX_TOKENS,
    'temperature' => ROAST_VISION_TEMPERATURE,
    'timeout_ms' => ROAST_PVP_T1_TIMEOUT_SEC * 1000,
];
// POST ROAST_VISION_VPS_URL with Authorization: Bearer ROAST_VISION_VPS_SECRET
// Map response → ['ok' => true, 'data' => ..., 'ms' => ..., 'backend' => 'vps_sidecar']
```

Successful sidecar hits are logged with `backend: vps_sidecar` in `VISION_OUTCOME` metrics.

---

## Sidecar wrapper (minimal Python + Ollama)

Ollama’s native `/api/chat` differs from this contract. Run a thin adapter that translates requests and enforces JSON schema.

Save as `sidecar/server.py` on the vision host:

```python
#!/usr/bin/env python3
"""OnlyBikes vision sidecar — Ollama backend. See docs/VISION-SIDECAR.md."""
import base64
import json
import os
import re
import time
from http.server import BaseHTTPRequestHandler, HTTPServer

SECRET = os.environ.get("ROAST_VISION_VPS_SECRET", "")
OLLAMA = os.environ.get("OLLAMA_HOST", "http://127.0.0.1:11434")
MODEL = os.environ.get("VISION_MODEL", "moondream")
PORT = int(os.environ.get("SIDECAR_PORT", "8787"))

LIVE_PROMPT = """Identify e-moto / electric dirt bike content in this live PvP duel camera frame.
Output strictly JSON only:
{"make":"string","model":"string","confidence":0.0,"is_complete_ebike":false,"visible_subject":"partial_bike"}
visible_subject: full_bike, partial_bike, parts_only, not_an_ebike, unclear"""


def auth_ok(h):
    if not SECRET:
        return False
    auth = h.headers.get("Authorization", "")
    return auth == f"Bearer {SECRET}"


def ollama_vision(b64: str, prompt: str, timeout_ms: int) -> dict:
    import urllib.request
    img = b64.split(",", 1)[-1] if b64.startswith("data:") else b64
    body = json.dumps({
        "model": MODEL,
        "prompt": prompt,
        "images": [img],
        "stream": False,
        "format": "json",
        "options": {"temperature": 0.1, "num_predict": 300},
    }).encode()
    req = urllib.request.Request(f"{OLLAMA}/api/generate", data=body, method="POST")
    t0 = time.time()
    with urllib.request.urlopen(req, timeout=timeout_ms / 1000) as resp:
        out = json.loads(resp.read())
    ms = int((time.time() - t0) * 1000)
    raw = out.get("response", "")
    m = re.search(r"\{[\s\S]*\}", raw)
    data = json.loads(m.group(0)) if m else {}
    return {"data": data, "ms": ms}


class Handler(BaseHTTPRequestHandler):
    def _json(self, code, obj):
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.end_headers()
        self.wfile.write(json.dumps(obj).encode())

    def do_GET(self):
        if self.path != "/health":
            return self._json(404, {"ok": False})
        if not auth_ok(self):
            return self._json(401, {"ok": False, "error": {"code": "UNAUTHORIZED"}})
        self._json(200, {"ok": True, "model": MODEL, "loaded": True, "version": "1.0.0"})

    def do_POST(self):
        if self.path != "/v1/identify":
            return self._json(404, {"ok": False})
        if not auth_ok(self):
            return self._json(401, {"ok": False, "error": {"code": "UNAUTHORIZED"}})
        n = int(self.headers.get("Content-Length", 0))
        req = json.loads(self.rfile.read(n))
        b64 = req.get("image_base64", "")
        prompt = req.get("prompt") or LIVE_PROMPT
        timeout_ms = int(req.get("timeout_ms", 3000))
        try:
            r = ollama_vision(b64, prompt, timeout_ms)
            return self._json(200, {"ok": True, "data": r["data"], "ms": r["ms"], "model": MODEL})
        except Exception as e:
            return self._json(200, {"ok": False, "error": {"code": "MODEL_ERROR", "message": str(e)}})

    def log_message(self, *args):
        pass


if __name__ == "__main__":
    HTTPServer(("0.0.0.0", PORT), Handler).serve_forever()
```

---

## Docker Compose (optional)

Place on the vision host as `docker-compose.yml`. TLS termination should sit in front (Caddy/nginx on the host or a `caddy` service).

```yaml
services:
  ollama:
    image: ollama/ollama:latest
    restart: unless-stopped
    volumes:
      - ollama_data:/root/.ollama
    # CX22: no GPU; CPU inference is fine for Moondream
    deploy:
      resources:
        limits:
          memory: 3G

  ollama-init:
    image: ollama/ollama:latest
    depends_on:
      - ollama
    restart: "no"
    entrypoint: ["/bin/sh", "-c"]
    command:
      - |
        sleep 5
        ollama pull moondream
    environment:
      OLLAMA_HOST: http://ollama:11434
    network_mode: "service:ollama"

  sidecar:
    build:
      context: ./sidecar
      dockerfile: Dockerfile
    restart: unless-stopped
    depends_on:
      - ollama
    environment:
      ROAST_VISION_VPS_SECRET: ${ROAST_VISION_VPS_SECRET}
      OLLAMA_HOST: http://ollama:11434
      VISION_MODEL: moondream
      SIDECAR_PORT: "8787"
    ports:
      - "127.0.0.1:8787:8787"

volumes:
  ollama_data:
```

Minimal `sidecar/Dockerfile`:

```dockerfile
FROM python:3.12-slim
WORKDIR /app
COPY server.py .
EXPOSE 8787
CMD ["python", "server.py"]
```

Reverse-proxy example (host Caddy):

```
vision.example.com {
    reverse_proxy 127.0.0.1:8787
}
```

---

## Operations

| Task | Command / action |
|------|------------------|
| Start | `docker compose up -d` |
| Pull model | `docker compose exec ollama ollama pull moondream` |
| Logs | `docker compose logs -f sidecar` |
| Rotate secret | Update VPS env + IONOS `api/.env`; restart sidecar |
| Sidecar down | Tier 1 auto-falls back to OpenRouter Qwen — no duel hard-fail |
| Cost control | Hash cache on IONOS dedupes identical frames; static camera ≈ zero repeat inference |

### Monitoring

- Alert if `/health` fails for > 5 min during `ROAST_EVENT_ENABLED=1`.
- Track `VISION_OUTCOME` log lines with `backend: vps_sidecar` vs `openrouter_vision_qwen` fallback rate (target: sidecar ≥ 70% when configured).

---

## Security checklist

- [ ] TLS on public URL (Cloudflare proxy or Let's Encrypt)
- [ ] `ROAST_VISION_VPS_SECRET` ≥ 32 bytes, unique per environment
- [ ] Sidecar bound to localhost; only reverse proxy exposed
- [ ] No VLM weights or sidecar code on IONOS `/htdocs/`
- [ ] Staging uses separate secret + URL from production

---

## Related env vars

| Variable | Where | Purpose |
|----------|-------|---------|
| `ROAST_VISION_VPS_URL` | IONOS `api/.env` | Full POST URL including `/v1/identify` |
| `ROAST_VISION_VPS_SECRET` | IONOS + sidecar | Bearer auth |
| `ROAST_PVP_T1_TIMEOUT_SEC` | IONOS | Tier 1 curl timeout (default `3`) |
| `ROAST_VISION_SKIP_GROQ` | IONOS | `1` = no Groq on live frames |
| `ROAST_OPENROUTER_API_KEY` | IONOS | Required fallback when sidecar misses |

*Last updated: Phase 2 build — sidecar contract + docker-compose scaffold.*
