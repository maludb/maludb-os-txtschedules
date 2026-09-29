// Headless Chromium proof of slice 3 (docs/build-specs/availability-time-off.md, "Proof — 375 px and 1280 px"): no sideways scroll on any screen of the slice, the day list and the request form are
// one column on a phone, every button and control at least 44 px tall at 375, the approver's card is readable on a phone, and the flows work as a person clicks them — the availability form, the
// request form with its live preview, approving with "Also open those shifts", adjusting a balance, a blackout date. Run through tests/phase3/slice3/run.sh (servers up). Playwright comes
// from the kernel's web/node_modules (read only). Screenshots: $SHOTS.
import { createRequire } from 'node:module';
import crypto from 'node:crypto';
import { execFileSync } from 'node:child_process';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8191';
const SHOTS = process.env.SHOTS || '/tmp/txtschedules-shots-p3s3';
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
  page.on('dialog', (d) => d.accept());                                           // hx-confirm is the browser's own dialog: a person says OK
  await page.goto(mint(member, scope), { waitUntil: 'networkidle' });
  return { ctx, page, errors };
}
const widthOK = async (page, label, vw) => { const o = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth, iw: window.innerWidth })); ok(o.sw <= o.cw && o.iw === vw, `no sideways scroll on ${label} (scrollWidth ${o.sw} <= ${o.cw} of ${o.iw})`); };
const touch = async (page, label) => {
  const small = await page.evaluate(() => [...document.querySelectorAll('#page-content a.btn, #page-content button.btn, #page-content summary.btn, #page-content select.form-select, #page-content input.form-control[type=text], #page-content input.form-control[type=date], #page-content input.form-control[type=time], #page-content input.form-control[type=number], #page-content label.btn-touch, #app-tabbar .app-tab')]
    .filter((e) => { const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return cs.display !== 'none' && cs.visibility !== 'hidden' && r.width > 0 && r.height < 43.5 && !e.closest('details:not([open]) > :not(summary)'); })
    .map((e) => (e.id || e.className) + ':' + Math.round(e.getBoundingClientRect().height)));
  ok(small.length === 0, `every button and control is at least 44 px tall on ${label}` + (small.length ? ' — too small: ' + small.join(', ') : ''));
};
const go = async (page, path) => { await page.goto(BASE + path, { waitUntil: 'networkidle' }); };
const shot = (page, name, full = true) => page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: full });
const screens = async (who, member, scope, viewport, tag, list) => {
  const { ctx, page, errors } = await session(member, scope, viewport);
  for (const [name, path, full] of list) {
    await go(page, path);
    await widthOK(page, `${name} (${who}, ${tag})`, viewport.width);
    if (viewport.width < 600) await touch(page, `${name} (${who})`);
    await shot(page, `${tag}-${name}`, full !== false);
  }
  ok(errors.length === 0, `no console errors across the ${tag} ${who} screens` + (errors.length ? ': ' + errors.join(' | ') : ''));
  await ctx.close();
};
const tomorrow = (n) => new Date(Date.now() + n * 86400000).toISOString().slice(0, 10);

for (const [tag, vp] of [['phone', PHONE], ['desk', DESK]]) {
  console.log(`${vp.width} x ${vp.height} — screens`);
  await screens('Priya', 26, null, vp, tag, [
    ['availability', '/availability'], ['availability-add', '/availability?add=4'], ['time-off', '/time-off'], ['time-off-view', `/time-off/${I.priyaReq}`], ['time-off-add', `/time-off/new?type=${I.vac}&from=${I.offDay}`],
    ['balances', '/time-off/balances'], ['my-requests', '/requests?state=all'],
  ]);
  await screens('Mara', 33, null, vp, tag, [
    ['approvals', '/approvals'], ['time-off-all', '/time-off?member=all&site=102'], ['time-off-who', `/time-off?on=${I.offDay}&site=102`], ['time-off-view-approver', `/time-off/${I.priyaReq}`],
    ['availability-of-priya', '/availability?member=26'], ['balances-of-priya', '/time-off/balances?member=26'],
  ]);
  await screens('Owner', 1, 102, vp, tag, [['balances-adjust', '/time-off/balances?member=26'], ['types', '/site/time-off?site=102'], ['types-edit', `/site/time-off?site=102&edit=${I.vac}`]]);
}

console.log('375 x 740 — Priya asks for time off and sets availability, as a person clicks');
{
  const { ctx, page, errors } = await session(26, null, PHONE);
  await go(page, '/availability');
  const cols = await page.evaluate(() => [...document.querySelectorAll('#availability-week > div')].map((e) => Math.round(e.getBoundingClientRect().width)));
  ok(cols.length === 7 && cols.every((w) => w > 300), `the seven days are one column on a phone (${cols.join(', ')} px wide)`);
  await page.click('#availability-day-2-add-btn');
  await page.waitForSelector('#availability-form');
  ok((await page.locator('#availability-form-field-weekday').inputValue()) === '2', 'Add on Tuesday opens the form with Tuesday chosen');
  await page.selectOption('#availability-form-field-kind', 'unavailable');
  await page.fill('#availability-form-field-starts', '18:00');
  await page.fill('#availability-form-field-ends', '22:00');
  await shot(page, 'phone-availability-form-filled');
  await page.click('#availability-form-save-btn');
  await page.waitForURL(/#availability-block-\d+$/);
  const nb = rows(`SELECT id, status, weekday FROM availability_rules WHERE member_id = 26 ORDER BY id DESC LIMIT 1`)[0];
  ok(nb.status === 'pending' && Number(nb.weekday) === 2 && page.url().endsWith('#availability-block-' + nb.id), 'saved: a pending block for Tuesday, and the page landed on it (…#availability-block-' + nb.id + ')');
  ok(await page.locator('#notice-banner').innerText().then((t) => t.includes('once a manager approves')), 'the banner says it counts once a manager approves it');
  await widthOK(page, 'availability after saving', 375);
  await shot(page, 'phone-availability-saved');
  await page.click(`#availability-block-${nb.id}-remove-btn`);
  await page.waitForFunction((id) => !document.getElementById('availability-block-' + id), nb.id, { timeout: 8000 }).catch(() => {});
  ok(rows(`SELECT status FROM availability_rules WHERE id = ${nb.id}`)[0].status === 'replaced', 'Remove (with the browser\'s confirm): the block is out of effect');

  await go(page, `/time-off/new?type=${I.vac}`);
  await widthOK(page, 'the request form', 375); await touch(page, 'the request form');
  const fcols = await page.evaluate(() => { const r = (id) => Math.round(document.getElementById(id).getBoundingClientRect().width); return [r('time-off-form-field-type'), r('time-off-form-field-from'), r('time-off-form-field-to')]; });
  ok(fcols[0] > fcols[1] * 1.8 && fcols[1] < 200 && fcols[2] < 200, `the form is one column on a phone: the kind is full width, the two dates sit side by side (${fcols.join(', ')})`);
  const d1 = tomorrow(400), d2 = tomorrow(401);
  await page.fill('#time-off-form-field-from', d1); await page.fill('#time-off-form-field-to', d2);
  await page.dispatchEvent('#time-off-form-field-to', 'change');
  await page.waitForFunction(() => document.getElementById('time-off-form-preview')?.innerText.includes('This uses 16 h.'), null, { timeout: 8000 });
  ok(true, 'the preview follows the dates as she types: "This uses 16 h." for two days');
  const bal = await page.locator('#time-off-form-preview-balance').innerText();
  ok(bal.includes('Balance 22 h') && bal.includes('this uses 16 h'), 'and the balance line: "' + bal.split('\n')[0] + '"');
  await page.click('#time-off-form-part-summary');
  await page.fill('#time-off-form-field-from-time', '09:00'); await page.fill('#time-off-form-field-to-time', '13:00');
  await page.fill('#time-off-form-field-to', d1);
  await page.dispatchEvent('#time-off-form-field-to', 'change');
  await page.waitForFunction(() => document.getElementById('time-off-form-preview')?.innerText.includes('This uses 4 h.'), null, { timeout: 8000 });
  ok(true, 'a part day: the preview says "This uses 4 h."');
  await shot(page, 'phone-time-off-form-part');
  await page.fill('#time-off-form-field-note', 'SMOKE from the phone');
  await page.click('#time-off-form-save-btn');
  await page.waitForURL(/\/time-off\/\d+$/);
  const nr = rows(`SELECT id, hours, note, status FROM time_off_requests WHERE note = 'SMOKE from the phone'`)[0];
  ok(nr && Number(nr.hours) === 4 && nr.status === 'pending' && page.url().endsWith('/time-off/' + nr.id), 'submitted: the page landed on /time-off/{id}; 4 h, pending');
  await widthOK(page, 'the new request', 375); await touch(page, 'the new request'); await shot(page, 'phone-time-off-new-request');
  await page.click('#time-off-cancel-btn');
  await page.waitForFunction(() => document.getElementById('time-off-status')?.innerText.includes('Cancelled'), null, { timeout: 8000 }).catch(() => {});
  ok(rows(`SELECT status FROM time_off_requests WHERE id = ${nr.id}`)[0].status === 'cancelled', 'Cancel (with confirm): the request is cancelled');
  // a blackout date in the preview
  await go(page, `/time-off/new?type=${I.vac}&from=${I.blackoutDay}&to=${I.blackoutDay}`);
  ok((await page.locator('#time-off-form-preview-error').innerText()).includes("No time off on that date: Valentine's Day."), 'a blackout date: the form says why before she asks');
  await shot(page, 'phone-time-off-form-blackout');
  await page.click('#time-off-form-save-btn');
  await page.waitForSelector('#flash .alert', { timeout: 8000 });
  ok((await page.locator('#flash').innerText()).includes("No time off on that date: Valentine's Day."), 'and asking anyway shows the same sentence in the banner');
  const unexpected = errors.filter((e) => !e.includes('status of 422'));      // the one 422 above is the refusal she asked for
  ok(unexpected.length === 0, 'no console errors (the deliberate 422 aside)' + (unexpected.length ? ': ' + unexpected.join(' | ') : ''));
  await ctx.close();
}

console.log('375 x 740 — Mara approves on her phone');
{
  const { ctx, page, errors } = await session(33, null, PHONE);
  await go(page, '/approvals');
  const card = `#request-card-${I.priyaReq}`;
  ok(await page.locator(card).count() === 1 && (await page.locator(card + '-shifts').innerText()).includes('Shifts this would cover') && await page.locator(`${card}-shift-${I.thu}`).count() === 1 && await page.locator(`${card}-shift-${I.fri}`).count() === 1, 'the approver\'s card lists the two covered shifts');
  ok((await page.locator(card + '-balance').innerText()).includes('this uses 16 h, leaving 6 h'), 'and the balance: "' + (await page.locator(card + '-balance').innerText()) + '"');
  const w = await page.evaluate((c) => { const r = document.querySelector(c).getBoundingClientRect(); return [Math.round(r.width), Math.round(document.documentElement.clientWidth)]; }, card);
  ok(w[0] <= w[1] - 16, `the card fits the phone (${w[0]} px in ${w[1]})`);
  ok(await page.locator(`#availability-block-${I.leeBlock}-approve-btn`).count() === 1, 'an availability card sits beside it, with Approve and Decline');
  await shot(page, 'phone-approvals-card');
  await page.check(`#request-form-${I.priyaReq}-field-open`);
  await page.click(`#request-card-${I.priyaReq}-approve-btn`);
  await page.waitForURL(/notice=to_approved_opened/, { timeout: 8000 });
  const sh = rows(`SELECT id, assignee_member_id FROM shifts WHERE id IN (${I.thu}, ${I.fri}, ${I.anaFri}) ORDER BY id`);
  const by = Object.fromEntries(sh.map((r) => [r.id, r.assignee_member_id]));
  ok(by[I.thu] === null && by[I.fri] === null && Number(by[I.anaFri]) === 30, 'approved with "Also open those shifts": Priya\'s two shifts are open, Ana\'s is not');
  ok((await page.locator('#notice-banner').innerText()).includes('the shifts it covered are open'), 'the banner says so');
  await shot(page, 'phone-approvals-after');
  await page.click(`#availability-block-${I.leeBlock}-decline-btn`);
  await page.waitForURL(/notice=av_declined/, { timeout: 8000 });
  ok(rows(`SELECT status FROM availability_rules WHERE id = ${I.leeBlock}`)[0].status === 'declined', 'Decline on the availability card: declined');
  await go(page, `/time-off/${I.anaReq}`);
  await widthOK(page, 'a pending request (approver)', 375); await touch(page, 'a pending request (approver)');
  await page.fill('#time-off-form-field-note', 'SMOKE ok');
  await page.click('#time-off-approve-btn');
  await page.waitForURL(/notice=to_approved/, { timeout: 8000 });
  ok(rows(`SELECT status, decision_note FROM time_off_requests WHERE id = ${I.anaReq}`)[0].status === 'approved', 'Approve on the request page: approved');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' | ') : ''));
  await ctx.close();
}

console.log('375 x 740 — the owner adjusts a balance, edits types and blacks out a date');
{
  const { ctx, page, errors } = await session(1, 102, PHONE);
  await go(page, '/time-off/balances?member=26');
  const before = Number(rows(`SELECT balance_hours FROM time_off_balances WHERE member_id = 26 AND type_id = ${I.vac}`)[0].balance_hours);
  await page.selectOption('#balance-form-field-type', String(I.vac));
  await page.fill('#balance-form-field-delta', '5');
  await page.fill('#balance-form-field-reason', 'SMOKE anniversary');
  await page.click('#balance-form-save-btn');
  await page.waitForURL(/#ledger-\d+$/, { timeout: 8000 });
  const after = Number(rows(`SELECT balance_hours FROM time_off_balances WHERE member_id = 26 AND type_id = ${I.vac}`)[0].balance_hours);
  ok(after === before + 5, `a grant of 5 h (with the confirm): ${before} → ${after}, and the page landed on its ledger row`);
  ok((await page.locator('tr[id^=ledger-]').first().innerText()).includes('+5'), 'the newest ledger row reads "+5"');
  await widthOK(page, 'balances after adjusting', 375); await shot(page, 'phone-balances-after');
  await go(page, '/site/time-off?site=102');
  await page.fill('#blackout-form-field-date', tomorrow(500)); await page.fill('#blackout-form-field-reason', 'SMOKE Closed for a wedding');
  await page.click('#blackout-form-save-btn');
  await page.waitForURL(/#blackout-\d+$/, { timeout: 8000 });
  ok((await page.locator('#blackouts').innerText()).includes('SMOKE Closed for a wedding'), 'a blackout date added: it is in the list');
  await widthOK(page, 'the types screen after a blackout', 375); await touch(page, 'the types screen after a blackout');
  await page.click('#time-off-types-add-btn');
  await page.fill('#time-off-type-form-field-name', 'SMOKE Jury duty');
  await page.check('#time-off-type-form-field-paid');
  await page.click('#time-off-type-form-save-btn');
  await page.waitForURL(/#type-\d+$/, { timeout: 8000 });
  const t = rows(`SELECT paid, tracks_balance FROM time_off_types WHERE name = 'SMOKE Jury duty'`)[0];
  ok(t && t.paid === true && t.tracks_balance === false, 'a new kind added from the phone (paid, no balance)');
  await shot(page, 'phone-types-after');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' | ') : ''));
  await ctx.close();
}

console.log('1280 x 800 — the desktop layout');
{
  const { ctx, page, errors } = await session(26, null, DESK);
  await go(page, '/availability');
  const cols = await page.evaluate(() => [...document.querySelectorAll('#availability-week > div')].slice(0, 2).map((e) => Math.round(e.getBoundingClientRect().left)));
  ok(cols[0] !== cols[1], 'two days sit side by side at 1280 px (' + cols.join(' / ') + ')');
  const sb = await page.evaluate(() => { const c = document.getElementById('availability-content'); return c.scrollWidth <= c.clientWidth; });
  ok(sb, 'no sideways scroll inside the availability content');
  await go(page, '/time-off/balances');
  const cards = await page.evaluate(() => [...document.querySelectorAll('[id^=balance-card-]')].filter((e) => /^balance-card-\d+$/.test(e.id)).map((e) => Math.round(e.getBoundingClientRect().left)));
  ok(cards.length >= 2 && cards[0] !== cards[1], 'the balance cards sit two across at 1280 px (' + cards.join(' / ') + ')');
  const tb = await page.evaluate(() => [...document.querySelectorAll('#page-content .table-responsive')].map((e) => [e.scrollWidth, e.clientWidth]));
  ok(tb.every(([s, c]) => s <= c), 'the ledger tables need no scrolling at 1280 px ' + JSON.stringify(tb));
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' | ') : ''));
  await ctx.close();
}
await browser.close();
console.log(`${failed ? failed + ' FAILED' : 'all'} (${passed} passed)`);
process.exit(failed ? 1 : 0);
