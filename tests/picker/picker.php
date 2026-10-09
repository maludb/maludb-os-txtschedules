<?php
/**
 * The record picker's proof (design-system reference record-picker.md; docs/record-picker.md here). Run through tests/picker/run.sh: a SCRATCH database (never the installed one),
 * the application under php -S, a fake kernel and a fake MaluDB. Everything it makes is named "SMOKE …"; nothing is sent.
 *   1. the code: the shell carries the one modal, the script and the CSS; no <select> of a person remains;
 *   2. /pick/: refusals (anonymous, unknown source, cleaned key, wrong right, a site not held, no site), the first page, search, escaping, paging past 25, `self`, the colleague source;
 *   3. every converted form renders its picker under the old name and id, with the chosen label on a first render;
 *   4. a write through the hidden input exactly as through the select: a shift, a give, time off for someone else.
 * txtSchedules' forms are hx-post to #flash — a refused write never re-renders the form — so the "422 keeps the label" check is the form's first render from the query (the prefill).
 */
require dirname(__DIR__) . '/phase3/slice4/lib.php';

$W = reset4();
$mara = as_member(33); $owner = as_member(1, 102); $priya = as_member(26); $ana = as_member(30); $lee = as_member(31); $joe = as_member(34); $planner = as_planner();
foreach ([$mara, $owner, $priya, $ana, $lee, $joe, $planner] as $j) { /* signed on */ }
$root = dirname(__DIR__, 2);
$get = fn (string $jar, string $path): array => req('GET', $path, ['jar' => $jar]);
$rows = fn (string $body): int => preg_match_all('/class="list-group-item list-group-item-action record-picker-row/', $body);
$labels = fn (string $body): array => preg_match_all('/data-label="([^"]*)"/', $body, $m) ? $m[1] : [];

echo "-- 1. the code\n";
foreach (['app/picker.php', 'app/pickers.php', 'html/pick/index.php', 'app/views/shared/picker-rows.php', 'app/views/layout.php'] as $f) {
    exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $out, $rc); ok($rc === 0, "php -l $f");
}
$layout = file_get_contents("$root/app/views/layout.php");
ok(substr_count($layout, 'id="record-picker"') === 1 && str_contains($layout, 'hx-vals="js:{...recordPickerValues()}"') && str_contains($layout, '/assets/js/record-picker.js'), 'the shell carries the one modal, its search box and the script');
ok(str_contains($layout, 'record-picker.js') && strpos($layout, 'common-init.min.js') < strrpos($layout, 'record-picker.js'), 'the script loads after common-init.min.js');
ok(str_contains(file_get_contents("$root/html/assets/css/app-overrides.css"), 'RECORD PICKER'), 'app-overrides.css has the picker section');
ok(str_contains(file_get_contents("$root/app/bootstrap.php"), "/picker.php'"), 'the bootstrap requires app/picker.php');
exec('node --check ' . escapeshellarg("$root/html/assets/js/record-picker.js") . ' 2>&1', $o2, $rc2); ok($rc2 === 0, 'node parses the script');
$views = glob("$root/app/views/{availability,builder/partials,schedule,shifts,timeoff}/{,*/}*.php", GLOB_BRACE);
$bad = [];
foreach ($views as $v) { if (preg_match('/<select[^>]*name="(member|assignee|colleague|swap_with)"/', (string) file_get_contents($v))) { $bad[] = basename($v); } }
ok($bad === [], 'no <select> of a person remains in those views' . ($bad ? ': ' . implode(', ', $bad) : ''));

echo "-- 2. /pick/\n";
$anon = req('GET', '/pick/?source=person&site_id=102');
ok(in_array($anon['code'], [302, 303], true), "anonymous: sent to the launcher ({$anon['code']})");
$r = $get($mara, '/pick/?source=nope&site_id=102');
ok($r['code'] === 404 && str_contains($r['body'], 'That list does not exist') && !str_contains($r['body'], '<html'), 'an unknown source is 404 with a fragment, not a page');
ok($get($mara, '/pick/?source=../etc')['code'] === 404, 'a source key is cleaned before the lookup');
$r = $get($mara, '/pick/?source=person&site_id=102');
ok($r['code'] === 200 && $rows($r['body']) >= 5, "a manager lists the Airport's people ({$rows($r['body'])} rows)");
$lab = $labels($r['body']);
ok(in_array('SMOKE Ana', $lab, true) && in_array('SMOKE Lee', $lab, true) && !in_array('SMOKE Joe', $lab, true), 'the Airport people are there; Downtown\'s Joe is not');
ok(str_contains($r['body'], 'record-picker-detail">Server') || str_contains($r['body'], 'record-picker-detail">Bar'), 'a person\'s row names the positions they work');
$r = $get($mara, '/pick/?source=person&site_id=101');
ok($r['code'] === 403 && str_contains($r['body'], 'permission') && !str_contains($r['body'], '<html') && !str_contains($r['body'], 'SMOKE Joe'), 'a site the manager does not hold: 403 fragment, nobody listed');
$r = $get($mara, '/pick/?source=person&site_id=999999');
ok($r['code'] === 403, 'a site that does not exist: 403 (it does not exist to them)');
$r = $get($mara, '/pick/?source=person');
ok($r['code'] === 403 && !str_contains($r['body'], 'SMOKE Ana'), 'no site given: 403, never the caller\'s whole directory');
$r = $get($mara, '/pick/?source=person&site_id=abc');
ok($r['code'] === 403, 'a site that is not a number is no site');
$r = $get($priya, '/pick/?source=person&site_id=102');
ok($r['code'] === 403 && str_contains($r['body'], 'permission'), 'staff (no schedule.build): 403 fragment');
ok($get($ana, '/pick/?source=person_approver&site_id=102')['code'] === 403, 'staff may not list people to approve for');
ok($get($planner, '/pick/?source=person&site_id=102')['code'] === 200, 'a planner (schedule.build) may list people');
ok($get($planner, '/pick/?source=person_approver&site_id=102')['code'] === 403, '...but not as an approver (no requests.approve)');
ok($get($mara, '/pick/?source=person_approver&site_id=102')['code'] === 200, 'a manager lists people as an approver');
$r = $get($joe, '/pick/?source=person&site_id=101');
ok($r['code'] === 403, 'Downtown\'s staff member: 403 at his own restaurant too');

$sql = "DELETE FROM members WHERE display_name LIKE 'SMOKE Picker %'";
admin_sql($sql);
admin_sql("INSERT INTO members (id, member_kind, display_name, email, business_role, is_external, status, capability, roles)
           SELECT 9000 + n, 'human', format('SMOKE Picker %s', lpad(n::text, 2, '0')), format('picker%s@example.invalid', n), 'user', false, 'active', 'write', '{staff}' FROM generate_series(1, 30) n;
           INSERT INTO members (id, member_kind, display_name, email, business_role, is_external, status, capability, roles) VALUES (9100, 'human', 'SMOKE Picker <i>Esc</i> & O''Neil', 'esc@example.invalid', 'user', false, 'active', 'write', '{staff}');
           INSERT INTO member_site_roles (member_id, scope_id, role_key, roles, capability) SELECT id, 102, 'staff', '{staff}', 'write' FROM members WHERE display_name LIKE 'SMOKE Picker %';
           INSERT INTO staff_profiles (member_id, main_scope_id) SELECT id, 102 FROM members WHERE display_name LIKE 'SMOKE Picker %';
           INSERT INTO staff_positions (member_id, position_id) SELECT id, {$W['aSrv']} FROM members WHERE display_name LIKE 'SMOKE Picker %';");
$total = (int) one("SELECT count(*) FROM staff_profiles sp JOIN members m ON m.id = sp.member_id JOIN member_site_roles r ON r.member_id = m.id AND r.scope_id = 102 WHERE m.member_kind = 'human' AND sp.active AND m.status = 'active'");
$p1 = $get($mara, '/pick/?source=person&site_id=102');
ok($rows($p1['body']) === 25 && str_contains($p1['body'], 'id="record-picker-more"') && str_contains($p1['body'], "25 of $total shown") && str_contains($p1['body'], 'page=2') && str_contains($p1['body'], 'site_id=102'),
   "the first page is 25 rows with Show more asking for page 2 and keeping the site ($total people)");
$p2 = $get($mara, '/pick/?source=person&site_id=102&page=2');
ok($rows($p2['body']) === min(25, $total - 25) && !str_contains($p2['body'], 'record-picker-list'), 'page 2 is rows only, no list wrapper');
if ($total > 50) { $p3 = $get($mara, '/pick/?source=person&site_id=102&page=3'); ok($rows($p3['body']) === $total - 50 && !str_contains($p3['body'], 'record-picker-more'), 'the last page has no Show more'); }
$r = $get($mara, '/pick/?source=person&site_id=102&q=picker%2007');
ok($rows($r['body']) === 1 && str_contains($r['body'], 'SMOKE Picker 07'), 'search narrows by name');
$r = $get($mara, '/pick/?source=person&site_id=102&q=zzzz%3Cb%3Eq');
ok(str_contains($r['body'], 'No person matches “zzzz&lt;b&gt;q”') && !str_contains($r['body'], '<b>q'), 'no match says so, the words escaped');
$r = $get($mara, '/pick/?source=person&site_id=102&q=' . urlencode('<i>Esc'));
ok(str_contains($r['body'], 'data-label="SMOKE Picker &lt;i&gt;Esc&lt;/i&gt; &amp; O&#039;Neil"') && !str_contains($r['body'], '<i>Esc</i>'), 'a name with markup is escaped in the row and its data-label');
ok($get($mara, '/pick/?source=person&site_id=102&q=' . str_repeat('a', 120))['code'] === 200, 'a search longer than 80 characters is cut, not refused');
$r = $get($mara, '/pick/?source=person&site_id=102&selected=30');
ok(str_contains($r['body'], 'record-picker-row active" id="record-picker-row-30"'), 'the chosen row is marked active');
// self: the caller first even when off the schedule
admin_sql("UPDATE staff_profiles SET active = false WHERE member_id = 33");
$r = $get($mara, '/pick/?source=person&site_id=102&self=1');
ok($labels($r['body'])[0] === 'SMOKE Mara' && str_contains($r['body'], 'record-picker-detail">You'), 'self=1: the caller is first and marked You, though off the schedule (the old select\'s Me)');
$r = $get($mara, '/pick/?source=person&site_id=102');
ok(!in_array('SMOKE Mara', $labels($r['body']), true), 'without self, an unscheduled manager is not listed (as before)');
admin_sql("UPDATE staff_profiles SET active = true WHERE member_id = 33");
$r = $get($mara, '/pick/?source=person&site_id=102&self=1');
ok(count(array_keys($labels($r['body']), 'SMOKE Mara', true)) === 1 && $labels($r['body'])[0] === 'SMOKE Mara', 'self=1 on the schedule: once, first');
admin_sql("UPDATE staff_profiles SET active = false WHERE member_id = 28");
ok(!in_array('SMOKE Sam', $labels($get($mara, '/pick/?source=person&site_id=102')['body']), true), 'a person off the schedule is not listed');
admin_sql("UPDATE staff_profiles SET active = true WHERE member_id = 28");
ok(!str_contains($get($mara, '/pick/?source=person&site_id=102&active=0&foo=1')['body'], 'Undeclared'), 'an undeclared param changes nothing');
$r = $get($mara, '/pick/?source=person&site_id=102');
ok(!preg_match('/@example\.invalid|\d{3}-\d{4}/', $r['body']), 'a row carries no email or phone');

// the colleague source: the holder of the shift, with market.trade, sees who could take it
$mine = (int) $W['p1'];
$r = $get($priya, "/pick/?source=colleague&shift_id=$mine");
$cl = $labels($r['body']);
ok($r['code'] === 200 && in_array('SMOKE Ana', $cl, true) && in_array('SMOKE Lee', $cl, true) && !in_array('SMOKE Priya', $cl, true) && !in_array('SMOKE Marco', $cl, true), 'colleague: the holder sees who works the position here (not herself, not Marco whose main is Downtown)');
ok($get($lee, "/pick/?source=colleague&shift_id=$mine")['code'] === 403, 'colleague: someone who does not hold the shift: 403');
ok($get($mara, "/pick/?source=colleague&shift_id=$mine")['code'] === 403, 'colleague: a manager who does not hold it: 403');
ok($get($priya, '/pick/?source=colleague')['code'] === 403 && $get($priya, '/pick/?source=colleague&shift_id=99999999')['code'] === 403, 'colleague: no shift, or one that does not exist: 403');
ok($get($joe, "/pick/?source=colleague&shift_id=$mine")['code'] === 403, 'colleague: a shift at a restaurant not held: 403');
ok($rows($get($priya, "/pick/?source=colleague&shift_id=$mine&q=SMOKE%20Ana")['body']) === 1, 'colleague: search narrows');

echo "-- 3. the forms\n";
$has = fn (string $b, string $name, string $id, string $source): bool => (bool) preg_match('/<input type="hidden" name="' . preg_quote($name, '/') . '" id="' . preg_quote($id, '/') . '" value="[^"]*" data-picker-value[^>]*\/>/', $b)
    && str_contains($b, 'id="' . $id . '-picker"') && str_contains($b, 'data-picker-source="' . $source . '"') && str_contains($b, 'id="' . $id . '-open"') && str_contains($b, 'for="' . $id . '-open"');
$noSelect = fn (string $b, string $name): bool => !preg_match('/<select[^>]*name="' . $name . '"/', $b);
$pg = $get($mara, '/availability')['body'];
ok($has($pg, 'member', 'availability-person-field-member', 'person') && $noSelect($pg, 'member') && substr_count($pg, 'id="record-picker"') === 1, 'availability: the person picker under name=member');
ok(str_contains($pg, 'data-picker-params="{&quot;site_id&quot;:102,&quot;self&quot;:1}"') && str_contains($pg, 'record-picker-label">SMOKE Mara<') && !str_contains($pg, 'record-picker-clear'), 'availability: the site and self are declared, the caller is the first label, no Clear (required)');
ok(str_contains($get($mara, '/availability?member=30')['body'], 'record-picker-label">SMOKE Ana<'), 'availability?member=30: the chosen label is Ana\'s');
$pg = $get($mara, '/time-off/balances')['body'];
ok($has($pg, 'member', 'balances-person-field-member', 'person_approver') && $noSelect($pg, 'member'), 'balances: the person picker (approver) under name=member');
$pg = $get($mara, '/time-off/new?site=102')['body'];
ok($has($pg, 'member', 'time-off-form-field-member', 'person_approver') && $noSelect($pg, 'member') && str_contains($pg, 'data-picker-params="{&quot;site_id&quot;:102,&quot;self&quot;:1}"'), 'time off: "For" is a picker, the site declared');
ok(str_contains($get($mara, '/time-off/new?site=102&member=30')['body'], 'record-picker-label">SMOKE Ana<'), 'time off ?member=30: shows Ana');
ok(!str_contains($get($ana, '/time-off/new?site=102')['body'], 'time-off-form-field-member'), 'time off for staff: no "For" field at all (as before)');
$pg = $get($mara, '/shifts/new?site=102')['body'];
ok($has($pg, 'assignee', 'shift-form-field-assignee', 'person') && $noSelect($pg, 'assignee') && str_contains($pg, 'record-picker-label text-muted">Leave open<') && str_contains($pg, 'record-picker-clear d-none'), 'new shift: "Who" is an optional picker, placeholder Leave open, Clear hidden while empty');
$pg = $get($mara, '/shifts/new?site=102&assignee=30')['body'];
ok(str_contains($pg, 'name="assignee" id="shift-form-field-assignee" value="30"') && str_contains($pg, 'record-picker-label">SMOKE Ana<') && !str_contains($pg, 'record-picker-clear d-none'), 'new shift ?assignee=30: Ana chosen, Clear shown (a first render keeps the label)');
ok(str_contains($pg, 'hx-trigger="change from:#shift-form') && str_contains($pg, 'hx-include="#shift-form"'), 'the shift form\'s live check still listens for change on the form');
$sid = (int) $W['pdraft'];
$pg = $get($mara, "/shifts/$sid/edit")['body'];
ok($has($pg, 'assignee', 'shift-form-field-assignee', 'person') && str_contains($pg, 'record-picker-label">SMOKE Priya<'), 'edit shift: the holder is the chosen label');
$pg = $get($mara, "/shifts/$mine")['body'];
ok($has($pg, 'assignee', "shift-move-$mine-person", 'person') && str_contains($pg, 'record-picker-label">SMOKE Priya<') && $noSelect($pg, 'assignee'), 'shift page, manager: "Move to…" has a person picker with the holder chosen');
$pg = $get($mara, '/builder?site=102&week=' . wk(0))['body'];
ok(preg_match('/id="builder-list-move-\d+-person-picker"/', $pg) === 1 || preg_match('/builder-list-move-\d+-person-open/', $pg) === 1 || str_contains($pg, 'builder-list-move'), 'the builder lists "Move to…" with person pickers (or this week has no shifts)');
$pg = $get($priya, "/shifts/$mine")['body'];
ok($has($pg, 'colleague', 'give-form-field-colleague', 'colleague') && $has($pg, 'swap_with', 'swap-form-field-with', 'colleague') && $noSelect($pg, 'colleague') && $noSelect($pg, 'swap_with')
   && substr_count($pg, 'data-picker-params="{&quot;shift_id&quot;:' . $mine . '}"') === 2, 'shift page, holder: Give and Swap colleague pickers, the shift declared');
ok(!str_contains($pg, '<option value="30"') && !str_contains($pg, '<option value="31"'), 'the page no longer carries every colleague as an option');
$pg = $get($priya, "/shifts/$mine?swap_with=30")['body'];
ok(str_contains($pg, 'name="swap_with" id="swap-form-field-with" value="30"') && str_contains($pg, 'record-picker-label">SMOKE Ana<'), 'swap ?swap_with=30: Ana chosen, her shifts listed below');
ok(!str_contains($get($lee, "/shifts/$mine")['body'], 'give-form-field-colleague'), 'someone who does not hold the shift sees no Give field');

echo "-- 4. a write through the hidden input\n";
$ws = wk(30);                                  // a week nobody published: shift_create
$date = (new DateTimeImmutable($ws))->modify('+2 days')->format('Y-m-d');
[$c, $b] = act($mara, '/shifts/save.php', ['site' => 102, 'position' => $W['aSrv'], 'date' => $date, 'starts' => '12:00', 'ends' => '16:00', 'break' => 0, 'assignee' => 9001]);
$new = (int) ($b['record_id'] ?? 0);
if ($c !== 200) { fwrite(STDERR, 'save said: ' . msg($b) . "\n"); }
ok($c === 200 && $new > 0 && (int) one('SELECT assignee_member_id FROM shifts WHERE id = :i', ['i' => $new]) === 9001, "a shift is saved with assignee posted from the hidden input ($c)");
[$c, $b] = act($mara, '/shifts/save.php', ['site' => 102, 'position' => $W['aSrv'], 'date' => $date, 'starts' => '12:00', 'ends' => '16:00', 'break' => 0, 'assignee' => '']);
$open = (int) ($b['record_id'] ?? 0);
ok($c === 200 && $open > 0 && one('SELECT assignee_member_id FROM shifts WHERE id = :i', ['i' => $open]) === null, "an empty hidden value saves an open shift, as 'Leave open' did ($c)");
[$c, $b] = act($priya, '/exchanges/give.php', ['shift' => $mine, 'colleague' => 30, 'note' => 'SMOKE picker']);
ok($c === 200 && (int) one("SELECT count(*) FROM exchanges WHERE shift_id = :s AND kind = 'give' AND to_member_id = 30", ['s' => $mine]) === 1, "a give goes to the colleague posted from the hidden input ($c)");
$vac = typ(102, 'vacation');
[$c, $b] = act($mara, '/time-off/request.php', ['time_off_type' => $vac, 'site' => 102, 'member' => 31, 'from' => today_plus(40), 'to' => today_plus(40)]);
ok($c === 200 && (int) one('SELECT count(*) FROM time_off_requests WHERE member_id = 31 AND type_id = :t', ['t' => $vac]) >= 1, "time off is asked for another person through member= ($c)");
admin_sql($sql);
finish();
