// Headless Chromium proof of slice 4 (docs/build-specs/people-positions.md, "Proof — 375 px and 1280 px"): no sideways scroll on any screen of the slice, the staff cards stack one column on a phone, every button and
// control at least 44 px tall at 375, the pay form is readable and confirms, and the flows work as a person clicks them — a person adds a card on the phone and it waits for a manager, a manager verifies it, the
// owner sets and clears a person's own rate and a position's default, adds a certification. Run through tests/phase3/slice4/run.sh (servers up). Playwright comes from the kernel's web/node_modules (read only).
import { createRequire } from 'node:module';
import crypto from 'node:crypto';
import { execFileSync } from 'node:child_process';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8191';
const SHOTS = process.env.SHOTS || '/tmp/txtschedules-shots-p3s4';
const KEY = process.env.ACTION_TOKEN_KEY;
const prep = JSON.parse(execFileSync('php', [new URL('./browser_prep.php', import.meta.url).pathname], { encoding: 'utf8', env: process.env }));
const I = prep.ids;
const mint = (member, scope = null) => {
  const payload = `${member}.${Math.floor(Date.now() / 1000) + 60}.txtschedules.${crypto.randomBytes(16).toString('hex')}`;
  const token = payload + '.' + crypto.createHmac('sha256', KEY).update('sso:' + payload).digest('hex');
  const text = Buffer.from(JSON.stringify({ ...prep.claims[String(member)], member_id: member, scope })).toString('base64url');
  return BASE + '/sso?' + new URLSearchParams({ token, claims: text + '.' + crypto.createHmac('sha256', KEY).update(text).digest('hex') });
};
const psql = (sql) => execFileSync('php', ['-r', `require '${new URL('./lib.php', import.meta.url).pathname}'; echo json_encode(q(${JSON.stringify(sql)}));`], { encoding: 'utf8', env: process.env });
const rows = (sql) => JSON.parse(psql(sql));
let failed = 0, passed = 0;
const ok = (c, l) => { if (c) passed++; else failed++; console.log((c ? '  ok   ' : '  FAIL ') + l); };
const browser = await chromium.launch({ ignoreDefaultArgs: ['--hide-scrollbars'] });
const PHONE = { width: 375, height: 740 }, DESK = { width: 1280, height: 800 };
async function session(member, scope, viewport) {
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: 2, isMobile: viewport.width < 600, hasTouch: viewport.width < 600 });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  page.on('dialog', (d) => d.accept());
  await page.goto(mint(member, scope), { waitUntil: 'networkidle' });
  return { ctx, page, errors };
}
const widthOK = async (page, label, vw) => { const o = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth, iw: window.innerWidth })); ok(o.sw <= o.cw && o.iw === vw, `no sideways scroll on ${label} (scrollWidth ${o.sw} <= ${o.cw} of ${o.iw})`); };
const touch = async (page, label) => {
  const small = await page.evaluate(() => [...document.querySelectorAll('#page-content a.btn, #page-content button.btn, #page-content summary.btn, #page-content select.form-select, #page-content input.form-control[type=text], #page-content input.form-control[type=search], #page-content input.form-control[type=date], #page-content input.form-control[type=number], #page-content input.form-control[type=color], #page-content textarea.form-control, #page-content label.btn-touch, #page-content div.btn-touch, #app-tabbar .app-tab')]
    .filter((e) => { const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return cs.display !== 'none' && cs.visibility !== 'hidden' && r.width > 0 && r.height < 43.5 && !e.closest('details:not([open]) > :not(summary)'); })
    .map((e) => (e.id || e.className) + ':' + Math.round(e.getBoundingClientRect().height)));
  ok(small.length === 0, `every button and control is at least 44 px tall on ${label}` + (small.length ? ' — too small: ' + small.join(', ') : ''));
};
const go = async (page, path) => { await page.goto(BASE + path, { waitUntil: 'networkidle' }); };
const shot = (page, name, full = true) => page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: full });
const screens = async (who, member, scope, viewport, tag, list) => {
  const { ctx, page, errors } = await session(member, scope, viewport);
  for (const [name, path] of list) {
    await go(page, path);
    await widthOK(page, `${name} (${who}, ${tag})`, viewport.width);
    if (viewport.width < 600) await touch(page, `${name} (${who})`);
    await shot(page, `${tag}-${name}`);
  }
  ok(errors.length === 0, `no console errors across the ${tag} ${who} screens` + (errors.length ? ': ' + errors.join(' | ') : ''));
  await ctx.close();
};

for (const [tag, vp] of [['phone', PHONE], ['desk', DESK]]) {
  console.log(`${vp.width} x ${vp.height} — screens`);
  await screens('Mara', 33, null, vp, tag, [
    ['staff-list', '/staff/?site=102'], ['staff-view-ana', '/staff/30'], ['staff-edit-ana', '/staff/30/edit'], ['positions-mara', '/positions/?site=102'],
    ['certifications', '/certifications/?site=102'], ['certifications-expired', '/certifications/?site=102&state=expired'], ['staff-list-filtered', '/staff/?site=102&q=a&on_schedule=yes'],
  ]);
  await screens('Owner', 1, 102, vp, tag, [
    ['staff-view-priya-pay', '/staff/26'], ['positions-owner', '/positions/?site=102'], ['position-add', '/positions/new?site=102'], ['position-edit', `/positions/${I.host}/edit`],
    ['kind-add', '/certifications/kinds/new?site=102'], ['kind-edit', `/certifications/kinds/${I.fa}/edit`], ['staff-view-lee', '/staff/31'],
  ]);
  await screens('Priya', 26, null, vp, tag, [['my-certifications', '/certifications/mine'], ['my-profile', '/staff/26']]);
  await screens('Lee', 31, null, vp, tag, [['my-certifications-lee', '/certifications/mine'], ['my-profile-lee', '/staff/31']]);
}

console.log('375 x 740 — the staff cards stack one column, and a person adds a card on the phone');
{
  const { ctx, page, errors } = await session(33, null, PHONE);
  await go(page, '/staff/?site=102');
  const cols = await page.evaluate(() => [...document.querySelectorAll('#staff-list-cards .staff-card')].map((e) => Math.round(e.getBoundingClientRect().width)));
  ok(cols.length >= 6 && cols.every((w) => w > 300), `the staff cards are one column on a phone (${cols.length} cards, ${[...new Set(cols)].join(', ')} px wide)`);
  ok(await page.locator('#staff-card-31-expired').count() === 1 && await page.locator('#staff-card-31-minor').count() === 1 && await page.locator('#staff-card-30-expired').count() === 0, 'Lee\'s card carries the danger chip and the minor chip; Ana\'s no danger chip');
  await page.click('#staff-card-30-name a');
  await page.waitForURL(/\/staff\/30/);
  ok(await page.locator('#staff-view-name').innerText() === 'SMOKE Ana' && await page.locator('#staff-view-back').count() === 1, 'a name opens the person, with "Back to Staff"');
  await ctx.close();
}
{
  const { ctx, page, errors } = await session(31, null, PHONE);
  await go(page, '/certifications/mine');
  ok((await page.locator('#certification-form-field-kind option').allInnerTexts()).some((t) => t.includes('Food handler')) && (await page.locator('#certification-form-field-kind option').allInnerTexts()).some((t) => t.includes('Alcohol service')), 'Lee\'s phone offers Airport\'s kinds');
  const expired = await page.locator(`#certification-${I.leeCard}-status`).innerText();
  ok(expired.startsWith('Expired') && (await page.locator(`#certification-${I.leeCard}-verified`).innerText()).includes('Verified by a manager'), 'his expired card reads "' + expired + '" and "Verified by a manager"');
  await page.selectOption('#certification-form-field-kind', String(I.al));
  const d = new Date(Date.now() + 400 * 86400000).toISOString().slice(0, 10);
  await page.fill('#certification-form-field-expires', d);
  await page.fill('#certification-form-field-reference', 'SMOKE-L-2');
  await shot(page, 'phone-my-certifications-filled');
  await page.click('#certification-form-save-btn');
  await page.waitForURL(/#certification-\d+$/, { timeout: 8000 });
  const nc = rows(`SELECT id, verified_at FROM certifications WHERE reference = 'SMOKE-L-2'`)[0];
  ok(nc && nc.verified_at === null && page.url().endsWith('#certification-' + nc.id), 'saved: an unverified card, and the page landed on it (…#certification-' + nc.id + ')');
  ok((await page.locator(`#certification-${nc.id}-verified`).innerText()).includes('Waiting for a manager to check'), 'the card says "Waiting for a manager to check"');
  await widthOK(page, 'my certifications after saving', 375); await touch(page, 'my certifications after saving'); await shot(page, 'phone-my-certifications-saved');
  await page.click(`#certification-${nc.id}-edit-btn`);
  await page.waitForSelector('#certification-form-cancel-link');
  await page.fill('#certification-form-field-reference', 'SMOKE-L-2b');
  await page.click('#certification-form-save-btn');
  await page.waitForURL(/notice=st_cert_updated/, { timeout: 8000 });
  ok(rows(`SELECT reference FROM certifications WHERE id = ${nc.id}`)[0].reference === 'SMOKE-L-2b', 'correcting the card works');
  await page.click(`#certification-${nc.id}-remove-btn`);
  await page.waitForFunction((id) => !document.getElementById('certification-' + id), nc.id, { timeout: 8000 }).catch(() => {});
  ok(rows(`SELECT removed_at FROM certifications WHERE id = ${nc.id}`)[0].removed_at !== null, 'Remove (with the browser\'s confirm): the card is removed');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' | ') : ''));
  await ctx.close();
}
{
  console.log('375 x 740 — Mara verifies from the due list');
  const { ctx, page, errors } = await session(33, null, PHONE);
  await go(page, '/certifications/?site=102&state=to_verify');
  const btn = `#due-cert-${I.anaCard}-verify-btn`;
  ok(await page.locator(btn).count() === 1, 'Ana\'s alcohol card is on the list with a Verify button');
  await page.click(btn);
  await page.waitForURL(/notice=st_cert_verified/, { timeout: 8000 });
  ok(rows(`SELECT verified_by FROM certifications WHERE id = ${I.anaCard}`)[0].verified_by === '33' || Number(rows(`SELECT verified_by FROM certifications WHERE id = ${I.anaCard}`)[0].verified_by) === 33, 'verified: the card carries Mara');
  ok(await page.locator(btn).count() === 0, 'and it left the to-verify list');
  await shot(page, 'phone-certifications-after-verify');
  await go(page, '/certifications/?site=102&state=missing');
  ok(await page.locator('[id^=due-missing-] a.btn').count() >= 1, 'a missing row offers "Add the card"');
  await page.click('[id^=due-missing-] a.btn');
  await page.waitForURL(/\/staff\/\d+/);
  ok(await page.locator('#certification-form-field-kind').inputValue() !== '', 'which opens the person with the kind already chosen');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' | ') : ''));
  await ctx.close();
}
{
  console.log('375 x 740 — the owner: pay, positions and a certification');
  const { ctx, page, errors } = await session(1, 102, PHONE);
  await go(page, '/staff/31');
  ok((await page.locator(`#staff-position-${I.srv}-pay`).innerText()).includes('$21.37 an hour') && (await page.locator(`#staff-position-${I.srv}-pay`).innerText()).includes('Default for the position'), 'Lee: "$21.37 an hour — Default for the position"');
  const lay = await page.evaluate((id) => { const f = document.querySelector('#staff-position-' + id + '-pay-form'); const i = document.getElementById('staff-position-' + id + '-pay-form-field-rate').getBoundingClientRect(); const b = document.getElementById('staff-position-' + id + '-pay-form-save-btn').getBoundingClientRect(); return [Math.round(i.width), Math.round(b.width), Math.round(i.height), Math.round(f.getBoundingClientRect().width)]; }, I.srv);
  ok(lay[0] >= 150 && lay[2] >= 44 && lay[1] >= 44, `the pay form is readable on a phone: the rate field ${lay[0]} px wide × ${lay[2]} tall, the button ${lay[1]} px`);
  await page.fill(`#staff-position-${I.srv}-pay-form-field-rate`, '19.25');
  await page.click(`#staff-position-${I.srv}-pay-form-save-btn`);
  await page.waitForURL(/notice=st_wage_set/, { timeout: 8000 });
  ok((await page.locator(`#staff-position-${I.srv}-pay`).innerText()).includes('$19.25 an hour') && (await page.locator(`#staff-position-${I.srv}-pay`).innerText()).includes('Own rate'), 'after Set (with the confirm): "$19.25 an hour — Own rate"');
  ok((await page.locator('#notice-banner').innerText()).includes('own rate is set') && !(await page.locator('#notice-banner').innerText()).includes('19.25'), 'the banner does not repeat the number');
  await shot(page, 'phone-staff-pay-set');
  await page.fill(`#staff-position-${I.srv}-pay-form-field-rate`, '');
  await page.click(`#staff-position-${I.srv}-pay-form-save-btn`);
  await page.waitForURL(/notice=st_wage_cleared/, { timeout: 8000 });
  ok((await page.locator(`#staff-position-${I.srv}-pay`).innerText()).includes('Default for the position'), 'emptied and Set: the position\'s default applies again');
  await go(page, '/positions/?site=102');
  await page.fill(`#position-${I.srv}-rate-form-field-rate`, '20.00');
  await page.click(`#position-${I.srv}-rate-form-save-btn`);
  await page.waitForURL(/notice=ps_rate_set/, { timeout: 8000 });
  ok((await page.locator(`#position-${I.srv}-rate`).innerText()).includes('$20.00 an hour') && (await page.locator(`#position-${I.srv}-reach`).innerText()).startsWith('Applies to '), 'the default is now $20.00 and the card says who it reaches: "' + (await page.locator(`#position-${I.srv}-reach`).innerText()) + '"');
  await widthOK(page, 'positions after the rate', 375);
  await go(page, '/certifications/kinds/new?site=102');
  await page.fill('#kind-form-field-name', 'SMOKE Fire safety');
  await page.fill('#kind-form-field-warn', '45');
  await page.check(`#kind-form-field-position-${I.host}`);
  await page.click('#kind-form-save-btn');
  await page.waitForURL(/#kind-\d+$/, { timeout: 8000 });
  const nk = rows(`SELECT id, warn_days, track_expiry FROM certification_kinds WHERE name = 'SMOKE Fire safety'`)[0];
  ok(nk && Number(nk.warn_days) === 45 && page.url().endsWith('#kind-' + nk.id) && (await page.locator(`#kind-${nk.id}-positions`).innerText()).includes('SMOKE Host'), 'a new certification: 45 days of warning, needed by Host, the page landed on it (…#kind-' + nk.id + ')');
  await go(page, '/positions/new?site=102');
  await page.fill('#position-form-field-name', 'SMOKE Busser');
  await page.selectOption('#position-form-field-area', 'front');
  await page.click('#position-form-save-btn');
  await page.waitForURL(/#position-\d+$/, { timeout: 8000 });
  ok(rows(`SELECT id FROM positions WHERE name = 'SMOKE Busser'`).length === 1, 'a new position from the phone');
  await go(page, '/staff/31/edit');
  await widthOK(page, 'the profile form', 375); await touch(page, 'the profile form');
  await page.fill('#staff-edit-form-field-hours', '20');
  await page.click('#staff-edit-form-save-btn');
  await page.waitForURL(/\/staff\/31\?notice=st_saved/, { timeout: 8000 });
  ok(Number(rows(`SELECT max_hours_week FROM staff_profiles WHERE member_id = 31`)[0].max_hours_week) === 20 && (await page.locator('#staff-view-limit').innerText()).includes('20 h'), 'the profile form saves and the page shows the new limit');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' | ') : ''));
  await ctx.close();
}
console.log('1280 x 800 — desktop layout');
{
  const { ctx, page, errors } = await session(33, null, DESK);
  await go(page, '/staff/?site=102');
  const cols = await page.evaluate(() => [...new Set([...document.querySelectorAll('#staff-list-cards .staff-card')].map((e) => Math.round(e.getBoundingClientRect().left)))].length);
  ok(cols >= 2, `on a desktop the staff cards sit in ${cols} columns`);
  await go(page, '/certifications/?site=102');
  ok(await page.locator('#certifications-kinds .card').count() >= 3, 'the kinds are cards');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' | ') : ''));
  await ctx.close();
}
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
