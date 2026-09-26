// 由 app/assets/icon.svg 產生各尺寸 PNG（透明背景）。用法：node tools/render-icons.cjs
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || '/opt/jt-ipam/frontend/node_modules/.pnpm/playwright@1.60.0/node_modules/playwright');
const fs = require('fs'); const path = require('path');
const root = path.join(__dirname, '..');
const svg = fs.readFileSync(path.join(root, 'app/assets/icon.svg'), 'utf8');
const out = { 'favicon-32.png': 32, 'favicon-192.png': 192, 'apple-touch-icon.png': 180, 'logo-64.png': 64, 'logo.png': 512, 'icon-512.png': 512 };
(async () => {
  const b = await chromium.launch(); const p = await b.newPage();
  for (const [name, size] of Object.entries(out)) {
    await p.setViewportSize({ width: size, height: size });
    await p.setContent(`<html><body style="margin:0;background:transparent">${svg.replace('<svg ', `<svg width="${size}" height="${size}" `)}</body></html>`);
    await p.locator('svg').screenshot({ path: path.join(root, 'app/assets', name), omitBackground: true });
    console.log(name, size);
  }
  await b.close();
})();
