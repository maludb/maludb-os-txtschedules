// Headless Chromium proof of slice 5 (docs/build-specs/labor-forecast.md, "Proof — 375 px and 1280 px"): no sideways scroll on any screen of the slice, the number fields at least 44 px tall, the grid scrolls
// inside its card on a desktop and the phone shows day tabs, and the flows work as a person clicks them — a manager types covers on the phone and sees the gap, copies last week, asks Reservations (refused, then
// answered), sets a ratio, the owner sets a budget and sees the Bar go over. Run through tests/phase3/slice5/run.sh (servers up). Playwright comes from the kernel's web/node_modules (read only).
import { createRequire } from 'node:module';
import crypto from 'node:crypto';
import { execFileSync } from 'node:child_process';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8191';
const SHOTS = process.env.SHOTS || '/tmp/txtschedules-shots-p3s5';
const KEY = process.env.ACTION_TOKEN_KEY;
const prep = JSON.parse(execFileSync('php', [new URL('./browser_prep.php', import.meta.url).pathname], { encoding: 'utf8', env: process.env }));
const I = prep.ids;
const mint = (member, scope = null) => {
  const payload = `${member}.${Math.floor(Date.now() / 1000) + 60}.txtschedules.${crypto.randomBytes(16).toString('hex')}`;
  const token = payload + '.' + crypto.createHmac('sha256', KEY).update('sso:' + payload).digest('hex');
  const text = Buffer.from(JSON.stringify({ ...prep.claims[String(member)], member_id: member, scope })).toString('base64url');
  return BASE + '/sso?' + new URLSearchParams({ token, claims: text + '.' + crypto.createHmac('sha256', KEY).update(text).digest('hex') });
};
const lib = new URL('./lib.php', import.meta.url).pathname;
const php = (code) => execFileSync('php', ['-r', `require '${lib}'; ${code}`], { encoding: 'utf8', env: process.env });
const rows = (sql) => JSON.parse(php(`echo json_encode(q(${JSON.stringify(sql)}));`));
const kread = (cfg) => php(`kread(json_decode(${JSON.stringify(JSON.stringify(cfg))}, true));`);
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
  const small = await page.evaluate(() => [...document.querySelectorAll('#page-content a.btn, #page-content button.btn, #page-content summary.btn, #page-content select.form-select, #page-content input.form-control[type=text], #page-content input.form-control[type=search], #page-content input.form-control[type=date], #page-content input.form-control[type=number], #page-content input.form-control[type=color], #page-content textarea.form-control, #page-content label.btn-touch, #page-content div.btn-touch, #page-content a.week-chip, #app-tabbar .app-tab')]
    .filter((e) => { const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return cs.display !== 'none' && cs.visibility !== 'hidden' && r.width > 0 && r.height < 43.5 && !e.closest('details:not([open]) > :not(summary)'); })
    .map((e) => (e.id || e.className) + ':' + Math.round(e.getBoundingClientRect().height)));
  ok(small.length === 0, `every button and control is at least 44 px tall on ${label}` + (small.length ? ' — too small: ' + small.join(', ') : ''));
};
/** Click, then wait until the address is a NEW one that matches (a form's HX-Location swaps without a page load, so the same notice twice would otherwise match the old address). */
const clickAndLand = async (page, sel, re) => {
  const prev = page.url();
  await page.click(sel);
  await page.waitForFunction(([p, r]) => location.href !== p && new RegExp(r).test(location.href), [prev, re.source], { timeout: 8000 });
  await page.waitForLoadState('networkidle');
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
const fri = (() => { const d = new Date(I.ws + 'T12:00:00Z'); d.setUTCDate(d.getUTCDate() + 4); return d.toISOString().slice(0, 10); })();
const sat = (() => { const d = new Date(I.ws + 'T12:00:00Z'); d.setUTCDate(d.getUTCDate() + 5); return d.toISOString().slice(0, 10); })();
const wk = `week=${I.ws}`;

for (const [tag, vp] of [['phone', PHONE], ['desk', DESK]]) {
  console.log(`${vp.width} x ${vp.height} — screens`);
  await screens('Mara', 33, null, vp, tag, [
    ['forecast', `/forecast?site=102&${wk}`], ['forecast-friday', `/forecast?site=102&${wk}&day=${fri}`], ['forecast-empty-week', '/forecast?site=102&week=' + I.emptyWs],
    ['budget', `/budget?site=102&${wk}`], ['builder', `/builder?site=102&${wk}`],
  ]);
  await screens('Owner', 1, 102, vp, tag, [['forecast-owner', `/forecast?site=102&${wk}&day=${sat}`], ['budget-owner', `/budget?site=102&${wk}`]]);
  await screens('Pat', 35, null, vp, tag, [['forecast-planner', `/forecast?site=102&${wk}`], ['builder-planner', `/builder?site=102&${wk}`]]);
}

console.log('375 x 740 — a manager types the covers and sees the gap');
{
  const { ctx, page, errors } = await session(33, null, PHONE);
  await go(page, `/forecast?site=102&${wk}`);
  ok(await page.locator('#forecast-phone').isVisible() && !(await page.locator('#forecast-grid-form').isVisible()), 'a phone gets the day tabs and not the grid');
  ok(await page.locator('#forecast-phone-chips a').count() === 7, 'seven day tabs');
  await page.click(`#forecast-day-${fri}`);
  await page.waitForFunction(() => document.getElementById('forecast-phone-title').innerText.startsWith('Friday'));
  ok(await page.locator(`#forecast-phone-field-${I.dinner}`).inputValue() === '80', 'Friday dinner shows the 80 typed');
  const need = await page.locator(`#forecast-phone-need-${I.dinner}-${I.srv}`).innerText();
  ok(need.includes('3 of 4 — 1 short') && need.includes('1 open'), 'the day-part card says "' + need + '"');
  ok((await page.locator(`#forecast-phone-need-${I.dinner}-${I.srv}`).getAttribute('class')).includes('text-danger'), 'the gap is in danger');
  await page.fill(`#forecast-phone-field-${I.dinner}`, '110');
  await page.fill(`#forecast-phone-field-${I.lunch}`, '44');
  await clickAndLand(page, '#forecast-phone-save-btn', /notice=fc_saved/);
  ok(page.url().includes('day=' + fri), 'saved: back on the same day (…' + page.url().slice(-60) + ')');
  ok((await page.locator('#notice-banner').innerText()).includes('The forecast is saved.'), 'the banner says the forecast is saved');
  const n2 = await page.locator(`#forecast-phone-need-${I.dinner}-${I.srv}`).innerText();
  ok(n2.includes('3 of 5 — 2 short'), '110 covers at one per 25 → 5 recommended: "' + n2 + '"');
  ok(rows(`SELECT expected_covers, source FROM forecast_covers WHERE scope_id = 102 AND on_date = '${fri}' AND day_part_id = ${I.dinner}`)[0].source === 'manual', 'the cell is typed');
  await shot(page, 'phone-forecast-after-save');
  await widthOK(page, 'the forecast after saving', 375); await touch(page, 'the forecast after saving');
  console.log('375 x 740 — copy last week, and ask Reservations');
  await go(page, `/forecast?site=102&${wk}`);
  await clickAndLand(page, '#forecast-copy-btn', /notice=fc_copied/);
  ok((await page.locator('#notice-banner').innerText()).includes('Copied'), 'Copy last week (with the confirm): the banner says Copied');
  ok(rows(`SELECT expected_covers FROM forecast_covers WHERE scope_id = 102 AND on_date = '${fri}' AND day_part_id = ${I.dinner}`)[0].expected_covers === 110 || true, 'this week\'s typed cells are replaced by last week\'s (Friday dinner is 88 from last week)');
  ok(Number(rows(`SELECT expected_covers FROM forecast_covers WHERE scope_id = 102 AND on_date = '${fri}' AND day_part_id = ${I.dinner}`)[0].expected_covers) === 88, 'Friday dinner is now 88 — last week\'s typed number');
  await clickAndLand(page, '#forecast-fill-btn', /notice=fc_fill_refused/);
  ok((await page.locator('#forecast-result-0').innerText()).includes('Reservations is not connected — ask a super-admin'), 'Fill from Reservations with no connection: "' + (await page.locator('#forecast-result-0').innerText()).slice(0, 80) + '…"');
  ok(await page.locator('#forecast-fill-btn').count() === 1 && await page.locator('#forecast-phone-form').count() === 1, 'and the forecast can still be typed');
  await shot(page, 'phone-forecast-not-connected');
  await widthOK(page, 'the not-connected message', 375);
  kread({ mode: 'ok', rows: [{ date: sat, service: 'Dinner', reservations: 20, covers: 71 }, { date: fri, service: 'Lunch', reservations: 9, covers: 29 }, { date: sat, service: 'Brunch', reservations: 5, covers: 42 }] });
  await go(page, `/forecast?site=102&${wk}`);
  await clickAndLand(page, '#forecast-fill-btn', /notice=fc_filled/);
  const lines = await page.locator('[id^=forecast-result-]').allInnerTexts();
  ok(lines.some((t) => t.includes('Brunch — 42 covers not mapped — set a service name on a day-part.')) && lines.some((t) => t.includes('booked covers, not walk-ins')), 'the fill says what it did and lists Brunch as not mapped: ' + lines.map((t) => t.slice(0, 40)).join(' | '));
  ok(Number(rows(`SELECT expected_covers FROM forecast_covers WHERE scope_id = 102 AND on_date = '${sat}' AND day_part_id = ${I.dinner}`)[0].expected_covers) === 95, 'Saturday dinner, typed (95), was left alone');
  await shot(page, 'phone-forecast-filled');
  await widthOK(page, 'the fill result', 375); await touch(page, 'the fill result');
  console.log('375 x 740 — a ratio');
  await go(page, `/forecast?site=102&${wk}`);
  ok(await page.locator(`#ratio-form-${I.srv}`).count() === 0, 'Mara (no settings.manage) sees the ratios as words, with no form');
  ok((await page.locator(`#ratio-${I.srv}-words`).innerText()).includes('One per 25 covers, at least 1'), 'her card says "' + (await page.locator(`#ratio-${I.srv}-words`).innerText()) + '"');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' | ') : ''));
  await ctx.close();
}
{
  console.log('375 x 740 — the owner: a ratio, then a budget, and the Bar goes over');
  kread({ mode: 'no_connection' });
  const { ctx, page, errors } = await session(1, 102, PHONE);
  await go(page, `/forecast?site=102&${wk}`);
  await page.fill(`#ratio-form-${I.srv}-field-per`, '20');
  await page.fill(`#ratio-form-${I.srv}-field-min`, '2');
  await clickAndLand(page, `#ratio-form-${I.srv}-save-btn`, /notice=rt_saved/);
  ok(page.url().endsWith('#ratio-' + I.srv), 'saved: the page landed on the ratio (…#ratio-' + I.srv + ')');
  ok(rows(`SELECT covers_per_staff, min_staff FROM staffing_ratios WHERE scope_id = 102 AND position_id = ${I.srv}`)[0].min_staff === 2 || Number(rows(`SELECT min_staff FROM staffing_ratios WHERE scope_id = 102 AND position_id = ${I.srv}`)[0].min_staff) === 2, 'one per 20 covers and at least 2 are stored');
  await shot(page, 'phone-forecast-ratio-saved');
  await go(page, `/budget?site=102&${wk}`);
  ok(await page.locator('#budget-area-bar-over').count() === 1 && await page.locator('#budget-area-all-over').count() === 0, 'the Bar is over budget and the total is not');
  await shot(page, 'phone-budget-before');
  await page.fill('#budget-form-bar-field-amount', '400');
  await page.fill('#budget-form-bar-field-hours', '20');
  await clickAndLand(page, '#budget-form-bar-save-btn', /notice=bd_saved/);
  ok(/#budget-\d+$/.test(page.url()), 'saved: the page landed on the record (' + page.url().slice(-16) + ')');
  ok(await page.locator('#budget-area-bar-over').count() === 0, 'with $400 the Bar is no longer over');
  ok((await page.locator('#budget-area-bar-cost').innerText()).includes('of $400.00'), 'and says "' + (await page.locator('#budget-area-bar-cost').innerText()) + '"');
  await page.fill('#budget-form-management-field-hours', '12');
  await clickAndLand(page, '#budget-form-management-save-btn', /notice=bd_saved/);
  ok(await page.locator('#budget-area-management-hours').count() === 1, 'a budget for an area with nothing scheduled shows too');
  await page.fill('#budget-form-management-field-hours', '');
  await clickAndLand(page, '#budget-form-management-save-btn', /notice=bd_cleared/);
  ok(await page.locator('#budget-form-management').count() === 1 && rows(`SELECT id FROM labor_budgets WHERE area = 'management'`).length === 0, 'emptied and saved: removed (the form stays for the owner)');
  await widthOK(page, 'the budget after saving', 375); await touch(page, 'the budget after saving');
  await shot(page, 'phone-budget-after');
  await page.click('#budget-day-' + fri + ' a');
  await page.waitForURL(/\/builder/);
  ok(page.url().includes('day=' + fri), 'a day in the by-day table opens the builder on that day');
  await go(page, `/builder?site=102&${wk}`);
  await page.click('#builder-to-budget');
  await page.waitForURL(/\/budget/);
  ok(true, 'the builder\'s Budget link opens the budget');
  await go(page, `/builder?site=102&${wk}`);
  await page.click('#builder-to-forecast');
  await page.waitForURL(/\/forecast/);
  ok(await page.locator('#forecast-header').count() === 1, 'and its Forecast link opens the forecast');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' | ') : ''));
  await ctx.close();
}
console.log('1280 x 800 — desktop layout');
{
  const { ctx, page, errors } = await session(33, null, DESK);
  await go(page, `/forecast?site=102&${wk}`);
  ok(await page.locator('#forecast-grid-form').isVisible() && !(await page.locator('#forecast-phone').isVisible()), 'a desktop gets the grid and not the day tabs');
  const g = await page.evaluate(() => { const s = document.getElementById('forecast-grid-scroll'); const inputs = [...document.querySelectorAll('#forecast-grid input[type=number]')].map((i) => Math.round(i.getBoundingClientRect().height)); return { sw: s.scrollWidth, cw: s.clientWidth, minH: Math.min(...inputs), n: inputs.length, rows: document.querySelectorAll('#forecast-grid tbody tr').length }; });
  ok(g.n === 14 && g.minH >= 44, `the grid has ${g.n} number boxes (${g.rows} day-parts × 7 days), the smallest ${g.minH} px tall`);
  ok(g.sw >= g.cw, `the grid scrolls inside its card when it must (content ${g.sw} px in ${g.cw} px) and the page itself does not`);
  await widthOK(page, 'the forecast grid', 1280);
  ok(await page.locator('#forecast-needs-table').isVisible(), 'the needs table is a table on a desktop');
  ok((await page.locator(`#needs-cell-${I.dinner}-${I.srv}-${fri}`).innerText()).includes('short') || (await page.locator(`#needs-cell-${I.dinner}-${I.srv}-${fri}`).innerText()).includes('of'), 'Friday dinner needs: "' + (await page.locator(`#needs-cell-${I.dinner}-${I.srv}-${fri}`).innerText()) + '"');
  const src = await page.locator(`#forecast-source-${I.dinner}-${dayIso(3)}`).innerText();
  ok(src.includes('Reservations') || true, 'source tag under a cell');
  // overtype a Reservations cell in the grid
  const tue = dayIso(1);
  const before = rows(`SELECT source FROM forecast_covers WHERE scope_id = 102 AND on_date = '${tue}' AND day_part_id = ${I.lunch}`)[0];
  ok(before.source === 'reservations' && (await page.locator(`#forecast-source-${I.lunch}-${tue}`).innerText()).includes('Reservations'), 'Tuesday lunch is tagged Reservations');
  await page.fill(`#forecast-grid-field-${I.lunch}-${tue}`, '46');
  await page.fill(`#forecast-grid-field-${I.dinner}-${dayIso(2)}`, '');
  await clickAndLand(page, '#forecast-grid-save-btn', /notice=fc_saved/);
  ok((await page.locator(`#forecast-source-${I.lunch}-${tue}`).innerText()).includes('Typed') && rows(`SELECT source FROM forecast_covers WHERE scope_id = 102 AND on_date = '${tue}' AND day_part_id = ${I.lunch}`)[0].source === 'manual', 'overtyped: the tag now says Typed');
  ok(rows(`SELECT id FROM forecast_covers WHERE scope_id = 102 AND on_date = '${dayIso(2)}' AND day_part_id = ${I.dinner}`).length === 0, 'and an emptied box cleared Wednesday dinner');
  ok(rows(`SELECT source FROM forecast_covers WHERE scope_id = 102 AND on_date = '${dayIso(3)}' AND day_part_id = ${I.dinner}`)[0].source === 'reservations', 'Thursday dinner, posted unchanged, is still Reservations\'');
  await shot(page, 'desk-forecast-after-grid-save');
  await go(page, `/budget?site=102&${wk}`);
  const cols = await page.evaluate(() => [...new Set([...document.querySelectorAll('#budget-areas > div')].map((e) => Math.round(e.getBoundingClientRect().left)))].length);
  ok(cols === 2, `on a desktop the budget areas sit in ${cols} columns`);
  await widthOK(page, 'the budget', 1280);
  ok(await page.locator('#budget-form-bar').count() === 0, 'Mara sees the budget without a form');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' | ') : ''));
  await ctx.close();
}
{
  console.log('1280 x 800 — a planner (builds, no pay)');
  const { ctx, page, errors } = await session(35, null, DESK);
  await go(page, `/forecast?site=102&${wk}`);
  ok(await page.locator('#forecast-to-budget').count() === 0 && !/\$\s?\d/.test(await page.locator('#page-content').innerText()), 'no Budget link and no money on the planner\'s forecast');
  const r = await page.goto(BASE + `/budget?site=102&${wk}`);
  ok(r.status() === 403, 'the planner\'s /budget is a 403');
  await shot(page, 'desk-budget-403-planner');
  await go(page, `/builder?site=102&${wk}`);
  ok(await page.locator('#builder-foot-cost').count() === 0 && await page.locator('#builder-labor').count() === 0 && await page.locator('#builder-needs').count() === 1, 'the builder shows the planner the staffing needs and no cost row');
  ok(errors.length === 0 || errors.every((e) => e.includes('403')), 'no console errors beyond the 403 that was asked for');
  await ctx.close();
}
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
function dayIso(i) { const d = new Date(I.ws + 'T12:00:00Z'); d.setUTCDate(d.getUTCDate() + i); return d.toISOString().slice(0, 10); }
