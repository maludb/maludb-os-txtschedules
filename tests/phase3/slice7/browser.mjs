// Headless Chromium proof of slice 7 (docs/build-specs/settings-rules-reports.md, "Proof — 375 px and 1280 px"): no sideways scroll on any screen of the slice, every button, field and toggle at least 44 px tall, the trade rows
// stacked selects on a phone, reports scrolling inside their card, and the flows work as a person clicks them — the admin changes the hours a day of time off and sees the example line follow, turns swaps off and sees the sentence
// follow, saves; makes a rule hard and applies the preset; adds a day-part and archives it; a manager reads the rules and the reports and downloads a CSV. Run through tests/phase3/slice7/run.sh (servers up). Playwright comes from the kernel's web/node_modules (read only).
import { createRequire } from 'node:module';
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8191';
const SHOTS = process.env.SHOTS || '/tmp/txtschedules-shots-p3s7';
const KEY = process.env.ACTION_TOKEN_KEY;
const prep = JSON.parse(execFileSync('php', [new URL('./browser_prep.php', import.meta.url).pathname], { encoding: 'utf8', env: process.env }));
const I = prep.ids, Wk = prep.weeks;
const mint = (member, scope = null) => {
  const payload = `${member}.${Math.floor(Date.now() / 1000) + 60}.txtschedules.${crypto.randomBytes(16).toString('hex')}`;
  const token = payload + '.' + crypto.createHmac('sha256', KEY).update('sso:' + payload).digest('hex');
  const text = Buffer.from(JSON.stringify({ ...prep.claims[String(member)], member_id: member, scope })).toString('base64url');
  return BASE + '/sso?' + new URLSearchParams({ token, claims: text + '.' + crypto.createHmac('sha256', KEY).update(text).digest('hex') });
};
const lib = new URL('./lib.php', import.meta.url).pathname;
const php = (code) => execFileSync('php', ['-r', `require '${lib}'; ${code}`], { encoding: 'utf8', env: process.env });
const rows = (sql) => JSON.parse(php(`echo json_encode(q(${JSON.stringify(sql)}));`));
let failed = 0, passed = 0;
const ok = (c, l) => { if (c) passed++; else failed++; console.log((c ? '  ok   ' : '  FAIL ') + l); };
const browser = await chromium.launch({ ignoreDefaultArgs: ['--hide-scrollbars'] });
const PHONE = { width: 375, height: 740 }, DESK = { width: 1280, height: 800 };
async function session(member, scope, viewport) {
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: 2, isMobile: viewport.width < 600, hasTouch: viewport.width < 600, acceptDownloads: true });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  page.on('dialog', (d) => d.accept());
  await page.goto(mint(member, scope), { waitUntil: 'networkidle' });
  return { ctx, page, errors };
}
const widthOK = async (page, label, vw) => { const o = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth, iw: window.innerWidth })); ok(o.sw <= o.cw && o.iw === vw, `no sideways scroll on ${label} (${o.sw} <= ${o.cw})`); };
const touch = async (page, label) => {
  const small = await page.evaluate(() => [...document.querySelectorAll('#page-content a.btn, #page-content button.btn, #page-content summary.btn, #page-content select.form-select, #page-content input.form-control[type=text], #page-content input.form-control[type=date], #page-content input.form-control[type=number], #page-content input.form-control[type=time], #page-content label.btn-touch')]
    .filter((e) => { const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return cs.display !== 'none' && cs.visibility !== 'hidden' && r.width > 0 && r.height < 43.5 && !e.closest('details:not([open]) > :not(summary)'); })
    .map((e) => (e.id || e.className) + ':' + Math.round(e.getBoundingClientRect().height)));
  ok(small.length === 0, `every button, field and toggle is at least 44 px tall on ${label}` + (small.length ? ' — too small: ' + small.join(', ') : ''));
};
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
const dd = (n) => new Date(Date.parse(prep.today) + n * 864e5).toISOString().slice(0, 10);
const rng = `from=${Wk.A}&to=${Wk.end}`;
for (const [tag, vp] of [['phone', PHONE], ['desk', DESK]]) {
  console.log(`${vp.width} x ${vp.height} — screens`);
  await screens('Owner', 1, 102, vp, tag, [['settings', '/site/?site=102'], ['day-parts', '/site/day-parts?site=102'], ['day-part-add', '/site/day-parts?site=102&add=1'], ['day-part-edit', `/site/day-parts?site=102&edit=${I.lunch}`],
    ['rules', '/rules/?site=102'], ['reports-list', '/reports/?site=102'], ['report-hours', `/reports/?site=102&report=hours&${rng}`], ['report-labor', `/reports/?site=102&report=labor&${rng}`],
    ['report-open', `/reports/?site=102&report=open&${rng}`], ['report-trades', `/reports/?site=102&report=trades&from=${prep.today}&to=${prep.today}`], ['report-overtime', `/reports/?site=102&report=overtime&${rng}`],
    ['report-overrides', `/reports/?site=102&report=overrides&from=${dd(-1)}&to=${dd(1)}`], ['report-time-off', `/reports/?site=102&report=time-off&from=${Wk.TO}&to=${Wk.TO}`]]);
  await screens('Mara', 33, null, vp, tag, [['rules-readonly', '/rules/?site=102'], ['reports-list-mara', '/reports/?site=102'], ['report-hours-mara', `/reports/?site=102&report=hours&${rng}`]]);
  await screens('Pat', 35, null, vp, tag, [['reports-list-pat', '/reports/?site=102']]);
}
console.log('375 x 740 — the trade rows are stacked selects');
{
  const { ctx, page, errors } = await session(1, 102, PHONE);
  await go(page, '/site/?site=102');
  const boxes = await page.evaluate(() => [...document.querySelectorAll('#site-settings-trades select.form-select')].map((e) => { const r = e.getBoundingClientRect(); return { l: Math.round(r.left), w: Math.round(r.width), t: Math.round(r.top), h: Math.round(r.height) }; }));
  ok(boxes.length === 10 && boxes.every((b) => b.w > 240 && b.h >= 44) && boxes.every((b, i) => i === 0 || b.t > boxes[i - 1].t), 'the ten selects of "How shifts change hands" stack in one column, each wide and tall enough to tap: ' + JSON.stringify(boxes.slice(0, 3)) + '…');
  ok(await page.locator('#site-settings-sentence').count() === 1 && (await page.locator('#site-settings-sentence').innerText()).startsWith('Staff can offer, pick up, swap and give shifts.'), 'the sentence is under the trade rows');
  await ctx.close();
}
console.log('375 x 740 — the admin changes the settings and watches the sentence and the example follow');
{
  const { ctx, page, errors } = await session(1, 102, PHONE);
  await go(page, '/site/?site=102');
  await page.fill('#site-settings-form-field-day-hours', '6');
  await page.waitForFunction(() => document.querySelector('#site-settings-day-hours-example').innerText.includes('counts 12 hours'), null, { timeout: 5000 });
  ok((await page.locator('#site-settings-day-hours-example').innerText()) === 'Vacation of two whole days counts 12 hours.', 'typing 6: the line under the field reads "Vacation of two whole days counts 12 hours." before anything is saved');
  ok(rows("SELECT time_off_day_hours::float AS h FROM site_settings WHERE scope_id = 102")[0].h == 8, 'and nothing is saved yet (still 8)');
  await page.selectOption('#site-settings-form-field-allow-swap', 'no');
  await page.selectOption('#site-settings-form-field-approval-pickup', 'never');
  await page.fill('#site-settings-form-field-cutoff', '180');
  await page.waitForFunction(() => document.querySelector('#site-settings-sentence')?.innerText.includes('3 hours'), null, { timeout: 5000 });
  const sent = await page.locator('#site-settings-sentence').innerText();
  ok(sent.startsWith('Staff can offer, pick up and give shifts.') && sent.includes('A pick-up never waits for a manager.') && sent.includes('Nothing changes hands within 3 hours of the start.'), 'the sentence follows the three changes as they are made: "' + sent.slice(0, 160) + '…"');
  await shot(page, 'phone-settings-edited');
  await page.fill('#site-settings-form-field-day-hours', '30');
  await page.waitForFunction(() => document.querySelector('#site-settings-day-hours-example').innerText.includes('0.25 to 24'), null, { timeout: 5000 });
  ok(true, 'an out-of-range value in the field is explained in words as it is typed: "' + (await page.locator('#site-settings-day-hours-example').innerText()) + '"');
  await page.fill('#site-settings-form-field-day-hours', '6');
  await page.waitForFunction(() => document.querySelector('#site-settings-day-hours-example').innerText.includes('12 hours'), null, { timeout: 5000 });
  await clickAndLand(page, '#site-settings-form-save-btn', /notice=st_saved/);
  ok((await page.locator('#notice-banner').innerText()).includes('The settings are saved.'), 'saved: the banner says so');
  const r = rows('SELECT allow_swap, approval_pickup, cutoff_minutes, time_off_day_hours::float AS h FROM site_settings WHERE scope_id = 102')[0];
  ok(r.allow_swap === false && r.approval_pickup === 'never' && r.cutoff_minutes === 180 && Number(r.h) === 6, 'stored: swaps off, pick-ups never wait, cutoff 180, a day of time off 6 hours');
  ok((await page.locator('#site-settings-form-field-allow-swap').inputValue()) === 'no' && (await page.locator('#site-settings-form-field-day-hours').inputValue()) === '6' && (await page.locator('#site-settings-day-hours-example').innerText()).includes('12 hours'), 'the form shows what was saved');
  await shot(page, 'phone-settings-saved');
  await widthOK(page, 'the saved settings', 375);
  await page.fill('#site-settings-form-field-day-hours', '0');
  await page.click('#site-settings-form-save-btn');
  await page.waitForSelector('#flash .alert', { timeout: 5000 }).catch(() => {});
  ok(Number(rows('SELECT time_off_day_hours::float AS h FROM site_settings WHERE scope_id = 102')[0].h) === 6, 'a bad value on save (0) leaves the setting at 6');
  await shot(page, 'phone-settings-refused');
  ok(errors.filter((e) => !/422/.test(e)).length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' | ') : ''));
  await ctx.close();
  php("admin_sql(\"UPDATE site_settings SET allow_swap = true, approval_pickup = 'on_warning', cutoff_minutes = 120 WHERE scope_id = 102\");");
}
console.log('375 x 740 — rules: make one hard, apply the preset');
{
  const { ctx, page, errors } = await session(1, 102, PHONE);
  await go(page, '/rules/?site=102');
  ok((await page.locator('#rule-min_rest-severity').innerText()) === 'Soft', 'rest between shifts starts as Soft');
  await page.selectOption('#rule-form-min_rest-field-severity', 'hard');
  await page.fill('#rule-form-min_rest-field-hours', '11');
  await clickAndLand(page, '#rule-form-min_rest-save-btn', /notice=rl_saved/);
  ok((await page.locator('#notice-banner').innerText()).includes('The rule is saved.') && (await page.locator('#rule-min_rest-severity').innerText()) === 'Hard' && /#rule-min_rest$/.test(page.url()), 'saved: the banner, the badge says Hard and the address ends at the rule');
  ok((() => { const r = rows("SELECT severity, params FROM site_rules WHERE scope_id = 102 AND rule_key = 'min_rest'")[0]; return r.severity === 'hard' && JSON.parse(r.params).hours === 11; })(), 'stored: hard, 11 hours');
  await shot(page, 'phone-rules-hard');
  await page.fill('#rule-form-minor_latest_end-field-time', '23:30');
  await clickAndLand(page, '#rule-form-minor_latest_end-save-btn', /notice=rl_saved/);
  ok(JSON.parse(rows("SELECT params FROM site_rules WHERE scope_id = 102 AND rule_key = 'minor_latest_end'")[0].params).time === '23:30', 'a minor\'s latest end saved as 23:30');
  await page.fill('#rule-form-min_rest-field-hours', '99');
  await page.click('#rule-form-min_rest-save-btn');
  await page.waitForSelector('#flash .alert', { timeout: 5000 }).catch(() => {});
  ok(JSON.parse(rows("SELECT params FROM site_rules WHERE scope_id = 102 AND rule_key = 'min_rest'")[0].params).hours === 11, 'a value out of range (99 hours of rest) is refused and the rule keeps 11');
  await shot(page, 'phone-rules-refused');
  await go(page, '/rules/?site=102');
  await clickAndLand(page, '#rules-preset-apply-btn', /notice=rl_preset/);
  ok((await page.locator('#notice-banner').innerText()).includes('back to the starting values') && (await page.locator('#rule-min_rest-severity').innerText()) === 'Soft' && (await page.locator('#rule-form-min_rest-field-hours').inputValue()) === '10', 'Apply preset (confirmed): the banner, Soft again and 10 hours');
  ok((await page.locator('#rules-overrides').innerText()).includes('Lee asked for the morning and the crew agreed') && (await page.locator('#rules-overrides').innerText()).includes('Rest between shifts'), 'the overrides list shows the reason a manager gave in the builder');
  await shot(page, 'phone-rules-preset');
  await widthOK(page, 'the rules after the preset', 375);
  ok(errors.filter((e) => !/422/.test(e)).length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' | ') : ''));
  await ctx.close();
}
console.log('375 x 740 — day-parts: add Brunch, archive it');
{
  const { ctx, page, errors } = await session(1, 102, PHONE);
  await go(page, '/site/day-parts?site=102');
  ok((await page.locator(`#day-part-${I.lunch}-service`).innerText()) === 'Reservations calls this Lunch', 'Lunch: "Reservations calls this Lunch"');
  await clickAndLand(page, '#day-parts-add-btn', /add=1/);
  await page.fill('#day-part-form-field-name', 'SMOKE Brunch');
  await page.fill('#day-part-form-field-starts', '09:00'); await page.fill('#day-part-form-field-ends', '11:00');
  await page.fill('#day-part-form-field-service', 'Brunch');
  await shot(page, 'phone-day-part-form');
  await clickAndLand(page, '#day-part-form-save-btn', /notice=dp_saved/);
  const id = rows("SELECT id FROM day_parts WHERE name = 'SMOKE Brunch'")[0].id;
  ok(new RegExp(`#day-part-${id}$`).test(page.url()) && (await page.locator(`#day-part-${id}-service`).innerText()) === 'Reservations calls this Brunch' && (await page.locator('#notice-banner').innerText()).includes('The day-part is saved.'), 'saved: the address ends in the new card, which reads "Reservations calls this Brunch"');
  await shot(page, 'phone-day-parts-after-add');
  await clickAndLand(page, `#day-part-${id}-archive-btn`, /notice=dp_archived/);
  ok(await page.locator(`#day-part-${id}-archived`).count() === 1 && await page.locator(`#day-part-${id}-archive-btn`).count() === 0 && (await page.locator('#notice-banner').innerText()).includes('Its old forecasts are kept.'), 'archived (confirmed): it moves to Archived and the banner says the forecasts are kept');
  await shot(page, 'phone-day-parts-archived');
  await widthOK(page, 'the day-parts after archiving', 375);
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' | ') : ''));
  await ctx.close();
}
console.log('375 x 740 — a manager reads the rules and the reports');
{
  const { ctx, page, errors } = await session(33, null, PHONE);
  await go(page, '/rules/?site=102');
  ok(await page.locator('[id^="rule-form-"]').count() === 0 && await page.locator('#rules-preset-apply-btn').count() === 0 && await page.locator('#rules-disclaimer').count() === 1, 'Mara reads the rules with no forms and no preset button, and the compliance sentence');
  await go(page, '/reports/?site=102');
  ok(await page.locator('#reports-list a.card').count() === 7, 'the seven reports are cards');
  await clickAndLand(page, '#report-hours', /report=hours/);
  ok(await page.locator('#report-title').count() === 1 && await page.locator('#report-table').count() === 1, 'a tap opens the report');
  await page.fill('#reports-range-field-from', Wk.A); await page.fill('#reports-range-field-to', Wk.end);
  await page.click('#reports-range-show-btn'); await page.waitForLoadState('networkidle');
  const t = await page.evaluate(() => { const c = document.querySelector('#report-table-card .table-responsive'); return { sw: c.scrollWidth, cw: c.clientWidth, page: document.documentElement.scrollWidth <= document.documentElement.clientWidth }; });
  ok(t.sw > t.cw && t.page, `the hours table scrolls inside its card (${t.sw} > ${t.cw}) and the page does not`);
  ok((await page.locator('#report-table tbody tr').count()) === 4, 'four rows: Ana, Lee and Priya in week A, Priya in the draft week');
  await shot(page, 'phone-report-hours-mara');
  const [dl] = await Promise.all([page.waitForEvent('download'), page.click('#reports-csv-btn')]);
  const csv = fs.readFileSync(await dl.path(), 'utf8');
  const lines = csv.trim().split('\r\n');
  ok(dl.suggestedFilename().startsWith('txtschedules-hours-') && lines.length === 5 && lines[0].startsWith('Week of,Person,Positions') && lines.some((l) => l.includes('SMOKE Priya') && l.includes('Over the overtime line')), 'Download CSV: a file "' + dl.suggestedFilename() + '" of the header and the same four rows');
  ok(Number(rows("SELECT count(*) AS n FROM activity_log WHERE action = 'report.export'")[0].n) >= 1, 'and the download was logged report.export');
  await go(page, `/reports/?site=102&report=labor&${rng}`);
  ok(await page.locator('#report-table').count() === 1 && (await page.locator('#report-table').innerText()).includes('Total'), 'Mara (manager: labor.view) also opens Labor against budget');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' | ') : ''));
  await ctx.close();
}
console.log('375 x 740 — a planner (no pay) has no labor report');
{
  const { ctx, page, errors } = await session(35, null, PHONE);
  await go(page, '/reports/?site=102');
  ok(await page.locator('#report-labor').count() === 0 && await page.locator('#report-time-off').count() === 0 && await page.locator('#report-hours').count() === 1, 'Pat is offered five reports — no Labor against budget, no Time off');
  const r = await page.request.get(BASE + `/reports/?site=102&report=labor&${rng}`);
  ok(r.status() === 403, 'and the labor report is 403');
  await go(page, `/reports/?site=102&report=overtime&${rng}`);
  ok(!(await page.locator('#report-table').innerText()).toLowerCase().includes('cost'), 'the overtime report has no cost column for him');
  await shot(page, 'phone-report-overtime-pat');
  await ctx.close();
}
console.log('1280 x 800 — the desk views');
{
  const { ctx, page, errors } = await session(1, 102, DESK);
  await go(page, '/site/day-parts?site=102');
  const cols = await page.evaluate(() => [...document.querySelectorAll('#day-parts-list > div')].map((e) => Math.round(e.getBoundingClientRect().left)));
  ok(new Set(cols).size >= 2, 'on a desktop the day-part cards sit side by side (' + cols.join(',') + ')');
  await go(page, '/rules/?site=102');
  const rc = await page.evaluate(() => [...document.querySelectorAll('#rules-cards > div')].slice(0, 2).map((e) => Math.round(e.getBoundingClientRect().left)));
  ok(new Set(rc).size === 2, 'the rule cards sit two to a row (' + rc.join(',') + ')');
  await go(page, `/reports/?site=102&report=hours&${rng}`);
  const fits = await page.evaluate(() => { const c = document.querySelector('#report-table-card .table-responsive'); return c.scrollWidth <= c.clientWidth; });
  ok(fits, 'the hours table fits its card without scrolling on a desktop');
  await widthOK(page, 'the desk report', 1280);
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' | ') : ''));
  await ctx.close();
}
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
