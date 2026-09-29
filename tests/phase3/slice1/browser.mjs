// Headless Chromium proof of slice 1 (docs/build-specs/shifts-marketplace.md, "Proof — 375 px and 1280 px"): no sideways scroll on any screen of the
// slice, every button at least 44 px tall at 375, the week strip scrolling inside its card, the bottom tab bar reaching Home, Schedule, Market and Requests,
// the marketplace re-fetching only #marketplace-results, the flows through the real screens (offer, take, decide, withdraw), JavaScript off.
// Run through tests/phase3/slice1/run.sh (servers up, the fixture built). Playwright comes from the kernel's web/node_modules (read only).
import { createRequire } from 'node:module';
import crypto from 'node:crypto';
import { execFileSync } from 'node:child_process';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8191';
const SHOTS = process.env.SHOTS || '/tmp/txtschedules-shots-p3s1';
const KEY = process.env.ACTION_TOKEN_KEY;
const prep = JSON.parse(execFileSync('php', [new URL('./browser_prep.php', import.meta.url).pathname], { encoding: 'utf8', env: process.env }));
const I = prep.ids;
const mint = (member, scope = null) => {
  const payload = `${member}.${Math.floor(Date.now() / 1000) + 60}.txtschedules.${crypto.randomBytes(16).toString('hex')}`;
  const token = payload + '.' + crypto.createHmac('sha256', KEY).update('sso:' + payload).digest('hex');
  const text = Buffer.from(JSON.stringify({ ...prep.claims[String(member)], member_id: member, scope })).toString('base64url');
  return BASE + '/sso?' + new URLSearchParams({ token, claims: text + '.' + crypto.createHmac('sha256', KEY).update(text).digest('hex') });
};
let failed = 0, passed = 0;
const ok = (c, l) => { if (c) passed++; else failed++; console.log((c ? '  ok   ' : '  FAIL ') + l); };
const browser = await chromium.launch();
const PHONE = { width: 375, height: 740 }, DESK = { width: 1280, height: 800 };
async function session(member, scope, viewport, opts = {}) {
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: 2, isMobile: viewport.width < 600, hasTouch: viewport.width < 600, ...opts });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  await page.goto(mint(member, scope), { waitUntil: 'networkidle' });
  return { ctx, page, errors };
}
const widthOK = async (page, label, vw) => { const o = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, iw: window.innerWidth })); ok(o.sw === o.iw && o.iw === vw, `no sideways scroll on ${label} (scrollWidth ${o.sw} = ${o.iw})`); };
const touch = async (page, label) => {
  const small = await page.evaluate(() => [...document.querySelectorAll('#page-content a.btn, #page-content button.btn, #page-content summary.btn, #page-content .week-chip, #page-content select.form-select, #page-content input.form-control[type=text], #app-tabbar .app-tab, #page-content .list-group-item.open-row a')]
    .filter((e) => { const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return cs.display !== 'none' && cs.visibility !== 'hidden' && r.width > 0 && (r.height < 43.5); })
    .map((e) => (e.id || e.className) + ':' + Math.round(e.getBoundingClientRect().height)));
  ok(small.length === 0, `every button and control is at least 44 px tall on ${label}` + (small.length ? ' — too small: ' + small.join(', ') : ''));
};
const go = async (page, path) => { await page.goto(BASE + path, { waitUntil: 'networkidle' }); };
const shot = (page, name) => page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: false });

// ================= 375 x 740 =================
console.log('375 x 740 — Priya (staff)');
{
  const { ctx, page, errors } = await session(26, null, PHONE);
  await widthOK(page, 'Home', 375);
  const tabs = await page.locator('#app-tabbar .app-tab').count();
  ok(tabs === 5, 'the bottom tab bar has Home, Schedule, Market, Requests, More');
  for (const [id, url, screen] of [['my-schedule', '/my-schedule', 'my-schedule'], ['marketplace', '/marketplace', 'marketplace'], ['my-requests', '/requests', 'my-requests'], ['dashboard', '/', 'dashboard']]) {
    await page.click('#tab-' + id);
    await page.waitForFunction((s) => document.getElementById('page-content').dataset.screen === s, screen);
    ok(page.url() === BASE + url && (await page.evaluate(() => document.getElementById('page-content').dataset.screen)) === screen, `the ${id} tab reaches ${url} (HTMX, URL pushed)`);
  }
  await go(page, '/my-schedule?week=' + prep.ids.week);
  await widthOK(page, 'My schedule (week)', 375); await touch(page, 'My schedule');
  const strip = await page.evaluate(() => { const c = document.getElementById('my-schedule-chips'); const card = document.getElementById('my-schedule-strip'); return { sw: c.scrollWidth, cw: c.clientWidth, chips: c.querySelectorAll('.week-chip').length, card: card.getBoundingClientRect().width, doc: document.documentElement.scrollWidth, works: c.querySelectorAll('.week-chip.works').length }; });
  ok(strip.chips === 7 && strip.works >= 3, `the strip has seven day chips and a dot on ${strip.works} days worked`);
  ok(strip.sw >= strip.cw && strip.doc === 375 && strip.card <= 375, `the strip scrolls inside its card, never the page (chips ${strip.sw} px in ${strip.cw} px; page ${strip.doc} px)`);
  ok(strip.sw <= strip.cw + 1, `at 375 px all seven chips fit in the card (${strip.sw} <= ${strip.cw})`);
  await shot(page, 'phone-my-schedule');
  await page.evaluate(() => { document.getElementById('my-schedule-chips').scrollLeft = 9999; });
  await widthOK(page, 'My schedule with the strip scrolled', 375);
  await go(page, '/my-schedule?view=list'); await widthOK(page, 'My schedule (list)', 375);
  const card = page.locator('#my-schedule-list .shift-card').first();
  ok((await card.boundingBox()).height >= 60, 'a shift is one card');
  // the shift page, then the offer flow through the real screen
  await go(page, '/shifts/' + I.offerable); await widthOK(page, 'the shift', 375); await touch(page, 'the shift');
  await shot(page, 'phone-shift');
  await page.click('#shift-offer-open');
  await page.fill('#offer-form-field-note', 'SMOKE from the phone');
  await shot(page, 'phone-shift-offer-open');
  await page.click('#offer-form-save-btn');
  await page.waitForURL(/\/exchanges\/\d+$/);
  await page.waitForSelector('#exchange-card');
  ok(/Up for grabs/.test(await page.locator('#exchange-status').innerText()), 'Offer this shift → the trade page: "Up for grabs" (HTMX, no full reload)');
  await widthOK(page, 'the trade page', 375); await touch(page, 'the trade page');
  await shot(page, 'phone-exchange-offered');
  page.once('dialog', (d) => d.accept());
  await page.click('#exchange-cancel-btn');
  await page.waitForSelector('#notice-banner');
  ok(/withdrawn/i.test(await page.locator('#notice-banner').innerText()) && /Withdrawn/.test(await page.locator('#exchange-status').innerText()), 'Withdraw (confirmed) → the trade page says "The trade is withdrawn." and its state is Withdrawn');
  for (const p of ['/requests', '/requests?state=decided', '/requests?state=all', '/team-schedule', '/marketplace?tab=claims']) { await go(page, p); await widthOK(page, p, 375); await touch(page, p); }
  await go(page, '/requests'); await shot(page, 'phone-requests');
  await go(page, '/team-schedule?day=' + new Date(Date.now() + 86400000 * 3).toISOString().slice(0, 10)); await shot(page, 'phone-team-schedule');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}

console.log('375 x 740 — Lee (staff): the marketplace');
{
  const { ctx, page, errors } = await session(31, null, PHONE);
  await go(page, '/marketplace');
  await widthOK(page, 'the marketplace', 375); await touch(page, 'the marketplace');
  await shot(page, 'phone-marketplace');
  ok(await page.locator(`#exchange-card-${I.offerAna}-take-btn`).isEnabled(), 'an offer Lee may take has one enabled button, Take it');
  // the region re-fetches alone
  const before = await page.evaluate(() => { window.__marked = document.getElementById('marketplace-header'); window.__reqs = []; document.body.addEventListener('htmx:beforeRequest', (e) => window.__reqs.push({ path: e.detail.requestConfig.path, target: e.detail.target.id })); return true; });
  const trig = await page.getAttribute('#marketplace-results', 'hx-trigger');
  ok(/exchangeChanged from:body/.test(trig) && /every 30s/.test(trig) && /visibilityState/.test(trig), 'the region listens to exchangeChanged and refreshes every 30 s while the tab is visible (' + trig + ')');
  const respPromise = page.waitForResponse((r) => r.url().includes('/marketplace?tab=grabs') && r.request().headers()['hx-target'] === 'marketplace-results');
  await page.evaluate(() => htmx.trigger(document.body, 'exchangeChanged'));
  const resp = await respPromise;
  const body = await resp.text();
  const after = await page.evaluate(() => ({ same: window.__marked === document.getElementById('marketplace-header'), reqs: window.__reqs }));
  ok(after.reqs.length === 1 && after.reqs[0].target === 'marketplace-results' && after.same, 'exchangeChanged re-fetches exactly one request, targeting #marketplace-results; the page header was not touched');
  ok(!body.includes('<html') && !body.includes('page-header') && body.includes('id="marketplace-results"'), `and the answer is the region alone (${body.length} bytes)`);
  await page.click(`#exchange-card-${I.offerAna}-take-btn`);
  await page.waitForSelector('#notice-banner');
  ok((await page.locator('#notice-banner').innerText()).includes('It\'s yours — added to your schedule.'), 'Take it → the banner "It\'s yours — added to your schedule."');
  ok(await page.locator(`#exchange-card-${I.offerAna}`).count() === 0, 'the card is gone from Up for grabs');
  await shot(page, 'phone-marketplace-taken');
  await go(page, '/marketplace?tab=forme'); await widthOK(page, 'the For me tab', 375); await touch(page, 'the For me tab');
  ok(await page.locator(`#exchange-card-${I.give}-accept-btn`).isEnabled() && await page.locator(`#exchange-card-${I.give}-refuse-btn`).isEnabled(), 'a gift offered to Lee has Accept and Refuse');
  await shot(page, 'phone-marketplace-forme');
  await page.click(`#exchange-card-${I.give}-accept-btn`);
  await page.waitForURL(/\/shifts\/\d+/);
  ok(/yours/i.test(await page.locator('#notice-banner').innerText()), 'Accept → the shift page with "Accepted — the shift is yours."');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}

console.log('375 x 740 — Mara (manager): approvals');
{
  const { ctx, page, errors } = await session(33, null, PHONE);
  await go(page, '/approvals');
  await widthOK(page, 'Approvals', 375); await touch(page, 'Approvals');
  ok(await page.locator(`#approval-card-${I.pending}`).count() === 1 && (await page.locator('#nav-approvals-count').count()) === 1, 'the pending trade is in her inbox and the menu has its count badge');
  await shot(page, 'phone-approvals');
  await page.click(`#approval-card-${I.pending}-approve-btn`);
  await page.waitForSelector('#notice-banner');
  ok((await page.locator('#notice-banner').innerText()).includes('Approved') && await page.locator(`#approval-card-${I.pending}`).count() === 0, 'Approve inline → "Approved — the shift has moved." and the card leaves the inbox');
  await go(page, '/coverage?shift=' + I.open); await widthOK(page, 'Coverage', 375); await touch(page, 'Coverage');
  await shot(page, 'phone-coverage');
  await go(page, '/coverage'); await widthOK(page, 'Coverage (open shifts)', 375);
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}

console.log('375 x 740 — Marco (both restaurants): the zones');
{
  const { ctx, page, errors } = await session(27, 101, PHONE);
  await go(page, '/my-schedule?view=list'); await widthOK(page, 'My schedule (Marco)', 375);
  const t = await page.locator('#my-schedule-list').innerText();
  ok(/EDT/.test(t) && /CDT/.test(t) && /SMOKE Downtown/.test(t) && /SMOKE Airport/.test(t), 'his cards name the zone and the restaurant (EDT and CDT)');
  await shot(page, 'phone-my-schedule-two-zones');
  await go(page, '/team-schedule?site=102'); await widthOK(page, 'Team schedule (Airport)', 375);
  ok(errors.length === 0, 'no console errors');
  await ctx.close();
}

console.log('JavaScript off — Lee takes a shift with a plain form post');
{
  const { ctx, page } = await session(31, null, PHONE, { javaScriptEnabled: false });
  await go(page, '/marketplace');
  await page.click(`#exchange-card-${I.offerAna2}-take-btn`);
  await page.waitForLoadState('networkidle');
  ok(page.url().includes('/marketplace') && page.url().includes('notice=claim_approved') && (await page.locator('#notice-banner').innerText()).includes('It\'s yours'), 'the form posts, is redirected and lands with the banner — the marketplace works with JavaScript off');
  await ctx.close();
}

// ================= 1280 x 800 =================
console.log('1280 x 800 — Priya');
{
  const { ctx, page, errors } = await session(26, null, DESK);
  await go(page, '/my-schedule?week=' + I.week);
  await widthOK(page, 'My schedule (week grid)', 1280);
  const cols = await page.evaluate(() => [...document.querySelectorAll('#my-schedule-week > .week-col')].filter((c) => c.getBoundingClientRect().width > 0).map((c) => Math.round(c.getBoundingClientRect().x)));
  ok(cols.length === 7 && new Set(cols).size === 7, `the week is a seven-column grid on a desktop (${cols.length} columns, ${new Set(cols).size} distinct x)`);
  ok(!(await page.locator('#app-tabbar').isVisible()) && await page.locator('#left-sidenav').isVisible(), 'the sidebar, not the tab bar');
  await shot(page, 'desktop-my-schedule');
  await go(page, '/team-schedule'); await widthOK(page, 'Team schedule', 1280); await shot(page, 'desktop-team-schedule');
  await go(page, '/shifts/' + I.w.p2); await widthOK(page, 'the shift', 1280); await shot(page, 'desktop-shift');
  await go(page, '/requests?state=all'); await widthOK(page, 'My requests', 1280);
  ok(errors.length === 0, 'no console errors');
  await ctx.close();
}
console.log('1280 x 800 — Owner (admin at both, main Downtown)');
{
  const { ctx, page, errors } = await session(1, 102, DESK);
  await go(page, '/marketplace'); await widthOK(page, 'Marketplace', 1280);
  const why = await page.locator(`#exchange-card-${I.offerAna3}-why`).innerText();
  ok(why.includes('You can pick up shifts only at your main restaurant.') && await page.locator(`#exchange-card-${I.offerAna3}-take-btn`).isDisabled(), 'the owner sees the offer with Take it disabled and: "' + why.trim() + '"');
  await shot(page, 'desktop-marketplace-disabled');
  await go(page, '/exchanges/' + I.offerAna3); await widthOK(page, 'the trade page', 1280); await shot(page, 'desktop-exchange');
  await go(page, '/approvals'); await widthOK(page, 'Approvals', 1280); await shot(page, 'desktop-approvals');
  await go(page, '/coverage?shift=' + I.open); await widthOK(page, 'Coverage', 1280); await shot(page, 'desktop-coverage');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}
await browser.close();
console.log(failed === 0 ? `all ${passed} passed` : `${failed} FAILED (${passed} passed)`);
process.exit(failed === 0 ? 0 : 1);
