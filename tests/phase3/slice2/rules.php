<?php
/** Proof — the database and the handlers agree (spec "Proof", 2): overlap, hard rules, soft rules and overrides, length, overnight; the publish page's warnings. */
require __DIR__ . '/lib.php';
$W = w2(); reset_people();
$mara = as_member(33); $ana = as_member(30);
$srv = srv(); $bar = (int) $W['aBar'];
$ws = wk(33);
$count = fn () => (int) one("SELECT count(*) FROM shifts WHERE week_id = (SELECT id FROM schedule_weeks WHERE scope_id = 102 AND week_start = :w)", ['w' => $GLOBALS['ws']]);
$mk = fn (array $o) => bp($GLOBALS['mara'], '/shifts/save.php', $o + ['site' => 102, 'position' => $GLOBALS['srv']]);

echo "1. Overlap\n";
$base = fx(102, $srv, 30, $ws, 1, '17:00', '23:00');
$n0 = $count();
[$c, $b] = $mk(['date' => dayn($ws, 1), 'starts' => '20:00', 'ends' => '02:00', 'assignee' => 30]);
ok($c === 422 && str_contains(msg($b), 'SMOKE Ana already has a shift then (Tue') && $count() === $n0, '422 "' . msg($b) . '" — nothing saved');
try { q("INSERT INTO shifts (scope_id, week_id, position_id, starts_at, ends_at, assignee_member_id) SELECT scope_id, week_id, position_id, starts_at + interval '1 hour', ends_at + interval '1 hour', assignee_member_id FROM shifts WHERE id = :i", ['i' => $base]); $code = 'none'; }
catch (PDOException $e) { $code = (string) $e->getCode(); }
ok($code === '23P01', 'and the database refuses the same overlap by itself (the exclusion constraint, SQLSTATE 23P01), whatever a handler does');

echo "2. A hard rule\n";
admin_sql("UPDATE staff_profiles SET is_minor = true, minor_until = current_date + 365 WHERE member_id = 31");
[$c, $b] = $mk(['date' => dayn($ws, 2), 'starts' => '17:00', 'ends' => '23:00', 'assignee' => 31]);
ok($c === 422 && str_contains(msg($b), 'A minor may not work past 22:00.') && $count() === $n0, 'a minor after 22:00: 422 "' . msg($b) . '" — nothing saved');
[$c, $b] = $mk(['date' => dayn($ws, 2), 'starts' => '17:00', 'ends' => '23:00', 'assignee' => 31, 'override_reason' => 'SMOKE I insist']);
ok($c === 422 && $count() === $n0 && (int) one('SELECT count(*) FROM rule_overrides') === 0, 'a reason does not buy past a hard rule: still 422, no override row');
[$c, $b] = $mk(['date' => dayn($ws, 2), 'starts' => '17:00', 'ends' => '21:00', 'assignee' => 31]);
ok($c === 200, 'the same minor before 22:00 saves');
admin_sql("UPDATE staff_profiles SET is_minor = false, minor_until = NULL WHERE member_id = 31");
[$c, $b] = $mk(['position' => $bar, 'date' => dayn($ws, 3), 'starts' => '17:00', 'ends' => '21:00', 'assignee' => 26]);
ok($c === 422 && str_contains(msg($b), 'Does not work this position here.'), 'a position she does not hold (Priya on Bar): 422 "' . msg($b) . '"');
[$c, $b] = $mk(['date' => dayn($ws, 3), 'starts' => '17:00', 'ends' => '21:00', 'assignee' => 34]);
ok($c === 422 && msg($b) === 'That person does not work at this restaurant.', 'a person of another restaurant (Joe, Downtown): 422 "' . msg($b) . '"');
[$c, $b] = $mk(['position' => 99999, 'date' => dayn($ws, 3), 'starts' => '17:00', 'ends' => '21:00']);
ok($c === 422 && msg($b) === 'Pick one of this restaurant\'s positions.', 'a position that is not the restaurant\'s: 422');
$dPos = (int) $W['dSrv'];
[$c, $b] = $mk(['position' => $dPos, 'date' => dayn($ws, 3), 'starts' => '17:00', 'ends' => '21:00']);
ok($c === 422 && msg($b) === 'Pick one of this restaurant\'s positions.', 'a Downtown position at Airport: 422 too');

echo "3. A soft rule, and the override\n";
$prev = fx(102, $srv, 30, $ws, 3, '17:00', '23:00');
$n1 = $count();
[$c, $b] = $mk(['date' => dayn($ws, 4), 'starts' => '07:00', 'ends' => '12:00', 'assignee' => 30]);
ok($c === 422 && str_contains(msg($b), 'Less than 10 hours between shifts.') && str_contains(msg($b), 'override_reason') && $count() === $n1, 'less than 10 hours\' rest: 422 until a reason comes — "' . msg($b) . '"');
$since = last_activity_id();
[$c, $b] = $mk(['date' => dayn($ws, 4), 'starts' => '07:00', 'ends' => '12:00', 'assignee' => 30, 'override_reason' => 'SMOKE covering for a sick colleague']);
ok($c === 200 && $count() === $n1 + 1, 'with override_reason the shift is saved');
$sid = (int) $b['record_id'];
$ov = q('SELECT * FROM rule_overrides WHERE shift_id = :s ORDER BY id', ['s' => $sid]);
ok(count($ov) === 1 && $ov[0]['rule_key'] === 'min_rest' && $ov[0]['reason'] === 'SMOKE covering for a sick colleague' && (int) $ov[0]['member_id'] === 30 && (int) $ov[0]['overridden_by'] === 33 && $ov[0]['context'] === 'build' && (int) $ov[0]['scope_id'] === 102, 'one rule_overrides row: rule, reason, the person, the manager, context build, the site');
$lg = activity('rule.override', $since);
ok(count($lg) === 1 && (int) $lg[0]['scope_id'] === 102 && (int) $lg[0]['entity_id'] === $sid, 'and one rule.override log row with the site');
$row = jshifts($mara, 102, $ws)[$sid] ?? [];
ok(count($row['warnings'] ?? []) === 1 && str_contains($row['warnings'][0], 'Less than 10 hours'), 'the builder marks the shift with its sentence');
$page = page($mara, "/builder?site=102&week=$ws")['body'];
ok(str_contains($page, 'id="builder-shift-' . $sid . '-warn"') && str_contains($page, 'id="builder-warnings"'), 'the ⚠ chip is on its block and the week lists the warning');
// two warnings at once: a long shift with no break for a person with a weekly limit
admin_sql("UPDATE staff_profiles SET max_hours_week = 8 WHERE member_id = 30");
$since = last_activity_id();
$starts = new DateTimeImmutable(dayn($ws, 5) . ' 09:00', new DateTimeZone(TZA)); $ends = $starts->modify('+10 hours');
$expected = (int) one("SELECT count(*) FROM ts_assignment_warnings(30, 102, :p, :a, :b, 0, NULL) WHERE severity = 'soft'", ['p' => $srv, 'a' => $starts->format('c'), 'b' => $ends->format('c')]);
[$c, $b] = $mk(['date' => dayn($ws, 5), 'starts' => '09:00', 'ends' => '19:00', 'assignee' => 30, 'override_reason' => 'SMOKE both are fine today']);
$sid2 = (int) ($b['record_id'] ?? 0);
ok($c === 200 && $expected >= 2 && (int) one('SELECT count(*) FROM rule_overrides WHERE shift_id = :s', ['s' => $sid2]) === $expected && count(activity('rule.override', $since)) === $expected, "two or more soft warnings ($expected) → one rule_overrides row and one rule.override row EACH, the same reason");
admin_sql("UPDATE staff_profiles SET max_hours_week = NULL WHERE member_id = 30");

echo "4. Length and the day it ends\n";
$n2 = $count();
[$c, $b] = $mk(['starts_at' => at($ws, 6, '06:00'), 'ends_at' => at($ws, 6, '23:00')]);
ok($c === 422 && msg($b) === 'A shift can be at most 16 hours.' && $count() === $n2, '17 hours: 422 "' . msg($b) . '"');
[$c, $b] = $mk(['starts_at' => at($ws, 6, '17:00'), 'ends_at' => at($ws, 6, '09:00')]);
ok($c === 422 && msg($b) === 'A shift must end after it starts.', 'ending before it starts on the same day (full dates, no overnight): 422 "' . msg($b) . '"');
[$c, $b] = $mk(['date' => dayn($ws, 6), 'starts' => '17:00', 'ends' => '17:00']);
ok($c === 422 && msg($b) === 'A shift can be at most 16 hours.', 'the form\'s start = end means the next day: 24 hours: 422');
[$c, $b] = $mk(['date' => dayn($ws, 6), 'starts' => '17:10', 'ends' => '21:00']);
ok($c === 422 && msg($b) === 'Times go in 15-minute steps.', 'a minute that is not a quarter hour: 422');
[$c, $b] = $mk(['date' => dayn($ws, 6), 'starts' => '17:00', 'ends' => '21:00', 'break' => '25']);
ok($c === 422 && msg($b) === 'A break is 0, 15, 30, 45 or 60 minutes.', 'a break of 25: 422');
ok($count() === $n2, 'none of the refusals saved anything');
try { q("INSERT INTO shifts (scope_id, week_id, position_id, starts_at, ends_at) SELECT scope_id, week_id, position_id, starts_at, starts_at + interval '17 hours' FROM shifts WHERE id = :i", ['i' => $base]); $code = 'none'; }
catch (PDOException $e) { $code = (string) $e->getCode(); }
ok($code === '23514', 'the database refuses 17 hours by itself (CHECK, SQLSTATE 23514)');

echo "5. Overnight, in the restaurant's zone\n";
$mon = $ws;
[$c, $b] = $mk(['date' => dayn($mon, 0), 'starts' => '22:00', 'ends' => '02:00', 'assignee' => 31]);
$night = (int) ($b['record_id'] ?? 0);
ok($c === 200, 'a shift 22:00–02:00 saves (an end before the start is the next day)');
$r = q('SELECT starts_at, ends_at, paid_hours FROM (SELECT starts_at, ends_at, round(EXTRACT(EPOCH FROM (ends_at - starts_at)) / 3600, 2) AS paid_hours FROM shifts WHERE id = :i) x', ['i' => $night])[0];
$loc = fn (string $t) => (new DateTimeImmutable($t))->setTimezone(new DateTimeZone(TZA))->format('Y-m-d H:i');
ok($loc($r['starts_at']) === dayn($mon, 0) . ' 22:00' && $loc($r['ends_at']) === dayn($mon, 1) . ' 02:00' && (float) $r['paid_hours'] === 4.0, 'stored as Monday 22:00 to Tuesday 02:00 Chicago time (4 hours)');
$page = page($mara, "/builder?site=102&week=$ws&view=people")['body'];
ok(preg_match('/id="builder-cell-m31-' . dayn($mon, 0) . '"[^>]*>(?:(?!<\/td>).)*id="builder-shift-' . $night . '"/s', $page) === 1 && !preg_match('/id="builder-cell-m31-' . dayn($mon, 1) . '"[^>]*>(?:(?!<\/td>).)*id="builder-shift-' . $night . '"/s', $page), 'the builder puts the block in Monday\'s cell for Lee, not Tuesday\'s');
[, $d1] = screen($mara, '/builder/day?site=102&date=' . dayn($mon, 0)); [, $d2] = screen($mara, '/builder/day?site=102&date=' . dayn($mon, 1));
$has = fn ($d) => in_array($night, array_map(fn ($s) => $s['shift_id'], array_merge(...array_map(fn ($g) => $g['shifts'], $d['positions'] ?? [[ 'shifts' => [] ]]))), true);
ok($has($d1) && $has($d2), 'the day view shows it on Monday AND (its last two hours) on Tuesday');
$since = last_activity_id();
[$c, $b] = $mk(['shift' => $night, 'starts_at' => at($mon, 0, '22:00')]);
ok($c === 422 && msg($b) === 'Nothing changed.', 'saying the start it already has: 422 "Nothing changed."');
[$c, $b] = $mk(['shift' => $night, 'note' => 'SMOKE side work']);
ok($c === 200 && (new DateTimeImmutable((string) one('SELECT ends_at FROM shifts WHERE id = :i', ['i' => $night])))->setTimezone(new DateTimeZone(TZA))->format('Y-m-d H:i') === dayn($mon, 1) . ' 02:00' && (string) one('SELECT note FROM shifts WHERE id = :i', ['i' => $night]) === 'SMOKE side work', 'shift_update with only a note: an absent field keeps what the shift has (times, person, position)');
[$c, $b] = $mk(['shift' => $night, 'date' => dayn($mon, 5)]);
$moved = q('SELECT starts_at, ends_at, assignee_member_id FROM shifts WHERE id = :i', ['i' => $night])[0];
ok($c === 200 && $loc($moved['starts_at']) === dayn($mon, 5) . ' 22:00' && $loc($moved['ends_at']) === dayn($mon, 6) . ' 02:00' && (int) $moved['assignee_member_id'] === 31, 'a lone date MOVES the shift to that day keeping its times (overnight too) and its person' . ($c === 200 ? '' : ' — ' . msg($b)));

echo "6. The publish page asks the rules again\n";
$w2 = wk(34);
fx(102, $srv, 30, $w2, 3, '17:00', '23:00'); fx(102, $srv, 30, $w2, 4, '07:00', '12:00');
$wid = week_id_of(102, $w2);
[$c, $sum] = screen($mara, "/weeks/publish-confirm?site=102&week=$w2");
ok($c === 200 && $sum['soft'] >= 1 && $sum['hard'] === 0, 'the summary lists ' . $sum['soft'] . ' soft warning(s) (a database fixture that skipped the handler\'s check)');
$page = page($mara, "/weeks/publish-confirm?site=102&week=$w2")['body'];
ok(str_contains($page, 'id="publish-warning-0"') && str_contains($page, 'Less than 10 hours between shifts.') && str_contains($page, 'publish-form-field-override-reason'), 'the page shows each warning with the person and rule, and a reason box');
[$c, $b] = bp($mara, '/weeks/publish.php', ['week' => $wid]);
ok($c === 422 && str_contains(msg($b), 'Give a reason (override_reason) to publish anyway.') && week_status(102, $w2) === 'draft', 'publish without a reason: 422 — "' . msg($b) . '"');
admin_sql("UPDATE site_rules SET severity = 'hard' WHERE scope_id = 102 AND rule_key = 'min_rest'");
[$c, $b] = bp($mara, '/weeks/publish.php', ['week' => $wid, 'override_reason' => 'SMOKE go']);
ok($c === 422 && str_contains(msg($b), 'Fix this before publishing') && week_status(102, $w2) === 'draft', 'the rule made HARD since: a reason no longer buys it — 422 "' . msg($b) . '"');
admin_sql("UPDATE site_rules SET severity = 'soft' WHERE scope_id = 102 AND rule_key = 'min_rest'");
$since = last_activity_id();
[$c, $b] = bp($mara, '/weeks/publish.php', ['week' => $wid, 'override_reason' => 'SMOKE the team agreed']);
ok($c === 200 && week_status(102, $w2) === 'published', 'with a reason: published');
$ovs = q("SELECT * FROM rule_overrides WHERE context = 'publish' AND shift_id IN (SELECT id FROM shifts WHERE week_id = :w)", ['w' => $wid]);
$ovLogs = activity('rule.override', $since); $pub = activity('week.publish', $since);
ok(count($ovs) === (int) $b['overridden'] && count($ovs) >= 1 && count($ovLogs) === count($ovs) && count($pub) === 1, 'one rule_overrides row (context publish) and one rule.override log row per warning: ' . count($ovs));
ok(max(array_map(fn ($r) => (int) $r['id'], $ovLogs)) < (int) $pub[0]['id'], 'every rule.override row is written BEFORE week.publish');
$after = json_decode((string) $pub[0]['after'], true);
ok($after['overridden'] === count($ovs) && count($after['warnings']) === count($ovs) && isset($after['warnings'][0]['rule_key']), 'week.publish carries the overridden count and the warnings\' rules');
finish();
