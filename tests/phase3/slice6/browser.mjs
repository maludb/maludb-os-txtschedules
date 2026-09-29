// Headless Chromium proof of slice 6 (docs/build-specs/announcements-notifications.md, "Proof — 375 px and 1280 px"): no sideways scroll on any screen of the slice, the settings toggles at least 44 px tall, the announcement
// cards stack on a phone, and the flows work as a person clicks them — Ana reads an announcement, Mara posts to two people through the confirm page, Priya changes how she is told, makes a calendar link, makes a new one and
// the old one dies. Run through tests/phase3/slice6/run.sh (servers up). Playwright comes from the kernel's web/node_modules (read only).
import { createRequire } from 'node:module';
import crypto from 'node:crypto';
import { execFileSync } from 'node:child_process';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8191';
const SHOTS = process.env.SHOTS || '/tmp/txtschedules-shots-p3s6';
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
const widthOK = async (page, label, vw) => { const o = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth, iw: window.innerWidth })); ok(o.sw <= o.cw && o.iw === vw, `no sideways scroll on ${label} (${o.sw} <= ${o.cw})`); };
const touch = async (page, label) => {
  const small = await page.evaluate(() => [...document.querySelectorAll('#page-content a.btn, #page-content button.btn, #page-content summary.btn, #page-content select.form-select, #page-content input.form-control[type=text], #page-content input.form-control[type=date], #page-content label.btn-touch')]
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
for (const [tag, vp] of [['phone', PHONE], ['desk', DESK]]) {
  console.log(`${vp.width} x ${vp.height} — screens`);
  await screens('Priya', 26, null, vp, tag, [['announcements', '/announcements/?site=102'], ['settings-notify', '/settings/'], ['settings-calendar', '/settings/?tab=calendar']]);
  await screens('Mara', 33, null, vp, tag, [['announcements-poster', '/announcements/?site=102'], ['announcement-add', '/announcements/new?site=102&audience=people']]);
  await screens('Ana', 30, null, vp, tag, [['announcements-ana', '/announcements/?site=102']]);
  await screens('Owner', 1, 102, vp, tag, [['announcements-owner', '/announcements/?site=102']]);
}
console.log('375 x 740 — the cards stack');
{
  const { ctx, page } = await session(33, null, PHONE);
  await go(page, '/announcements/?site=102');
  const boxes = await page.evaluate(() => [...document.querySelectorAll('#announcements-cards > div')].map((e) => { const r = e.getBoundingClientRect(); return { l: Math.round(r.left), w: Math.round(r.width), t: Math.round(r.top) }; }));
  ok(boxes.length === 3 && new Set(boxes.map((b) => b.l)).size === 1 && boxes.every((b) => b.w > 330) && boxes[0].t < boxes[1].t && boxes[1].t < boxes[2].t, 'the three announcement cards stack in one column, each nearly the full width: ' + JSON.stringify(boxes));
  const first = await page.locator(`#announcement-${I.a1}-title`).innerText();
  ok(first.includes('Parking behind the building'), 'the pinned one is first');
  ok(await page.locator(`#announcement-${I.a1}-pinned`).count() === 1 && (await page.locator(`#announcement-${I.a1}-body a`).getAttribute('href')).startsWith('https://example.invalid/parking-map'), 'the pin chip and the clickable link');
  const rc = await page.locator(`#announcement-${I.a1}-read-count`).innerText(); ok(rc.toLowerCase().includes('2 read'), 'the poster sees "2 read" ("' + rc + '")');
  await page.click(`#announcement-${I.a1}-read-count`);
  ok((await page.locator(`#announcement-${I.a1}-readers`).innerText()).includes('SMOKE Priya') && (await page.locator(`#announcement-${I.a1}-readers`).innerText()).includes('SMOKE Lee'), 'and on tap the names');
  await shot(page, 'phone-announcements-readers');
  await widthOK(page, 'the readers open', 375);
  await ctx.close();
}
console.log('375 x 740 — Ana reads one');
{
  const { ctx, page, errors } = await session(30, null, PHONE);
  await go(page, '/announcements/?site=102');
  ok(await page.locator(`#announcement-${I.a2}`).count() === 1 && await page.locator(`#announcement-${I.a3}`).count() === 0, 'Ana sees the Bar announcement and not the one for two other people');
  ok(await page.locator(`#announcement-${I.a1}-unread`).count() === 1 && await page.locator('[id$=-read-count]').count() === 0, 'the parking one is marked New, and she sees no read counts');
  await clickAndLand(page, `#announcement-${I.a1}-read-btn`, /notice=an_read/);
  ok((await page.locator('#notice-banner').innerText()).includes('Marked as read.') && await page.locator(`#announcement-${I.a1}-unread`).count() === 0 && await page.locator(`#announcement-${I.a1}-read-btn`).count() === 0, 'she taps Got it: the banner says so and New is gone');
  ok(rows(`SELECT count(*) AS n FROM announcement_reads WHERE announcement_id = ${I.a1}`)[0].n === 3 || Number(rows(`SELECT count(*) AS n FROM announcement_reads WHERE announcement_id = ${I.a1}`)[0].n) === 3, 'three receipts now');
  await shot(page, 'phone-announcements-after-read');
  await widthOK(page, 'after reading', 375);
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' | ') : ''));
  await ctx.close();
}
console.log('375 x 740 — Mara posts to two people through the confirm page');
{
  const { ctx, page, errors } = await session(33, null, PHONE);
  await go(page, '/announcements/new?site=102&audience=people');
  ok(await page.locator('#announcement-form-field-audience-people').isChecked(), 'the audience arrives as asked (named people)');
  await page.fill('#announcement-form-field-title', 'SMOKE Trial shift Monday');
  await page.fill('#announcement-form-field-body', 'Two trial shifts on Monday at 5 pm.\nSee https://example.invalid/trial for the plan.');
  await page.check(`#announcement-form-field-person-30`);
  await page.check(`#announcement-form-field-person-32`);
  await page.click('#announcement-form-review-btn');
  await page.waitForSelector('#announcement-confirm-reach');
  ok((await page.locator('#announcement-confirm-reach').innerText()).includes('This will be sent to 2 people by email and text.'), 'the confirm page says "This will be sent to 2 people by email and text."');
  ok(rows("SELECT count(*) AS n FROM announcements WHERE title = 'SMOKE Trial shift Monday'")[0].n === 0 || Number(rows("SELECT count(*) AS n FROM announcements WHERE title = 'SMOKE Trial shift Monday'")[0].n) === 0, 'nothing is posted yet');
  await shot(page, 'phone-announcement-confirm');
  await widthOK(page, 'the confirm page', 375); await touch(page, 'the confirm page');
  await clickAndLand(page, '#announcement-confirm-send-btn', /notice=an_posted/);
  ok((await page.locator('#notice-banner').innerText()).includes('Announcement posted.') && /#announcement-\d+$/.test(page.url()), 'sent: back on the list with the banner and the address ends in the new announcement (' + page.url().slice(-40) + ')');
  const n = rows("SELECT id FROM announcements WHERE title = 'SMOKE Trial shift Monday'");
  ok(n.length === 1 && rows(`SELECT count(*) AS n FROM notification_outbox WHERE reference = 'announcement:${n[0].id}'`)[0].n == 4, 'one announcement and four queued notices (two people, two channels)');
  await shot(page, 'phone-announcements-after-post');
  await widthOK(page, 'after posting', 375);
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' | ') : ''));
  await ctx.close();
}
console.log('375 x 740 — Priya: how she is told, and her calendar link');
{
  const { ctx, page, errors } = await session(26, null, PHONE);
  await go(page, '/settings/');
  ok(await page.locator('#prefs-text-note').count() === 1 && (await page.locator('#prefs-text-note').innerText()).includes('Texts need a phone number verified in the operating system'), 'the note about the phone is shown (the kernel refused her last text)');
  const hs = await page.evaluate(() => [...document.querySelectorAll('#prefs label.btn-touch')].map((l) => Math.round(l.getBoundingClientRect().height)));
  ok(hs.length === 8 && hs.every((h) => h >= 44), 'the eight toggles (two channels, six events) are each at least 44 px tall: ' + hs.join(','));
  await page.uncheck('#prefs-field-sms');
  await page.uncheck('#prefs-field-kind-exchange');
  await page.selectOption('#prefs-field-lead', '240');
  await clickAndLand(page, '#prefs-save-btn', /notice=pf_saved/);
  ok((await page.locator('#notice-banner').innerText()).includes('Saved'), 'saved: the banner says so');
  const r = rows('SELECT by_email, by_sms, kinds, reminder_minutes FROM notification_prefs WHERE member_id = 26')[0];
  ok(r.by_sms === false && r.by_email === true && r.reminder_minutes === 240 && !r.kinds.includes('exchange') && r.kinds.includes('reminder'), 'stored: text off, email on, four hours, trades off');
  ok(!(await page.locator('#prefs-field-sms').isChecked()) && (await page.locator('#prefs-field-lead').inputValue()) === '240', 'and the form remembers');
  await shot(page, 'phone-settings-saved');
  await widthOK(page, 'the saved settings', 375);
  await go(page, '/settings/?tab=calendar');
  ok((await page.locator('#calendar-link-state').innerText()).includes('no link yet'), 'no calendar link yet');
  await clickAndLand(page, '#calendar-link-make-btn', /notice=cf_made/);
  const url1 = await page.locator('#calendar-link-field-url').inputValue();
  ok(/\/api\/v1\/calendar\/[a-f0-9]{48}\.ics$/.test(url1), 'the link is shown once: ' + url1.slice(0, 40) + '…');
  await shot(page, 'phone-calendar-link-made');
  await widthOK(page, 'the calendar link', 375); await touch(page, 'the calendar link');
  const feed = await page.request.get(url1);
  ok(feed.status() === 200 && (feed.headers()['content-type'] || '').startsWith('text/calendar') && (await feed.text()).startsWith('BEGIN:VCALENDAR'), 'the link is a calendar');
  await go(page, '/settings/?tab=calendar');
  ok(await page.locator('#calendar-link-field-url').count() === 0 && (await page.locator('#calendar-link-state').innerText()).includes('cannot be shown again'), 'on the next visit it is not shown again');
  await shot(page, 'phone-calendar-link-hidden');
  await clickAndLand(page, '#calendar-link-make-btn', /notice=cf_rotated/);
  const url2 = await page.locator('#calendar-link-field-url').inputValue();
  ok(url2 !== url1 && (await page.request.get(url1)).status() === 404 && (await page.request.get(url2)).status() === 200, 'Make a new link (confirmed): a new address; the old one is 404, the new one works');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' | ') : ''));
  await ctx.close();
}
console.log('1280 x 800 — the poster\'s desk view');
{
  const { ctx, page, errors } = await session(1, 102, DESK);
  await go(page, '/announcements/?site=102');
  const cols = await page.evaluate(() => [...document.querySelectorAll('#announcements-cards > div')].map((e) => Math.round(e.getBoundingClientRect().left)));
  ok(new Set(cols).size === 2, 'on a desktop the cards sit two to a row (' + cols.join(',') + ')');
  await widthOK(page, 'the desk announcements', 1280);
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' | ') : ''));
  await ctx.close();
}
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
