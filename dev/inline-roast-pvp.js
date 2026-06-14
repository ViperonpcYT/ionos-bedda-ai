/**
 * Inline roast-pvp.js into roast-pvp.html — ONLY when html still uses external script.
 * roast-pvp.html with pvp-inline-v14+ is the source of truth; do not downgrade.
 */
const fs = require('fs');
const path = require('path');
const root = path.join(__dirname, '..');
const htmlPath = path.join(root, 'roast-pvp.html');
const jsPath = path.join(root, 'roast-pvp.js');

let html = fs.readFileSync(htmlPath, 'utf8');

if (/pvp-inline-v1[4-9]|pvp-inline-v\d{2,}/.test(html)) {
  console.log('SKIP: roast-pvp.html already has inline build v14+ — edit roast-pvp.html directly.');
  process.exit(0);
}

if (html.includes('<script src="roast-pvp.js')) {
  const js = fs.readFileSync(jsPath, 'utf8').replace(/<\/script>/gi, '<\\/script>');
  const block =
    '\n  <!-- pvp-inline-from-js — run sync script after editing roast-pvp.js -->\n' +
    '  <script>\n' +
    js +
    '\n  </script>\n';
  const patterns = [
    /\s*<script src="roast-pvp\.js[^"]*"><\/script>\s*/,
  ];
  let replaced = false;
  for (const re of patterns) {
    if (re.test(html)) {
      html = html.replace(re, block);
      replaced = true;
      break;
    }
  }
  if (!replaced) {
    throw new Error('Could not find external roast-pvp.js script tag');
  }
  fs.writeFileSync(htmlPath, html);
  console.log('Inlined OK — roast-pvp.html is now', Buffer.byteLength(html), 'bytes');
} else {
  console.log('No external roast-pvp.js tag found — nothing to inline.');
}
