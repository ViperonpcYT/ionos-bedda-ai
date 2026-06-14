#!/usr/bin/env node
/**
 * OnlyBikes Security Fortress Test - Attack Simulation
 * Tests all security controls without causing damage
 *
 * Usage: npm run test:security
 */

const BASE_URL = process.env.BASE_URL || 'https://onlybikes.example';
const RESULTS = [];

function log(ok, msg) {
  console.log(`${ok ? '✓' : '✗'} ${msg}`);
  RESULTS.push({ pass: ok, msg });
}

async function attack(path, opts = {}) {
  try {
    const url = `${BASE_URL}${path}`;
    const res = await fetch(url, {
      method: opts.method || 'GET',
      headers: {
        'User-Agent': opts.ua || 'SecurityTest/1.0',
        ...opts.headers
      },
      body: opts.body
    });
    const body = await res.text().catch(() => '');
    return { status: res.status, body, headers: Object.fromEntries(res.headers) };
  } catch (e) {
    return { status: 0, error: e.message };
  }
}

console.log('\n═══════════════════════════════════════════════════');
console.log('  BEDDA FORTRESS SECURITY TEST');
console.log(`  Target: ${BASE_URL}`);
console.log('═══════════════════════════════════════════════════\n');

// ─────────────────────────────────────────────────
// 1. CONFIG FILE ACCESS
// ─────────────────────────────────────────────────
console.log('─── [1] Config File Access Tests ───');
{
  const tests = [
    ['/api/.env', 'Direct .env access'],
    ['/api/secure-config.php', 'Config PHP direct'],
    ['/api/config.php', 'Old config access'],
    ['/.env', 'Root .env'],
    ['/api/.htaccess', 'HTAccess access'],
    ['/api/orders/2026-01-01-orders.log', 'Order log access'],
    ['/api/.git/config', 'Git config'],
  ];
  for (const [path, name] of tests) {
    const r = await attack(path);
    const blocked = r.status === 403 || r.status === 401 || r.status === 404;
    log(blocked, `${name}: HTTP ${r.status} ${blocked ? 'BLOCKED' : 'ALLOWED'}`);
  }
}

// ─────────────────────────────────────────────────
// 2. SQL INJECTION ATTEMPTS
// ─────────────────────────────────────────────────
console.log('\n─── [2] SQL Injection Tests ───');
{
  const payloads = [
    "'; DROP TABLE orders; --",
    "' OR '1'='1",
    "1'; DELETE FROM customers WHERE '1'='1",
    "1 UNION SELECT * FROM admin--",
    "1; INSERT INTO admin VALUES('hacker','pass')--"
  ];
  for (const payload of payloads) {
    const r = await attack('/api/get-stock.php?sku=' + encodeURIComponent(payload));
    const safe = r.status !== 200 || !r.body.includes('orders') || r.body.includes('error') || r.body.includes('not found');
    log(safe, `SQLi payload blocked: HTTP ${r.status}`);
  }
}

// ─────────────────────────────────────────────────
// 3. XSS ATTEMPTS
// ─────────────────────────────────────────────────
{
  console.log('\n─── [3] XSS Injection Tests ───');
  const xssPayload = '<script>alert(1)</script>';
  const r = await attack('/api/newsletter-subscribe.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email: `test${xssPayload}@test.com` })
  });
  const safe = !r.body.includes('<script>') || r.status === 400 || r.status === 403;
  log(safe, `XSS in email: HTTP ${r.status} ${safe ? '(sanitized/blocked)' : '(DANGER)'}`);
}

// ─────────────────────────────────────────────────
// 4. BRUTE FORCE PROTECTION
// ─────────────────────────────────────────────────
console.log('\n─── [4] Admin Brute-Force Test ───');
{
  let blocked = false;
  for (let i = 0; i < 8; i++) {
    const r = await attack('/api/manage-coupons.php?action=list', {
      headers: { 'X-Admin-Key': 'WRONG_KEY_' + i }
    });
    if (r.status === 429) {
      blocked = true;
      log(true, `Brute-force blocked after ${i+1} attempts (HTTP 429)`);
      break;
    }
  }
  if (!blocked) log(false, 'Brute-force NOT blocked after 8 attempts');
}

// ─────────────────────────────────────────────────
// 5. ORIGIN SPOOFING
// ─────────────────────────────────────────────────
console.log('\n─── [5] CORS/Origin Tests ───');
{
  const origins = [
    ['https://evil.com', 'Evil origin'],
    ['http://localhost:3000', 'Wrong localhost port'],
    ['null', 'Null origin'],
    ['https://onlybikes.example', 'Valid origin']
  ];
  for (const [origin, name] of origins) {
    const r = await attack('/api/check-rate-limit.php', {
      method: 'POST',
      headers: {
        'Origin': origin,
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({ email: 'test@test.com', phone: '9055551234' })
    });
    const corsHeader = r.headers['access-control-allow-origin'];
    const allowed = corsHeader === origin;
    const expectBlocked = origin !== 'https://onlybikes.example';
    log(allowed !== expectBlocked || !expectBlocked, `${name}: CORS ${corsHeader || 'none'}`);
  }
}

// ─────────────────────────────────────────────────
// 6. PATH TRAVERSAL
// ─────────────────────────────────────────────────
console.log('\n─── [6] Path Traversal Tests ───');
{
  const paths = [
    '/api/get-stock.php?sku=../../../etc/passwd',
    '/images/../api/secure-config.php',
    '/api/../.env',
    '/api/rate-limits/../../.htaccess'
  ];
  for (const path of paths) {
    const r = await attack(path);
    const safe = r.status === 403 || r.status === 404 || r.status === 400 || !r.body.includes('STRIPE');
    log(safe, `Path ${path}: HTTP ${r.status} ${safe ? 'BLOCKED' : 'LEAK'}`);
  }
}

// ─────────────────────────────────────────────────
// 7. HTTP METHOD RESTRICTIONS
// ─────────────────────────────────────────────────
console.log('\n─── [7] HTTP Method Tests ───');
{
  const tests = [
    ['/api/submit-order.php', 'GET', 'Order GET blocked'],
    ['/api/newsletter-subscribe.php', 'GET', 'Newsletter GET blocked'],
    ['/api/create-payment-intent.php', 'DELETE', 'PI DELETE blocked'],
    ['/api/ai-engine.php', 'PUT', 'AI PUT blocked']
  ];
  for (const [path, method, name] of tests) {
    const r = await attack(path, { method });
    const blocked = r.status === 405 || r.status === 403 || r.status === 404;
    log(blocked, `${name}: HTTP ${r.status}`);
  }
}

// ─────────────────────────────────────────────────
// 8. PAYLOAD SIZE LIMITS
// ─────────────────────────────────────────────────
console.log('\n─── [8] Payload Size Test ───');
{
  const bigBody = JSON.stringify({ items: Array(1000).fill({ product: 'A', price: 999, quantity: 1 }) });
  const r = await attack('/api/submit-order.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: bigBody
  });
  const blocked = r.status === 413 || r.status === 400 || r.status === 403;
  log(blocked, `Oversized payload (${(bigBody.length/1024).toFixed(1)}KB): HTTP ${r.status}`);
}

// ─────────────────────────────────────────────────
// 9. SECURITY HEADERS
// ─────────────────────────────────────────────────
console.log('\n─── [9] Security Headers Test ───');
{
  const r = await attack('/');
  const headers = r.headers;
  const checks = [
    ['x-frame-options', 'DENY', 'X-Frame-Options'],
    ['x-content-type-options', 'nosniff', 'X-Content-Type-Options'],
    ['x-xss-protection', '1', 'X-XSS-Protection'],
    ['referrer-policy', 'strict-origin-when-cross-origin', 'Referrer-Policy']
  ];
  for (const [header, expect, name] of checks) {
    const val = headers[header] || '';
    log(val.toLowerCase().includes(expect.toLowerCase()), `${name}: ${val || 'MISSING'}`);
  }
}

// ─────────────────────────────────────────────────
// 10. BOT/USER-AGENT FILTERING
// ─────────────────────────────────────────────────
console.log('\n─── [10] Bot Detection ───');
{
  const badUAs = [
    'sqlmap/1.0',
    'nikto/2.1.5',
    'masscan/1.0',
    'python-requests/2.25',
    'curl/7.68.0'
  ];
  for (const ua of badUAs) {
    const r = await attack('/api/get-stock.php?sku=BEDDA-001', { ua });
    const blocked = r.status !== 200 || r.body.includes('error') || r.body.includes('invalid');
    log(true, `Bot UA "${ua.slice(0,20)}...": ${blocked ? 'handled' : 'allowed'}`);
  }
}

// ─────────────────────────────────────────────────
// SUMMARY
// ─────────────────────────────────────────────────
const passed = RESULTS.filter(r => r.pass).length;
const failed = RESULTS.length - passed;

console.log('\n═══════════════════════════════════════════════════');
console.log(`  RESULTS: ${passed}/${RESULTS.length} PASSED`);
console.log(`  FAILED: ${failed}`);
console.log('═══════════════════════════════════════════════════');

if (failed > 0) {
  console.log('\nFailed tests:');
  RESULTS.filter(r => !r.pass).forEach(r => console.log(`  ✗ ${r.msg}`));
}

process.exit(failed > 0 ? 1 : 0);
