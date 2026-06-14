# Bike Roast — Full Ionos Deployment Guide



Enterprise pipeline: **cloud vision (Agents 1–3)** + **local judge with cloud fallbacks (Agent 4)**.



## Model routing (exact spec)



### Agents 1–3 — Vision (cloud only, prevents OOM)



| Route | Model | Provider | Trigger |

|-------|-------|----------|---------|

| Primary | `llama-3.2-11b-vision-preview` | Groq | Always |

| Fallback 1 | `meta-llama/llama-3.2-11b-vision-instruct` | OpenRouter | Groq 429 / 5xx / timeout |

| Fallback 2 | `qwen/qwen-2.5-vl-3b-instruct` | OpenRouter | OpenRouter FB1 failure |



Params: `response_format: json_object`, `temperature: 0.1`, `max_tokens: 300`



### Agent 4 — Judge roast



| Route | Model | Provider | Trigger |

|-------|-------|----------|---------|

| Primary | `qwen2.5-1.5b-instruct-q4_k_m.gguf` | Local llama-cli | Always |

| Fallback 1 | `llama-3.1-8b-instant` | Groq | OOM / GLIBC / empty / >15s |

| Fallback 2 | `qwen/qwen-2.5-1.5b-instruct` | OpenRouter | Groq 429 on text |



Local flags: `--threads 1 --ctx-size 2048 --n-predict 150 --temp 0.7 --no-mmap`



---



## Step 0 — Download judge model (GitHub Actions)



1. **Actions** → **Build Roast Judge Model for Ionos** → **Run workflow**

2. Artifacts:

   - `roast-llama-cli-ionos` → `/htdocs/llama-b9285/llama-cli`

   - `roast-judge-model-ionos` → `/htdocs/models/qwen2.5-1.5b-instruct-q4_k_m.gguf`

3. `chmod 755 llama-cli`



No VLM download needed — vision runs on Groq/OpenRouter.



---



## Step 1 — Database



Run [`api/sql/07-roast-jobs.sql`](sql/07-roast-jobs.sql) on Analytics DB `dbs15747041`.



---



## Step 2 — Upload PHP + frontend



Upload `api/roast-limited/*`, `api/lib/roast-*.php`, `api/lib/roast-cloud-*.php`, `roast-limited.html`, `js/roast-limited.js`, etc.



---



## Step 3 — `api/.env`



```env

ROAST_EVENT_ENABLED=1

ROAST_EVENT_END=2026-07-01

ROAST_PIPELINE_MODE=cloud_first



# Required — vision primary + judge fallback

ROAST_GROQ_API_KEY=gsk_your_groq_key

ROAST_OPENROUTER_API_KEY=sk-or-your_openrouter_key



# Vision models (defaults match spec — override only if needed)

ROAST_VISION_MODEL=llama-3.2-11b-vision-preview

ROAST_VISION_MODEL_OR_LLAMA=meta-llama/llama-3.2-11b-vision-instruct

ROAST_VISION_MODEL_OR_QWEN=qwen/qwen-2.5-vl-3b-instruct

ROAST_VISION_MAX_TOKENS=300

ROAST_VISION_TEMPERATURE=0.1

ROAST_VISION_TIMEOUT_SEC=12



# Local judge

ROAST_JUDGE_GGUF=/homepages/27/d4299910459/htdocs/models/qwen2.5-1.5b-instruct-q4_k_m.gguf

ROAST_JUDGE_CTX=2048

ROAST_JUDGE_N_PREDICT=150

ROAST_JUDGE_TEMP=0.7

ROAST_JUDGE_LOCAL_TIMEOUT_SEC=15

ROAST_JUDGE_MODEL_GROQ=llama-3.1-8b-instant

ROAST_JUDGE_MODEL_OR=qwen/qwen-2.5-1.5b-instruct

ROAST_JUDGE_GROQ_TEMP=0.8

ROAST_JUDGE_GROQ_MAX_TOKENS=200



ROAST_RATE_LIMIT_PER_IP_PER_DAY=1

ROAST_DAILY_MAX_JOBS=15

```



Get keys:

- Groq: https://console.groq.com

- OpenRouter: https://openrouter.ai/keys



---



## Step 4 — Health check



```

/api/roast-limited/test-roast-health.php?key=CRON_SECRET

```



Requires: `groq_api_key_set`, `openrouter_api_key_set`, `judge_gguf_ok`, `text_cli`, `analytics_db_ok`.



Ping: `/api/roast-limited/orchestrator.php?ping=1`



---



## Step 5 — Go live



`https://onlybikes.shop/roast-limited.html`



---



## Fallback behaviour



- **429 on Groq**: immediate route to next fallback (no exponential backoff)

- **5xx / timeout**: cascade to next provider

- **JSON schema fail**: one stricter retry on same provider, then SCHEMA_VIOLATION → partial envelope

- **Local judge kill**: Groq 8B instant, then OpenRouter 1.5B



Logs: `api/logs/roast-failures.log`



---



## Cost



- Vision: Groq free tier + OpenRouter paid fallback when rate-limited

- Judge: $0 when local succeeds; pennies when cloud fallback fires

- No VLM RAM on Ionos — avoids OOM on Level 2

