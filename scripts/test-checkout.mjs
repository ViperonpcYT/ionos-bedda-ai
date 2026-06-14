#!/usr/bin/env node
/**
 * OnlyBikes checkout test suite - exercises Stripe test cards against local or live API.
 *
 * Usage:
 *   npm run test              # default BASE_URL=http://localhost:8080
 *   npm run test:live         # against https://onlybikes.example
 *
 * Env:
 *   BASE_URL, STRIPE_SECRET_KEY, TEST_EMAIL
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import Stripe from 'stripe';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(__dirname, '..');

const BASE_URL = process.env.BASE_URL || 'http://localhost:8080';
const TEST_EMAIL = process.env.TEST_EMAIL || 'test@example.com';
const TEST_NAME = 'Cursor Test User';
const TEST_PHONE = '9055551234';

const CARDS = {
  success: { token: 'tok_visa', expect: 'succeeded' },
  declined: { token: 'tok_chargeDeclined', expect: 'card_declined' },
  insufficient: { token: 'tok_chargeDeclinedInsufficientFunds', expect: 'insufficient_funds' },
};

const SAMPLE_ITEM = {
  product: 'E-Moto Brake Pads (Universal Fit)',
  price: 24.99,
  quantity: 1,
};

function loadEnvFile() {
  const envPath = path.join(ROOT, 'api', '.env');
  if (!fs.existsSync(envPath)) return {};
  const out = {};
  for (const line of fs.readFileSync(envPath, 'utf8').split('\n')) {
    const t = line.trim();
    if (!t || t.startsWith('#') || !t.includes('=')) continue;
    const i = t.indexOf('=');
    out[t.slice(0, i).trim()] = t.slice(i + 1).trim();
  }
  return out;
}

const envFile = loadEnvFile();
const STRIPE_SECRET =
  process.env.STRIPE_SECRET_KEY ||
  envFile.STRIPE_SECRET_KEY ||
  '';

if (!STRIPE_SECRET) {
  console.error('Missing STRIPE_SECRET_KEY (set env or api/.env)');
  process.exit(1);
}

const stripe = new Stripe(STRIPE_SECRET);

const results = [];

function log(icon, msg) {
  console.log(`${icon} ${msg}`);
}

function orderNumber(tag) {
  return `OB-TEST-${tag}-${Date.now().toString(36).toUpperCase()}`;
}

function baseOrder(overrides = {}) {
  const ts = Math.floor(Date.now() / 1000) - 10;
  return {
    orderNumber: orderNumber('ORD'),
    items: [SAMPLE_ITEM],
    subtotal: SAMPLE_ITEM.price,
    customerName: TEST_NAME,
    customerEmail: TEST_EMAIL,
    phoneNumber: TEST_PHONE,
    streetAddress: '123 Test Street',
    address2: '',
    city: 'Toronto',
    province: 'ON',
    postalCode: 'L5B1B8',
    fulfillment_method: 'shipping',
    shipping_option: { total: 12.5, carrier: 'Canada Post', id: 'test-rate' },
    form_timestamp: ts,
    newsletter: false,
    ...overrides,
  };
}

class CookieJar {
  constructor() {
    this.cookies = new Map();
  }
  ingest(setCookie) {
    if (!setCookie) return;
    const parts = setCookie.split(';')[0];
    const eq = parts.indexOf('=');
    if (eq > 0) this.cookies.set(parts.slice(0, eq), parts.slice(eq + 1));
  }
  header() {
    if (!this.cookies.size) return '';
    return [...this.cookies.entries()].map(([k, v]) => `${k}=${v}`).join('; ');
  }
}

async function apiFetch(jar, urlPath, body, method = 'POST') {
  const headers = {
    'Content-Type': 'application/json',
    Origin: BASE_URL,
    Referer: `${BASE_URL}/products.html`,
    'User-Agent': 'OnlyBikesCheckoutTest/1.0 (Cursor)',
  };
  const cookie = jar.header();
  if (cookie) headers.Cookie = cookie;

  const res = await fetch(`${BASE_URL}${urlPath}`, {
    method,
    headers,
    body: body ? JSON.stringify(body) : undefined,
  });

  const setCookie = res.headers.getSetCookie?.() || [];
  for (const c of setCookie) jar.ingest(c);
  const legacy = res.headers.get('set-cookie');
  if (legacy) jar.ingest(legacy);

  let json;
  const text = await res.text();
  try {
    json = JSON.parse(text);
  } catch {
    json = { raw: text };
  }
  return { status: res.status, json };
}

async function createPaymentMethod(cardKey) {
  const card = CARDS[cardKey] || CARDS.success;
  return stripe.paymentMethods.create({
    type: 'card',
    card: { token: card.token },
  });
}

async function confirmPaymentIntent(clientSecret, cardKey) {
  const piId = clientSecret.split('_secret')[0];
  const pm = await createPaymentMethod(cardKey);
  try {
    const pi = await stripe.paymentIntents.confirm(piId, {
      payment_method: pm.id,
      return_url: `${BASE_URL}/checkout-success.html`,
    });
    return { ok: true, status: pi.status, id: pi.id, error: null };
  } catch (err) {
    return {
      ok: false,
      status: err.payment_intent?.status || 'failed',
      id: err.payment_intent?.id || piId,
      error: err.code || err.message,
    };
  }
}

async function confirmSubscriptionSecret(clientSecret, cardKey) {
  if (clientSecret.startsWith('seti_')) {
    const siId = clientSecret.split('_secret')[0];
    const pm = await createPaymentMethod(cardKey);
    try {
      const si = await stripe.setupIntents.confirm(siId, { payment_method: pm.id });
      return { ok: true, status: si.status, error: null };
    } catch (err) {
      return { ok: false, status: 'failed', error: err.code || err.message };
    }
  }
  return confirmPaymentIntent(clientSecret, cardKey);
}

async function runScenario(name, opts) {
  const jar = new CookieJar();
  const started = Date.now();
  const detail = { name, pass: false, ms: 0, notes: [] };

  try {
    if (opts.loginFirst) {
      let login = await apiFetch(jar, '/api/customer-auth.php', {
        action: 'login',
        email: TEST_EMAIL,
        password: opts.password || 'TestPass123!',
      });
      if (!login.json.success) {
        const reg = await apiFetch(jar, '/api/customer-auth.php', {
          action: 'register',
          email: TEST_EMAIL,
          password: opts.password || 'TestPass123!',
          firstName: 'Cursor',
          lastName: 'Test',
        });
        if (reg.json.success) {
          login = await apiFetch(jar, '/api/customer-auth.php', {
            action: 'login',
            email: TEST_EMAIL,
            password: opts.password || 'TestPass123!',
          });
        }
        detail.notes.push(login.json.success ? 'logged in' : `auth: ${login.json.message || reg.json.message}`);
      } else {
        detail.notes.push('logged in');
      }
    }

    const order = baseOrder(opts.order || {});
    if (opts.use_points) order.use_points = true;
    if (opts.customer_id) order.customer_id = opts.customer_id;

    const endpoint = opts.subscription
      ? '/api/create-subscription.php'
      : '/api/create-payment-intent.php';

    const intentPayload = {
      ...order,
      isSubscription: !!opts.subscription,
      interval: opts.subscription ? (opts.interval || '2') : undefined,
    };

    const intentRes = await apiFetch(jar, endpoint, intentPayload);
    if (!intentRes.json.success && !intentRes.json.clientSecret) {
      throw new Error(`Intent failed (${intentRes.status}): ${JSON.stringify(intentRes.json)}`);
    }
    detail.notes.push(`intent ${intentRes.status}`);

    const cardKey = opts.card || 'success';
    const card = CARDS[cardKey] || CARDS.success;
    const confirm = await confirmSubscriptionSecret(
      intentRes.json.clientSecret,
      cardKey
    );

    const expectPayOk = opts.expectPaymentSuccess !== false;
    if (expectPayOk && !confirm.ok) {
      throw new Error(`Expected payment success, got: ${confirm.error}`);
    }
    if (!expectPayOk && confirm.ok) {
      throw new Error('Expected payment failure but it succeeded');
    }
    detail.notes.push(`stripe: ${confirm.ok ? confirm.status : confirm.error}`);

    if (opts.skipSubmit) {
      detail.pass = true;
      return detail;
    }

    if (!confirm.ok) {
      detail.pass = true;
      detail.notes.push('skipped submit (payment failed as expected)');
      return detail;
    }

    const submitBody = {
      ...order,
      payment_intent_id: confirm.id,
      payment_status: confirm.status,
      isSubscription: !!opts.subscription,
      subscriptionInterval: opts.subscription ? (opts.interval || '2') : undefined,
      subscriptionId: intentRes.json.subscriptionId,
      use_points: !!opts.use_points,
      customer_id: opts.customer_id,
    };

    const submit = await apiFetch(jar, '/api/submit-order.php', submitBody);
    if (!submit.json.success) {
      throw new Error(`Submit failed (${submit.status}): ${submit.json.message || JSON.stringify(submit.json)}`);
    }
    detail.notes.push(`order ${submit.json.data?.orderNumber || 'saved'}`);
    detail.notes.push(`email → ${TEST_EMAIL}`);
    detail.pass = true;
  } catch (err) {
    detail.notes.push(String(err.message || err));
  } finally {
    detail.ms = Date.now() - started;
  }
  return detail;
}

async function healthCheck() {
  const checks = [
    ['homepage', `${BASE_URL}/`],
    ['products', `${BASE_URL}/products.html`],
    ['rate-limit', `${BASE_URL}/api/check-rate-limit.php`],
    ['stock', `${BASE_URL}/api/get-stock.php?sku=BEDDA-UNI-EXFOL`],
  ];

  for (const [name, url] of checks) {
    try {
      if (name === 'rate-limit') {
        const r = await fetch(url, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', Origin: BASE_URL },
          body: JSON.stringify({ email: TEST_EMAIL, phone: TEST_PHONE }),
        });
        log(r.ok ? '✓' : '✗', `${name}: HTTP ${r.status}`);
      } else {
        const r = await fetch(url, { headers: { 'User-Agent': 'OnlyBikesCheckoutTest/1.0' } });
        log(r.ok ? '✓' : '✗', `${name}: HTTP ${r.status}`);
      }
    } catch (e) {
      log('✗', `${name}: ${e.message}`);
    }
  }
}

const SCENARIOS = [
  { name: '1. One-time · shipping · success card · no points', card: 'success' },
  { name: '2. One-time · shipping · declined card', card: 'declined', expectPaymentSuccess: false, skipSubmit: true },
  { name: '3. One-time · shipping · insufficient funds', card: 'insufficient', expectPaymentSuccess: false, skipSubmit: true },
  {
    name: '4. One-time · local pickup · success card',
    card: 'success',
    order: {
      fulfillment_method: 'pickup',
      pickup_location: '',
      pickup_date: '2026-06-15',
      shipping_option: null,
    },
  },
  { name: '5. Subscription · 2-month · success card', card: 'success', subscription: true, interval: '2' },
  { name: '6. Subscription · declined card', card: 'declined', subscription: true, expectPaymentSuccess: false, skipSubmit: true },
  { name: '7. One-time · success · use_points flag (logged in)', card: 'success', use_points: true, loginFirst: true },
  { name: '8. One-time · success · no points baseline', card: 'success', use_points: false },
];

console.log('\n══════════════════════════════════════════');
console.log(' OnlyBikes Checkout Test Suite');
console.log(` Target: ${BASE_URL}`);
console.log(` Email:  ${TEST_EMAIL}`);
console.log('══════════════════════════════════════════\n');

console.log('── Health checks ──');
await healthCheck();
console.log('\n── Payment scenarios ──');

for (const scenario of SCENARIOS) {
  process.stdout.write(`\n▶ ${scenario.name} ... `);
  const result = await runScenario(scenario.name, scenario);
  results.push(result);
  if (result.pass) {
    console.log(`PASS (${result.ms}ms)`);
    result.notes.forEach((n) => console.log(`    · ${n}`));
  } else {
    console.log(`FAIL (${result.ms}ms)`);
    result.notes.forEach((n) => console.log(`    · ${n}`));
  }
}

const passed = results.filter((r) => r.pass).length;
const failed = results.length - passed;

console.log('\n══════════════════════════════════════════');
console.log(` Results: ${passed}/${results.length} passed, ${failed} failed`);
console.log('══════════════════════════════════════════');
console.log('\nCheck viperonpcyt@gmail.com for order confirmation emails.');
console.log('Stripe test dashboard: https://dashboard.stripe.com/test/payments\n');

process.exit(failed > 0 ? 1 : 0);
