// Headless Chromium proof of slice 2 (docs/build-specs/week-builder.md, "Proof — drag and buttons agree", "375 px and 1280 px"): the phone builder is the day-tab list, the grid scrolls
// inside its card with a visible scrollbar at 1280, no sideways scroll on any screen of the slice, every button at least 44 px tall at 375, a REAL drag posts shift_update / shift_assign
// and a refused drop springs back with its sentence, "Move to…" does the same without a drag, the live check on the form, JavaScript off still renders the builder.
// Run through tests/phase3/slice2/run.sh (servers up). Playwright comes from the kernel's web/node_modules (read only).
import { createRequire } from 'node:module';
import crypto from 'node:crypto';
import { execFileSync } from 'node:child_process';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8191';
const SHOTS = process.env.SHOTS || '/tmp/txtschedules-shots-p3s2';
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
const browser = await chromium.launch({ ignoreDefaultArgs: ['--hide-scrollbars'] });     // a real (classic) scrollbar, as a person has it
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
const widthOK = async (page, label, vw) => { const o = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth, iw: window.innerWidth })); ok(o.sw <= o.cw && o.iw === vw, `no sideways scroll on ${label} (scrollWidth ${o.sw} <= ${o.cw} of ${o.iw})`); };
const touch = async (page, label) => {
  const small = await page.evaluate(() => [...document.querySelectorAll('#page-content a.btn, #page-content button.btn, #page-content summary.btn, #page-content .week-chip, #page-content select.form-select, #page-content input.form-control[type=text], #page-content input.form-control[type=date], #page-content input.form-control[type=time], #app-tabbar .app-tab')]
    .filter((e) => { const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return cs.display !== 'none' && cs.visibility !== 'hidden' && r.width > 0 && r.height < 43.5 && !e.closest('details:not([open]) > :not(summary)'); })
    .map((e) => (e.id || e.className) + ':' + Math.round(e.getBoundingClientRect().height)));
  ok(small.length === 0, `every button and control is at least 44 px tall on ${label}` + (small.length ? ' — too small: ' + small.join(', ') : ''));
};
const go = async (page, path) => { await page.goto(BASE + path, { waitUntil: 'networkidle' }); };
const shot = (page, name, full = false) => page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: full });
// a drag as a person makes it: press on the block, move in steps, let go over the cell
const drag = async (page, src, dst) => {
  const a = await page.locator(src).boundingBox();
  await page.mouse.move(a.x + a.width / 2, a.y + a.height / 2);
  await page.mouse.down();
  await page.mouse.move(a.x + a.width / 2 + 6, a.y + a.height / 2 + 6, { steps: 3 });
  for (let i = 0; i < 4; i++) {                                     // the cell moves under the hand while the block hovers: follow it, as a person does
    const b = await page.locator(dst).boundingBox();
    await page.mouse.move(b.x + b.width / 2, b.y + b.height / 2, { steps: 2 });
    await page.mouse.move(b.x + b.width / 2 + 2, b.y + b.height / 2 + 2, { steps: 2 });
  }
  await page.mouse.up();
};
// a drag the server accepts: the builder is re-fetched (its grid element is replaced) and re-armed
const dragOK = async (page, src, dst) => {
  await page.evaluate(() => { window.__grid = document.getElementById('builder-grid'); });
  await drag(page, src, dst);
  await page.waitForFunction(() => { const g = document.getElementById('builder-grid'); return g && g !== window.__grid && g.dataset.ready === '1'; }, null, { timeout: 8000 });
};
const settle = async (page) => { await page.waitForLoadState('networkidle'); await page.waitForTimeout(150); };

// ================= 375 x 740 =================
console.log('375 x 740 — Mara (manager)');
{
  const { ctx, page, errors } = await session(33, null, PHONE);
  await go(page, `/builder?site=102&week=${I.b1}`);
  await widthOK(page, 'the builder', 375); await touch(page, 'the builder');
  const vis = await page.evaluate(() => ({ grid: getComputedStyle(document.getElementById('builder-grid-card')).display, phone: getComputedStyle(document.getElementById('builder-phone')).display, chips: document.querySelectorAll('#builder-phone-chips .week-chip').length }));
  ok(vis.grid === 'none' && vis.phone !== 'none' && vis.chips === 7, 'the phone builder is the day-tab list: no grid, seven day tabs');
  ok((await page.locator('#builder-state').innerText()) === 'Draft', 'the week\'s state badge says Draft');
  await shot(page, 'phone-builder');
  await page.click('#builder-day-' + I.b1.slice(0, 8) + String(Number(I.b1.slice(8)) + 3).padStart(2, '0')).catch(() => {});
  await go(page, `/builder?site=102&week=${I.b1}&day=${new Date(new Date(I.b1 + 'T12:00:00Z').getTime() + 3 * 86400000).toISOString().slice(0, 10)}`);
  const warn = await page.locator(`#builder-list-shift-${I.priyaThu} .text-warning`).count();
  ok(warn >= 1, 'Thursday\'s list shows Priya\'s shift with its warning sentence in words');
  await shot(page, 'phone-builder-thursday');
  await widthOK(page, 'the builder (Thursday)', 375);
  // Move to… on the phone
  const sunday = new Date(new Date(I.b1 + 'T12:00:00Z').getTime() + 6 * 86400000).toISOString().slice(0, 10);
  await go(page, `/builder?site=102&week=${I.b1}&day=${sunday}`);
  await page.click(`#builder-list-move-${I.moveMe}-open`);
  await page.evaluate(() => window.scrollTo(0, 0));
  await shot(page, 'phone-builder-move');
  const tue = new Date(new Date(I.b1 + 'T12:00:00Z').getTime() + 1 * 86400000).toISOString().slice(0, 10);
  await page.selectOption(`#builder-list-move-${I.moveMe}-day`, tue);
  await page.click(`#builder-list-move-${I.moveMe}-save-btn`);
  await page.waitForURL(/\/shifts\/\d+$/);
  const moved = rows(`SELECT (starts_at AT TIME ZONE 'America/Chicago')::date::text AS d, to_char(starts_at AT TIME ZONE 'America/Chicago', 'HH24:MI') AS t FROM shifts WHERE id = ${I.moveMe}`)[0];
  ok(moved.d === tue && moved.t === '12:00', 'Move to… (a day, no drag): the shift moved to Tuesday and kept its time, and the page landed on the shift');
  await widthOK(page, 'the shift (manager)', 375); await touch(page, 'the shift (manager)');
  await shot(page, 'phone-shift-manage', true);
  // Add a shift from the phone, with the live check
  await go(page, `/builder?site=102&week=${I.b4}`);
  await page.click('#builder-phone-add');
  await page.waitForSelector('#shift-form');
  await widthOK(page, 'the shift form', 375); await touch(page, 'the shift form');
  await page.selectOption('#shift-form-field-assignee', '30');
  await page.waitForSelector('#shift-form-check-clear');
  ok(await page.locator('#shift-form-check-clear').isVisible(), 'the live check: choosing a person says "No rule is broken."');
  await page.selectOption('#shift-form-field-position', String(rows(`SELECT id FROM positions WHERE scope_id = 102 AND name = 'Bar'`)[0].id));
  await page.selectOption('#shift-form-field-assignee', '26');
  await page.waitForSelector('#shift-form-check-hard-0', { timeout: 5000 });
  ok((await page.locator('#shift-form-check-hard-0').innerText()).includes('Does not work this position here.') && await page.locator('#shift-form-save-btn').isDisabled(), 'Priya on Bar: the hard sentence is listed and Save is disabled');
  await shot(page, 'phone-shift-form-hard');
  await page.selectOption('#shift-form-field-position', String(rows(`SELECT id FROM positions WHERE scope_id = 102 AND name = 'Server'`)[0].id));
  await page.selectOption('#shift-form-field-assignee', '30');
  await page.fill('#shift-form-field-note', 'SMOKE from the phone');
  await page.waitForSelector('#shift-form-check-clear');
  ok(await page.locator('#shift-form-save-btn').isEnabled(), 'fixed: Save is enabled again');
  await page.click('#shift-form-save-btn');
  await page.waitForURL(/\/shifts\/\d+$/);
  const created = rows(`SELECT id, note, assignee_member_id FROM shifts WHERE note = 'SMOKE from the phone'`)[0];
  ok(created && Number(created.assignee_member_id) === 30 && page.url().endsWith('/shifts/' + created.id), 'saved: the page landed on /shifts/{id} of the new shift');
  // a soft warning asks for the reason on the form
  await go(page, `/shifts/new?site=102&date=${new Date(new Date(I.b1 + 'T12:00:00Z').getTime() + 3 * 86400000).toISOString().slice(0, 10)}`);
  await page.selectOption('#shift-form-field-assignee', '26');
  await page.fill('#shift-form-field-date', new Date(new Date(I.b1 + 'T12:00:00Z').getTime() + 2 * 86400000).toISOString().slice(0, 10));
  await page.fill('#shift-form-field-starts', '06:00');
  await page.fill('#shift-form-field-ends', '11:00');
  await page.dispatchEvent('#shift-form-field-ends', 'change');
  await page.waitForSelector('#shift-form-field-override-reason', { timeout: 5000 }).catch(() => {});
  ok(await page.locator('#shift-form-field-override-reason').count() === 1, 'a soft warning: the reason box appears under the person');
  await shot(page, 'phone-shift-form-soft', true);
  // publish page
  await go(page, `/weeks/publish-confirm?site=102&week=${I.b1}`);
  await widthOK(page, 'the publish page', 375); await touch(page, 'the publish page');
  ok(await page.locator('#publish-form-field-override-reason').count() === 1 && (await page.locator('#publish-summary-shifts').innerText()) !== '0', 'the publish page lists the warnings and asks for the reason');
  await shot(page, 'phone-publish', true);
  await go(page, `/builder/day?site=102&date=${new Date(new Date(I.b1 + 'T12:00:00Z').getTime() + 4 * 86400000).toISOString().slice(0, 10)}`);
  await widthOK(page, 'the day view', 375); await touch(page, 'the day view'); await shot(page, 'phone-day-view');
  await go(page, `/templates/?site=102&week=${I.b4}`); await widthOK(page, 'the templates list', 375); await touch(page, 'the templates list'); await shot(page, 'phone-templates');
  await go(page, `/templates/${I.template}?week=${I.b4}`); await widthOK(page, 'the template', 375); await touch(page, 'the template'); await shot(page, 'phone-template-view', true);
  await go(page, `/builder?site=102&week=${I.b3}`);
  await widthOK(page, 'a published week', 375);
  ok((await page.locator('#builder-state').innerText()).includes('changed after') && await page.locator('#builder-live-note').isVisible(), 'a published week: "Published — changed after" and the live note');
  ok(await page.locator('#builder-copy-form').count() === 0 && await page.locator('#builder-publish-btn').count() === 0, 'and no Copy, Auto-fill or Publish on it');
  await shot(page, 'phone-builder-live');
  ok(errors.length === 0, 'no console errors on the phone' + (errors.length ? ': ' + errors.join(' | ') : ''));
  await ctx.close();
}

// ================= 1280 x 800 =================
console.log('1280 x 800 — Mara (manager)');
{
  const { ctx, page, errors } = await session(33, null, DESK);
  await go(page, `/builder?site=102&week=${I.b1}`);
  await widthOK(page, 'the builder grid', 1280);
  const g = await page.evaluate(() => { const s = document.getElementById('builder-grid-scroll'); return { sw: s.scrollWidth, cw: s.clientWidth, ov: getComputedStyle(s).overflowX, bar: s.offsetHeight - s.clientHeight, phone: getComputedStyle(document.getElementById('builder-phone')).display, card: getComputedStyle(document.getElementById('builder-grid-card')).display }; });
  ok(g.card !== 'none' && g.phone === 'none', 'at 1280 the grid shows and the phone list does not');
  ok(g.sw > g.cw && g.ov === 'auto' && g.bar >= 8, `the grid scrolls INSIDE its card (${g.sw} px of grid in ${g.cw} px) with a visible scrollbar (${g.bar} px), never the page`);
  const cells = await page.locator('#builder-grid .grid-cell').count();
  ok(cells === 7 * (1 + (await page.locator('#builder-grid tbody tr').count() - 1)), `every row has seven day cells (${cells})`);
  const hoursRow = await page.locator('#builder-hours-30').innerText();
  ok(/\d+(\.\d+)? \/ 40 h/.test(hoursRow), 'a person\'s row ends with hours against the threshold: "' + hoursRow.replace(/\n/g, ' ') + '"');
  await shot(page, 'desktop-builder');
  await page.click('#builder-filter-view').catch(() => {});
  await go(page, `/builder?site=102&week=${I.b1}&view=positions`); await widthOK(page, 'the builder by position', 1280); await shot(page, 'desktop-builder-positions');
  await go(page, `/builder/day?site=102&date=${new Date(new Date(I.b1 + 'T12:00:00Z').getTime() + 4 * 86400000).toISOString().slice(0, 10)}`); await widthOK(page, 'the day view', 1280); await shot(page, 'desktop-day-view');
  await go(page, `/weeks/publish-confirm?site=102&week=${I.b1}`); await widthOK(page, 'the publish page', 1280); await shot(page, 'desktop-publish');
  await go(page, `/shifts/${I.priyaThu}/edit`); await widthOK(page, 'the shift form', 1280); await shot(page, 'desktop-shift-form');
  await go(page, `/templates/${I.template}`); await widthOK(page, 'the template', 1280); await shot(page, 'desktop-template');
  await go(page, `/builder?site=102&week=${I.b3}`); await shot(page, 'desktop-builder-live');

  // real drags on the drag week
  await go(page, `/builder?site=102&week=${I.b2}`);
  await page.waitForFunction(() => document.getElementById('builder-grid').dataset.ready === '1');
  const dayOf = (n) => new Date(new Date(I.b2 + 'T12:00:00Z').getTime() + n * 86400000).toISOString().slice(0, 10);
  const cell = (member, day) => `#builder-cell-m${member}-${day}`;
  await page.setViewportSize({ width: 1280, height: 1500 });        // the whole grid in view, so the drag needs no scrolling
  await go(page, `/builder?site=102&week=${I.b2}`);
  await page.waitForFunction(() => document.getElementById('builder-grid').dataset.ready === '1');
  await dragOK(page, `#builder-shift-${I.dragDay}`, cell(30, dayOf(1)));
  await page.waitForSelector(`${cell(30, dayOf(1))} #builder-shift-${I.dragDay}`);
  await settle(page);
  let r = rows(`SELECT (starts_at AT TIME ZONE 'America/Chicago')::date::text AS d, to_char(starts_at AT TIME ZONE 'America/Chicago', 'HH24:MI') AS t, assignee_member_id FROM shifts WHERE id = ${I.dragDay}`)[0];
  ok(r.d === dayOf(1) && r.t === '17:00' && Number(r.assignee_member_id) === 30, 'a REAL drag of Ana\'s Monday shift onto Tuesday posts shift_update: it is Tuesday 17:00, still Ana\'s');
  ok((await page.locator(`${cell(30, dayOf(1))} #builder-shift-${I.dragDay}`).count()) === 1, 'and the page shows the block in Tuesday\'s cell after the refresh');
  const lg = rows(`SELECT action, source FROM activity_log WHERE entity_type = 'shift' AND entity_id = ${I.dragDay} ORDER BY id DESC LIMIT 1`)[0];
  ok(lg.action === 'shift.update' && lg.source === 'web', 'the log says shift.update from the web (the same action the buttons post)');
  await dragOK(page, `#builder-shift-${I.dragPerson}`, cell(26, dayOf(2)));
  await page.waitForSelector(`${cell(26, dayOf(2))} #builder-shift-${I.dragPerson}`);
  await settle(page);
  r = rows(`SELECT assignee_member_id FROM shifts WHERE id = ${I.dragPerson}`)[0];
  ok(Number(r.assignee_member_id) === 26, 'dragging Lee\'s Wednesday shift onto Priya\'s Wednesday cell posts shift_assign: it is Priya\'s');
  const lg2 = rows(`SELECT action, after FROM activity_log WHERE entity_type = 'shift' AND entity_id = ${I.dragPerson} ORDER BY id DESC LIMIT 1`)[0];
  ok(lg2.action === 'shift.assign', 'the log says shift.assign');
  await dragOK(page, `#builder-shift-${I.dragOpen}`, cell(32, dayOf(4)));
  await page.waitForSelector(`${cell(32, dayOf(4))} #builder-shift-${I.dragOpen}`, { timeout: 4000 });
  await settle(page);
  r = rows(`SELECT assignee_member_id FROM shifts WHERE id = ${I.dragOpen}`)[0];
  ok(Number(r.assignee_member_id) === 32, 'an open shift dragged onto Dana\'s row is hers');
  await dragOK(page, `#builder-shift-${I.dragOpen}`, '#builder-cell-open-' + dayOf(4));
  await page.waitForSelector(`#builder-cell-open-${dayOf(4)} #builder-shift-${I.dragOpen}`, { timeout: 4000 });
  await settle(page);
  r = rows(`SELECT assignee_member_id FROM shifts WHERE id = ${I.dragOpen}`)[0];
  ok(r.assignee_member_id === null, 'and back onto the Open shifts row leaves it open again');
  // a refused drop: Ana's Bar shift onto Dana's cell (Dana works Server, not Bar)
  const before = rows(`SELECT assignee_member_id FROM shifts WHERE id = ${I.dragRefused}`)[0];
  await drag(page, `#builder-shift-${I.dragRefused}`, cell(32, dayOf(3)));
  await page.waitForSelector('#builder-drop-message', { timeout: 5000 });
  const msg = await page.locator('#builder-drop-message').innerText();
  ok(msg.includes('Does not work this position here.'), 'a refused drop says the sentence: "' + msg + '"');
  ok((await page.locator(`${cell(30, dayOf(3))} #builder-shift-${I.dragRefused}`).count()) === 1, 'and the block springs back to where it was');
  const after = rows(`SELECT assignee_member_id FROM shifts WHERE id = ${I.dragRefused}`)[0];
  ok(after.assignee_member_id === before.assignee_member_id, 'nothing was saved');
  await shot(page, 'desktop-drop-refused');
  // a published week is not draggable
  await go(page, `/builder?site=102&week=${I.b3}`);
  ok((await page.locator('#builder-grid[data-sortable]').count()) === 0 && (await page.locator('.shift-block[data-draggable]').count()) === 0, 'a published week\'s grid is not draggable (its changes are Change / Cancel, which tell staff)');
  const real = errors.filter((e) => !e.includes('status of 422'));       // the refused drop is a 422 the browser logs; anything else is an error
  ok(real.length === 0, 'no console errors at 1280 (the refused drop\'s 422 aside)' + (real.length ? ': ' + real.join(' | ') : ''));
  await ctx.close();
}

// ================= JavaScript off =================
console.log('JavaScript off');
{
  const { ctx, page } = await session(33, null, PHONE, { javaScriptEnabled: false });
  await go(page, `/builder?site=102&week=${I.b1}`);
  ok(await page.locator('#builder-phone-chips .week-chip').count() === 7 && await page.locator(`#builder-list-shift-${I.ana}`).count() + await page.locator('#builder-phone-title').count() >= 1, 'the phone builder renders with JavaScript off (day tabs are plain links)');
  const href = await page.locator('#builder-day-' + new Date(new Date(I.b1 + 'T12:00:00Z').getTime() + 3 * 86400000).toISOString().slice(0, 10)).getAttribute('href');
  await go(page, href);                                   // (a plain href — followed the way the browser follows a link)
  ok(page.url().includes('day=') && (await page.locator(`#builder-list-shift-${I.priyaThu}`).count()) === 1, 'a day tab is a real link: Thursday\'s list loads');
  const sat = new Date(new Date(I.b1 + 'T12:00:00Z').getTime() + 5 * 86400000).toISOString().slice(0, 10);
  const wed = new Date(new Date(I.b1 + 'T12:00:00Z').getTime() + 2 * 86400000).toISOString().slice(0, 10);
  await go(page, `/builder?site=102&week=${I.b1}&day=${sat}`);
  const mid = I.openSat;
  await page.locator(`#builder-list-move-${mid}-open`).click();
  await page.selectOption(`#builder-list-move-${mid}-day`, wed);
  await page.locator(`#builder-list-move-${mid}-reason`).press('Enter');                      // a plain form: Enter in its last field submits it
  await page.waitForURL(/\/shifts\/\d+$/);
  const r = rows(`SELECT (starts_at AT TIME ZONE 'America/Chicago')::date::text AS d FROM shifts WHERE id = ${mid}`)[0];
  ok(r.d === wed, 'Move to… works without any JavaScript: a plain form post (with its CSRF field) moved the shift to Wednesday');
  await go(page, `/builder?site=102&week=${I.b1}`);
  ok(await page.locator('#builder-grid').count() === 1 && (await page.locator('#builder-grid').getAttribute('data-ready')) === null, 'the grid is in the page but read-only (the drag was never wired)');
  await ctx.close();
}
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
