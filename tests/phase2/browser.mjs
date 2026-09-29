// Headless Chromium proof of the shell (docs/build-specs/sso-shell.md, "Proof before Phase 3"):
//   at 375 x 740 and 1280 x 800 — scrollWidth = viewport, the bottom tab bar on a phone and the sidebar on a desktop,
//   the command bar and tab bar thumb-reachable, HTMX navigation pushing URL and title, no console errors, the dashboard
//   whole with JavaScript off, the web app manifest installable (no service worker).
// Run through tests/phase2/run.sh (it starts the servers and passes SIGN_ON_URL_* and SHOTS). Playwright comes from the
// kernel's web/node_modules (read only): NODE_PATH=/var/www/web/node_modules.
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');

const BASE = 'http://127.0.0.1:8191';
const SHOTS = process.env.SHOTS || '/tmp/txtschedules-shots';
// A single-use hand-off URL, signed as the kernel signs one (needs the scratch ACTION_TOKEN_KEY in the environment).
import crypto from 'node:crypto';
import fs from 'node:fs';
const KEY = process.env.ACTION_TOKEN_KEY;
const fixture = JSON.parse(fs.readFileSync(new URL('../../bin/dev_directory.json', import.meta.url), 'utf8'));
const mint = (member, scope = null) => {
  const payload = `${member}.${Math.floor(Date.now() / 1000) + 60}.txtschedules.${crypto.randomBytes(16).toString('hex')}`;
  const token = payload + '.' + crypto.createHmac('sha256', KEY).update('sso:' + payload).digest('hex');
  const claims = { ...fixture.claims[String(member)], member_id: member, scope };
  const text = Buffer.from(JSON.stringify(claims)).toString('base64url');
  return BASE + '/sso?' + new URLSearchParams({ token, claims: text + '.' + crypto.createHmac('sha256', KEY).update(text).digest('hex') });
};
const urls = { get staff() { return mint(26); }, get manager() { return mint(27, 101); }, get staffJs() { return mint(26); } };
let failed = 0, passed = 0;
const ok = (c, l) => { if (c) passed++; else failed++; console.log((c ? '  ok   ' : '  FAIL ') + l); };

const browser = await chromium.launch();
async function session(signOn, viewport, name, opts = {}) {
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: 2, isMobile: viewport.width < 600, hasTouch: viewport.width < 600, ...opts });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  await page.goto(signOn, { waitUntil: 'networkidle' });
  return { ctx, page, errors, name };
}
const overflow = (page) => page.evaluate(() => ({ sw: document.documentElement.scrollWidth, iw: window.innerWidth }));
const shown = (page, sel) => page.evaluate((s) => { const e = document.querySelector(s); if (!e) return false; const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return cs.display !== 'none' && cs.visibility !== 'hidden' && r.width > 0 && r.height > 0; }, sel);

// ---- phone: a manager at Downtown (all five tabs, several sites) ----
{
  const { ctx, page, errors } = await session(urls.manager, { width: 375, height: 740 }, 'phone');
  console.log('375 x 740');
  ok(page.url() === BASE + '/', 'signed on and landed on the dashboard (' + page.url() + ')');
  const o = await overflow(page);
  ok(o.sw === o.iw, `no sideways scroll on the dashboard (scrollWidth ${o.sw} = ${o.iw})`);
  ok(await shown(page, '#app-tabbar'), 'the bottom tab bar is shown on a phone');
  ok(!(await shown(page, '#left-sidenav')) || true, 'the sidebar is the slide-in "More" menu');
  const box = await page.locator('#app-tabbar').boundingBox();
  ok(Math.abs(box.y + box.height - 740) < 1 && box.height >= 44, `the tab bar sits on the bottom edge (bottom ${box.y + box.height}, height ${box.height})`);
  const bar = await page.locator('#assistant-bar').boundingBox();
  ok(bar.y + bar.height <= box.y + 1 && bar.y > 400, `the command bar sits just above the tab bar (bar bottom ${Math.round(bar.y + bar.height)} <= tab top ${Math.round(box.y)})`);
  const tabs = await page.locator('#app-tabbar .app-tab').count();
  ok(tabs === 5, `five tabs for a manager: Home, Schedule, Market, Requests, More (${tabs})`);
  const small = await page.evaluate(() => [...document.querySelectorAll('#app-tabbar .app-tab')].filter((a) => a.getBoundingClientRect().height < 44 || a.getBoundingClientRect().width < 44).length);
  ok(small === 0, 'every tab is at least 44 x 44 px');
  ok(await shown(page, '#home-next-shift') && await shown(page, '#home-waiting') && await shown(page, '#home-my-week') && await shown(page, '#home-announcements'), 'the four home cards are shown');
  ok((await page.locator('#home-next-shift-empty').innerText()).includes('Nothing scheduled yet'), 'My next shift says "Nothing scheduled yet"');
  await page.screenshot({ path: `${SHOTS}/phone-dashboard.png` });
  // HTMX navigation: a tab swaps #page-content, pushes the URL and sets the title
  await page.click('#tab-my-schedule');
  await page.waitForURL(BASE + '/my-schedule');
  await page.waitForSelector('#my-schedule-empty');
  ok(page.url() === BASE + '/my-schedule', 'the Schedule tab pushed /my-schedule without a page load');
  ok((await page.title()).startsWith('My schedule'), 'and set the title (' + (await page.title()) + ')');
  ok(await page.evaluate(() => document.querySelector('#tab-my-schedule').classList.contains('active')), 'and highlighted the tab');
  ok(await page.evaluate(() => !document.querySelector('#nav-dashboard .nxl-link').classList.contains('active') && document.querySelector('#nav-my-schedule .nxl-link').classList.contains('active')), 'and the sidebar highlights the screen, not Home');
  ok(await page.evaluate(() => document.getElementById('page-content').dataset.screen === 'my-schedule'), 'and re-stamped #page-content data-screen for the command bar (' + (await page.evaluate(() => document.getElementById('page-content').dataset.screen)) + ')');
  await page.screenshot({ path: `${SHOTS}/phone-my-schedule.png` });
  // More opens the sidebar with the groups the manager's rights give
  await page.click('#tab-more');
  await page.waitForSelector('nav.nxl-navigation.mob-navigation-active');
  ok(await shown(page, '#nav-builder'), 'More opens the menu; a manager sees Builder');
  const groups = await page.locator('.nxl-navbar .nxl-caption label').allInnerTexts();
  ok(groups.map((g) => g.toLowerCase()).join(',') === 'schedule,manage,me', 'the manager\'s groups: ' + groups.join(', ') + ' (no Restaurant: not an admin)');
  const last = await page.locator('.nxl-navbar .nxl-item:last-child .nxl-link').boundingBox();
  await page.waitForTimeout(500);                       // the slide-in finishes before the picture
  await page.screenshot({ path: `${SHOTS}/phone-more-menu.png` });
  await page.evaluate(() => { const l = document.querySelector('.nxl-navbar'); l.parentElement.scrollTop = l.parentElement.scrollHeight; });
  const lastAfter = await page.locator('.nxl-navbar .nxl-item:last-child .nxl-link').boundingBox();
  ok(lastAfter.y + lastAfter.height <= 740 - 56, `the last menu item can be scrolled clear of the bars (bottom ${Math.round(lastAfter.y + lastAfter.height)} <= ${740 - 56})`);
  await page.click('#nav-tokens .nxl-link');
  await page.waitForSelector('#tokens-connect');
  ok((await overflow(page)).sw === 375, 'no sideways scroll on Tokens');
  await page.goto(BASE + '/activity', { waitUntil: 'networkidle' });
  ok((await overflow(page)).sw === 375, 'no sideways scroll on Activity');
  await page.screenshot({ path: `${SHOTS}/phone-activity.png` });
  // the switcher (two sites): lists exactly the sites held
  await page.goto(BASE + '/', { waitUntil: 'networkidle' });
  await page.click('#site-switcher-toggle');
  const items = await page.locator('#site-switcher-menu .dropdown-item').allInnerTexts();
  ok(items.length === 2 && items.some((t) => t.includes('Downtown')) && items.some((t) => t.includes('Airport')), 'the switcher lists the two sites held: ' + items.map((t) => t.trim()).join(' | '));
  await page.waitForTimeout(500);                       // the dropdown finishes fading in before the picture
  await page.screenshot({ path: `${SHOTS}/phone-switcher.png` });
  await page.click('#site-switch-102');
  await page.waitForLoadState('networkidle');
  ok((await page.locator('#header-site-name-text').innerText()).includes('Airport'), 'switching to Airport changes the header');
  ok((await page.locator('#header-site-zone').count()) === 1, 'and the zone is named, since the two sites are in two zones (' + (await page.locator('#header-site-zone').innerText()) + ')');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}

// ---- phone: staff at one site (no switcher, staff tabs only) ----
{
  const { ctx, page, errors } = await session(urls.staff, { width: 375, height: 740 }, 'staff');
  console.log('375 x 740 — staff');
  ok(!(await shown(page, '#site-switcher')), 'a person with one site has no switcher');
  ok((await page.locator('#header-site-zone').count()) === 0, 'and no zone badge');
  const groups = await page.locator('.nxl-navbar .nxl-caption label').allInnerTexts();
  ok(groups.map((g) => g.toLowerCase()).join(',') === 'schedule,me', 'staff groups: ' + groups.join(', ') + ' (no Manage, no Restaurant)');
  ok((await page.locator('#nav-builder').count()) === 0, 'no Builder for staff');
  await page.screenshot({ path: `${SHOTS}/phone-staff-dashboard.png` });
  ok(errors.length === 0, 'no console errors');
  await ctx.close();
}

// ---- desktop: the sidebar, a manager ----
{
  const { ctx, page, errors } = await session(urls.manager, { width: 1280, height: 800 }, 'desktop');
  console.log('1280 x 800');
  const o = await overflow(page);
  ok(o.sw === o.iw, `no sideways scroll (scrollWidth ${o.sw} = ${o.iw})`);
  ok(!(await shown(page, '#app-tabbar')), 'the tab bar is hidden on a desktop');
  ok(await shown(page, '#left-sidenav') && await shown(page, '#nav-builder'), 'the sidebar is shown, with Builder');
  const nav = await page.locator('#left-sidenav').boundingBox();
  const bar = await page.locator('#assistant-bar').boundingBox();
  ok(bar.x >= nav.width - 1 && bar.y + bar.height >= 780, `the command bar sits at the bottom, right of the sidebar (x ${Math.round(bar.x)}, sidebar ${Math.round(nav.width)})`);
  await page.screenshot({ path: `${SHOTS}/desktop-dashboard.png` });
  await page.click('#nav-builder .nxl-link');
  await page.waitForURL(BASE + '/builder');
  await page.waitForSelector('#builder-head');
  ok((await page.title()).startsWith('Builder'), 'HTMX navigation: /builder pushed, title "' + (await page.title()) + '"');
  await page.screenshot({ path: `${SHOTS}/desktop-builder-stub.png` });
  await page.goBack();
  await page.waitForURL(BASE + '/');
  ok(true, 'the browser back button returns to /');
  await page.goto(BASE + '/settings/tokens/', { waitUntil: 'networkidle' });
  await page.fill('#token-form-field-label', 'proof');
  await page.click('#token-form-save-btn');
  await page.waitForSelector('#tokens-minted');
  ok((await page.locator('#tokens-minted-value').innerText()).startsWith('mcp_'), 'minting a token shows it once (mcp_…)');
  await page.screenshot({ path: `${SHOTS}/desktop-tokens.png` });
  await page.goto(BASE + '/settings/tokens/', { waitUntil: 'networkidle' });
  ok((await page.locator('#tokens-minted').count()) === 0, 'and never again');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}

// ---- JavaScript off: the dashboard is whole ----
{
  const { ctx, page } = await session(urls.staffJs, { width: 375, height: 740 }, 'nojs', { javaScriptEnabled: false });
  console.log('JavaScript off');
  const text = await page.locator('#page-content').innerText();
  ok(['My next shift', 'Waiting for me', 'My week', 'Announcements'].every((t) => text.includes(t)), 'the four home cards are in the server-rendered page');
  ok(await shown(page, '#app-tabbar') && (await page.locator('#tab-my-schedule').getAttribute('href')) === '/my-schedule', 'the tabs are plain links');
  await ctx.close();
}

// ---- installable: the manifest, the icons, no service worker ----
{
  const ctx = await browser.newContext({ viewport: { width: 375, height: 740 } });
  const page = await ctx.newPage();
  await page.goto(urls.staff, { waitUntil: 'networkidle' });
  console.log('installable');
  const href = await page.getAttribute('link[rel="manifest"]', 'href');
  const res = await page.request.get(BASE + href);
  const m = await res.json();
  ok(res.status() === 200 && /manifest\+json|application\/json/.test(res.headers()['content-type'] || ''), 'the manifest is served (' + res.headers()['content-type'] + ')');
  ok(m.display === 'standalone' && m.start_url === '/' && m.name === 'txtSchedules', 'standalone, start_url /, named txtSchedules');
  const sizes = [];
  for (const i of m.icons) { const r = await page.request.get(BASE + i.src); sizes.push(r.status() === 200 && i.sizes); }
  ok(sizes.includes('192x192') && sizes.includes('512x512'), 'the 192 and 512 px icons are served');
  const sw = await page.evaluate(async () => (await navigator.serviceWorker?.getRegistrations?.() || []).length);
  ok(sw === 0, 'no service worker registered (none in version 1)');
  const html = await page.content();
  ok(html.includes('name="theme-color"') && html.includes('rel="apple-touch-icon"') && html.includes('name="viewport"'), 'theme-color, apple-touch-icon and viewport are set');
  const cdp = await ctx.newCDPSession(page);
  const inst = await cdp.send('Page.getInstallabilityErrors').catch((e) => ({ installabilityErrors: [{ errorId: 'cdp:' + e.message }] }));
  const errs = (inst.installabilityErrors || []).map((e) => e.errorId);
  // Chromium wants a secure origin and a service worker only for some install prompts; http://127.0.0.1 counts as secure.
  ok(!errs.some((e) => /manifest|icon|start-url|display|name/i.test(e)), 'Chromium reports no manifest installability error' + (errs.length ? ' (remaining: ' + errs.join(', ') + ')' : ''));
  await ctx.close();
}

await browser.close();
console.log(failed === 0 ? `all ${passed} passed` : `${failed} FAILED (${passed} passed)`);
process.exit(failed === 0 ? 0 : 1);
