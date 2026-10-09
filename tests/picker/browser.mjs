// Headless Chromium pass for the record picker in txtSchedules (design-system references/record-picker.md; the adapted picker-browser.mjs): a manager on a desktop (the shift form — optional person, the live check
// that listens for change —, time off for someone, Show more, keyboard, Clear), a staff member on a PHONE (Give and Swap colleague pickers: the dialog fills the screen, rows are touch targets, no sideways scroll, a give
// goes through), dark mode. Run through tests/picker/run.sh (servers up). Playwright comes from the kernel's web/node_modules (read only). Screenshots in $SHOTS.
import { createRequire } from 'node:module';
import crypto from 'node:crypto';
import { execFileSync } from 'node:child_process';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
const BASE = process.env.TS_BASE || 'http://127.0.0.1:8191';
const SHOTS = process.env.SHOTS || '/tmp/txtschedules-shots-picker';
const KEY = process.env.ACTION_TOKEN_KEY;
const prep = JSON.parse(execFileSync('php', [new URL('./browser_prep.php', import.meta.url).pathname], { encoding: 'utf8', env: process.env }));
const I = prep.ids;
const mint = (member, scope = null) => {
  const payload = `${member}.${Math.floor(Date.now() / 1000) + 60}.txtschedules.${crypto.randomBytes(16).toString('hex')}`;
  const token = payload + '.' + crypto.createHmac('sha256', KEY).update('sso:' + payload).digest('hex');
  const text = Buffer.from(JSON.stringify({ ...prep.claims[String(member)], member_id: member, scope })).toString('base64url');
  return BASE + '/sso?' + new URLSearchParams({ token, claims: text + '.' + crypto.createHmac('sha256', KEY).update(text).digest('hex') });
};
const sql = (q) => execFileSync('php', ['-r', `require '${new URL('../phase3/slice4/lib.php', import.meta.url).pathname}'; echo json_encode(q(${JSON.stringify(q)}));`], { encoding: 'utf8', env: process.env });
const rows = (q) => JSON.parse(sql(q));
let failed = 0, passed = 0;
const ok = (c, l) => { if (c) passed++; else failed++; console.log((c ? '  ok   ' : '  FAIL ') + l); };
const browser = await chromium.launch({ ignoreDefaultArgs: ['--hide-scrollbars'] });
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
const shot = (page, name, full = false) => page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: full });
const noSideways = async (page, label, vw) => { const o = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth, iw: window.innerWidth })); ok(o.sw <= o.cw && o.iw === vw, `no sideways scroll on ${label} (scrollWidth ${o.sw} <= ${o.cw} of ${o.iw})`); };
const open = async (page, id) => { await page.click(`#${id}-open`); await page.waitForSelector('#record-picker.show'); await page.waitForSelector('#record-picker .record-picker-row'); await page.waitForFunction(() => document.activeElement && document.activeElement.id === 'record-picker-search', null, { timeout: 3000 }).catch(() => {}); };
const label = (page, id) => page.evaluate((i) => document.getElementById(i + '-open').textContent.trim(), id);
const rowsIn = (page) => page.$$eval('#record-picker .record-picker-row', (r) => r.length);

// ---- the manager, desktop ----------------------------------------------------------------------------------
{
  const { ctx, page, errors } = await session(33, null, { width: 1280, height: 800 });
  const F = 'shift-form-field-assignee';
  await page.goto(`${BASE}/shifts/new?site=102`, { waitUntil: 'networkidle' });
  ok(await page.$(`#${F}-open`) !== null, 'the shift form has the Who picker');
  ok((await label(page, F)) === 'Leave open', 'it shows its placeholder, Leave open');
  await shot(page, '1-shift-form-desktop');
  await open(page, F);
  await page.waitForFunction(() => document.activeElement && document.activeElement.id === 'record-picker-search', null, { timeout: 3000 }).catch(() => {});
  ok(await page.evaluate(() => document.activeElement.id === 'record-picker-search'), 'the search box has focus');
  ok((await page.textContent('#record-picker-title')).trim() === 'Who works the shift', 'the title is the field\'s');
  ok((await rowsIn(page)) === 25, 'the first page is 25 rows');
  await page.click('#record-picker-more'); await page.waitForFunction(() => document.querySelectorAll('#record-picker .record-picker-row').length > 25);
  const total = Number(rows("SELECT count(*) AS n FROM staff_profiles sp JOIN member_site_roles r ON r.member_id = sp.member_id AND r.scope_id = 102 JOIN members m ON m.id = sp.member_id WHERE sp.active AND m.status = 'active' AND m.member_kind = 'human'")[0].n);
  ok((await rowsIn(page)) === total && !(await page.$('#record-picker-more')), `Show more appends the rest in place (${total} rows)`);
  await page.fill('#record-picker-search', 'picker 07'); await page.waitForFunction(() => document.querySelectorAll('#record-picker .record-picker-row').length === 1);
  ok(true, 'typing narrows the list to one row');
  await shot(page, '2-picker-desktop');
  await page.keyboard.press('ArrowDown');
  ok(await page.evaluate(() => document.activeElement.classList.contains('record-picker-row')), 'ArrowDown moves to the first row');
  const check = page.waitForResponse((r) => r.url().includes('/shifts/check.php'));
  await page.keyboard.press('Enter'); await page.waitForSelector('#record-picker', { state: 'hidden' });
  ok((await label(page, F)) === 'SMOKE Picker 07' && (await page.inputValue(`#${F}`)) === '9007', 'Enter picks it: the label shows and the hidden input holds the id');
  ok((await check).ok(), 'the shift form\'s live check ran after the pick (change reached the form)');
  await page.waitForFunction((i) => document.activeElement.id === i + '-open', F, { timeout: 3000 }).catch(() => {});
  ok(await page.evaluate((i) => document.activeElement.id === i + '-open', F), 'focus returns to the opener');
  ok(await page.evaluate((i) => !document.getElementById(i + '-clear').classList.contains('d-none'), F), 'Clear appears with a choice');
  await page.click(`#${F}-clear`);
  ok((await label(page, F)) === 'Leave open' && (await page.inputValue(`#${F}`)) === '', 'Clear returns to Leave open');
  await open(page, F); await page.fill('#record-picker-search', 'ana');
  await page.waitForFunction(() => document.querySelectorAll('#record-picker .record-picker-row').length >= 1 && document.querySelector('#record-picker .record-picker-row').dataset.label.includes('Ana'));
  await page.keyboard.press('Enter'); await page.waitForSelector('#record-picker', { state: 'hidden' });
  ok((await label(page, F)) === 'SMOKE Ana', 'Enter in the search box picks the first match');
  await open(page, F);
  ok(await page.evaluate(() => document.querySelector('#record-picker .record-picker-row.active')?.dataset.label === 'SMOKE Ana'), 'reopened: the chosen row is marked active');
  await page.keyboard.press('Escape'); await page.waitForSelector('#record-picker', { state: 'hidden' });
  ok((await label(page, F)) === 'SMOKE Ana', 'Escape closes and keeps the choice');
  // save the shift through the hidden input
  const n0 = Number(rows("SELECT count(*) AS n FROM shifts WHERE assignee_member_id = 30")[0].n);
  await page.goto(`${BASE}/shifts/new?site=102&date=${new Date(Date.now() + 200 * 86400000).toISOString().slice(0, 10)}`, { waitUntil: 'networkidle' });
  await open(page, F); await page.fill('#record-picker-search', 'ana'); await page.waitForFunction(() => document.querySelector('#record-picker .record-picker-row')?.dataset.label === 'SMOKE Ana'); await page.keyboard.press('Enter'); await page.waitForSelector('#record-picker', { state: 'hidden' });
  await page.waitForTimeout(500);
  await page.click('#shift-form-save-btn'); await page.waitForTimeout(1200);
  ok(Number(rows("SELECT count(*) AS n FROM shifts WHERE assignee_member_id = 30")[0].n) === n0 + 1, 'the shift form saves with the picked person');
  // time off for someone: the preview refreshes on change, the site is declared
  await page.goto(`${BASE}/time-off/new?site=102`, { waitUntil: 'networkidle' });
  const T = 'time-off-form-field-member';
  ok((await label(page, T)) === 'SMOKE Mara', 'time off: "For" starts as the manager herself');
  await open(page, T);
  ok(await page.evaluate(() => document.querySelector('#record-picker .record-picker-row')?.dataset.label === 'SMOKE Mara'), 'the caller is the first row');
  const prev = page.waitForResponse((r) => r.url().includes('preview=1'));
  await page.fill('#record-picker-search', 'lee'); await page.waitForFunction(() => document.querySelector('#record-picker .record-picker-row')?.dataset.label === 'SMOKE Lee');
  await page.click('#record-picker .record-picker-row'); await page.waitForSelector('#record-picker', { state: 'hidden' });
  ok((await label(page, T)) === 'SMOKE Lee' && (await prev).ok(), 'picking Lee refreshes the time-off preview');
  await shot(page, '3-time-off-desktop');
  // availability and balances: a GET form with the picker beside Show
  for (const [path, id] of [['/availability', 'availability-person-field-member'], ['/time-off/balances', 'balances-person-field-member']]) {
    await page.goto(BASE + path, { waitUntil: 'networkidle' });
    await open(page, id); await page.fill('#record-picker-search', 'lee'); await page.waitForFunction(() => document.querySelector('#record-picker .record-picker-row')?.dataset.label === 'SMOKE Lee');
    await page.keyboard.press('Enter'); await page.waitForSelector('#record-picker', { state: 'hidden' });
    await Promise.all([page.waitForURL(/member=31/), page.click(`#${id.replace('-field-member', '')}-btn`)]);
    ok(page.url().includes('member=31'), `${path}: picking Lee and pressing Show goes to ?member=31`);
    ok((await label(page, id)) === 'SMOKE Lee', `${path}: the page opens with Lee chosen`);
  }
  await page.evaluate(() => document.documentElement.classList.add('app-skin-dark'));
  await page.goto(`${BASE}/shifts/new?site=102`, { waitUntil: 'networkidle' });
  await page.evaluate(() => document.documentElement.classList.add('app-skin-dark'));
  await open(page, F); await page.waitForTimeout(300);
  await shot(page, '4-picker-desktop-dark');
  ok(errors.length === 0, 'no page errors (manager)' + (errors.length ? ': ' + errors.join(' | ') : ''));
  await ctx.close();
}

// ---- Priya, a phone ----------------------------------------------------------------------------------------
{
  const { ctx, page, errors } = await session(26, null, { width: 375, height: 740 });
  await page.goto(`${BASE}/shifts/${I.mine}`, { waitUntil: 'networkidle' });
  await noSideways(page, 'the shift page', 375);
  await page.click('#shift-give-open');
  const G = 'give-form-field-colleague';
  ok((await label(page, G)) === 'Choose a colleague', 'Give: the placeholder shows');
  await shot(page, '5-give-phone');
  await open(page, G);
  const box = await page.evaluate(() => { const r = document.querySelector('#record-picker .modal-content').getBoundingClientRect(); return [r.width, r.height]; });
  ok(box[0] >= 374 && box[1] >= 700, `the dialog fills the phone screen (${box.join('x')})`);
  const rowH = await page.evaluate(() => document.querySelector('#record-picker .record-picker-row').getBoundingClientRect().height);
  ok(rowH >= 44, `rows are touch targets (${rowH}px)`);
  const names = await page.$$eval('#record-picker .record-picker-row', (r) => r.map((x) => x.dataset.label));
  ok(names.includes('SMOKE Ana') && !names.includes('SMOKE Priya') && !names.includes('SMOKE Marco') && !names.includes('SMOKE Joe'), `colleagues who work this position here are offered, not herself or other restaurants' people (${names.length})`);
  await shot(page, '6-picker-phone');
  await page.evaluate(() => document.documentElement.classList.add('app-skin-dark')); await page.waitForTimeout(200);
  await shot(page, '7-picker-phone-dark');
  await page.evaluate(() => document.documentElement.classList.remove('app-skin-dark'));
  await page.fill('#record-picker-search', 'SMOKE Lee'); await page.waitForFunction(() => document.querySelectorAll('#record-picker .record-picker-row').length === 1);
  await page.tap('#record-picker .record-picker-row'); await page.waitForSelector('#record-picker', { state: 'hidden' });
  ok((await label(page, G)) === 'SMOKE Lee' && (await page.inputValue(`#${G}`)) === '31', 'a tap picks Lee');
  await page.click('#give-form-save-btn'); await page.waitForTimeout(1500);
  const g = rows(`SELECT to_member_id FROM exchanges WHERE shift_id = ${I.mine} AND kind = 'give'`);
  ok(g.length === 1 && Number(g[0].to_member_id) === 31, 'Ask them: the give went to Lee through the hidden input');
  // swap: the picker beside the button, the GET form
  await page.goto(`${BASE}/shifts/${I.mine2}`, { waitUntil: 'networkidle' });
  await page.click('#shift-swap-open');
  await noSideways(page, 'the swap panel', 375);
  const S = 'swap-form-field-with';
  const wbox = await page.evaluate((i) => { const a = document.getElementById(i + '-picker').getBoundingClientRect(), b = document.getElementById('swap-pick-btn').getBoundingClientRect(); return [a.width, b.width, b.top - a.top]; }, S);
  ok(wbox[0] > 250, `the picker has the phone's full width, its button below (picker ${Math.round(wbox[0])}px)`);
  await shot(page, '8-swap-phone');
  await open(page, S); await page.fill('#record-picker-search', 'SMOKE Ana'); await page.waitForFunction(() => document.querySelector('#record-picker .record-picker-row')?.dataset.label === 'SMOKE Ana');
  await page.tap('#record-picker .record-picker-row'); await page.waitForSelector('#record-picker', { state: 'hidden' });
  await Promise.all([page.waitForFunction(() => location.search.includes('swap_with=30')), page.click('#swap-pick-btn')]);
  await page.waitForLoadState('networkidle');
  ok((await label(page, S)) === 'SMOKE Ana', 'Show their shifts: the page reloads with Ana chosen (htmx swap keeps the one modal)');
  ok((await page.$$('#record-picker')).length === 1, 'still exactly one modal after the swap');
  await noSideways(page, 'the swap page after the swap', 375);
  await page.goto(`${BASE}/availability`, { waitUntil: 'networkidle' });
  await noSideways(page, 'availability (staff: no person picker)', 375);
  ok(errors.length === 0, 'no page errors (phone)' + (errors.length ? ': ' + errors.join(' | ') : ''));
  await ctx.close();
}

// ---- the manager on a phone: availability, time off, the builder's Move to ------------------------------------
{
  const { ctx, page, errors } = await session(33, null, { width: 375, height: 740 });
  for (const [path, name] of [['/availability', 'availability'], ['/time-off/balances', 'balances'], ['/time-off/new?site=102', 'time off'], ['/shifts/new?site=102', 'the shift form']]) {
    await page.goto(BASE + path, { waitUntil: 'networkidle' });
    await noSideways(page, `${name} (manager, phone)`, 375);
    const small = await page.evaluate(() => [...document.querySelectorAll('.record-picker-open, .record-picker-search-btn, .record-picker-clear')].filter((e) => e.getBoundingClientRect().width > 0 && e.getBoundingClientRect().height < 43.5).map((e) => e.id + ':' + Math.round(e.getBoundingClientRect().height)));
    ok(small.length === 0, `the picker's buttons are at least 44px tall on ${name}` + (small.length ? ' — ' + small.join(', ') : ''));
    if (name === 'availability') await shot(page, '9-availability-phone');
  }
  await page.goto(`${BASE}/shifts/${I.mine}`, { waitUntil: 'networkidle' });
  await page.click(`#shift-move-${I.mine}-open`);
  const M = `shift-move-${I.mine}-person`;
  ok((await label(page, M)) === 'SMOKE Priya', 'Move to…: the holder is the chosen person');
  await shot(page, '10-move-phone');
  await open(page, M); await page.fill('#record-picker-search', 'SMOKE Lee'); await page.waitForFunction(() => document.querySelector('#record-picker .record-picker-row')?.dataset.label === 'SMOKE Lee');
  await page.tap('#record-picker .record-picker-row'); await page.waitForSelector('#record-picker', { state: 'hidden' });
  ok((await page.inputValue(`#${M}`)) === '31', 'Move to…: Lee picked');
  ok(errors.length === 0, 'no page errors (manager, phone)' + (errors.length ? ': ' + errors.join(' | ') : ''));
  await ctx.close();
}
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
