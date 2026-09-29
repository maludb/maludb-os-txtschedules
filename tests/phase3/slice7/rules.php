<?php
/**
 * Proof — the rules act (spec "Proof": rules act; certification rule; the overrides list; rights): every change goes through rule_save / rule_preset_apply; the builder's next question obeys it (hard refuses, soft asks
 * for a reason, off is silent, a value moves the line); bad values are 422; the preset restores; Downtown's admin cannot touch Airport's rules; the cert_required hard/soft proof owed by slice 4 now uses rule_save.
 */
require __DIR__ . '/lib.php';
$W = reset7();
$owner = admin_jar(102); $mara = as_member(33); $pat = as_planner(); $priya = as_member(26); $dana = as_member(32); $dee = as_dee(); $dex = as_dex(); $sol = as_setter();
$srv = $W['aSrv'];
$KEYS = ['position_not_held', 'time_off', 'unavailable', 'min_rest', 'max_hours_day', 'overtime_week', 'max_hours_person', 'break_required', 'minor_hours_day', 'minor_hours_week', 'minor_latest_end', 'cert_required'];

echo "1. The screen\n";
[$c, $d] = screen($owner, '/rules/?site=102');
ok($c === 200 && array_column($d['rules'], 'rule') === $KEYS, 'the admin opens the rules: the engine\'s twelve, in order');
$byKey = array_column($d['rules'], null, 'rule');
ok($byKey['min_rest']['severity'] === 'soft' && $byKey['position_not_held']['severity'] === 'hard' && $byKey['minor_latest_end']['values'][0]['value'] === '22:00' && $byKey['min_rest']['values'][0]['value'] === 10 && $byKey['break_required']['values'][1]['name'] === 'minutes', 'severities and values as the Generic preset ships them (rest 10 h, a minor until 22:00, break 30 minutes after 6 h)');
ok($d['can_change'] === true && str_contains($d['disclaimer'], 'does not guarantee legal compliance') && $d['preset'] === 'generic', 'can_change, the disclaimer and the preset in the answer');
$html = page($owner, '/rules/?site=102')['body'];
ok(str_contains($html, 'id="rules-header"') && str_contains($html, 'txtSchedules helps you follow your own rules; it does not guarantee legal compliance.') && str_contains($html, 'id="rule-form-min_rest-field-severity"') && str_contains($html, 'id="rules-preset-apply-btn"') && !str_contains($html, 'is not built yet'), 'the page says the compliance sentence, has a severity selector per rule and the preset button');
ok(!preg_match('/fair.?workweek/i', $html), 'fair workweek is not offered (D8)');
foreach (['Mara (manager)' => $mara, 'Pat (builds, no settings)' => $pat] as $who => $jar) {
    $h = page($jar, '/rules/?site=102');
    [, $j] = screen($jar, '/rules/?site=102');
    ok($h['code'] === 200 && !str_contains($h['body'], 'id="rule-form-min_rest"') && !str_contains($h['body'], 'rules-preset-apply-btn') && $j['can_change'] === false && count($j['rules']) === 12, "$who reads the rules (200) with no forms and no preset button; can_change false");
}
ok(req('GET', '/rules/?site=102', ['jar' => $priya])['code'] === 403 && req('GET', '/rules/?site=102', ['jar' => $dana])['code'] === 403 && req('GET', '/rules/?site=102', ['jar' => $dee])['code'] === 404 && req('GET', '/rules/?site=102', ['headers' => JSONH])['code'] === 401, 'staff and a shift lead: 403; a manager of another restaurant: 404; anonymous: 401');

echo "2. Making min_rest hard turns the builder's soft warning into a refusal; off removes it; a value moves the line\n";
$ws = wk(170);
fx(102, $srv, 31, $ws, 1, '17:00', '23:00');                                           // Lee works Tuesday evening, ends 23:00
$open = fx(102, $srv, null, $ws, 2, '06:00', '12:00');                                 // Wednesday 06:00 — 7 hours of rest
$try = fn (?string $reason = null) => act($owner, '/shifts/assign.php', ['shift' => $open, 'assignee' => 31] + ($reason ? ['override_reason' => $reason] : []));
$free = fn () => admin_sql("UPDATE shifts SET assignee_member_id = NULL WHERE id = $open");
[$c, $b] = $try();
ok($c === 422 && str_contains(msg($b), 'Less than 10 hours between shifts.') && str_contains(msg($b), 'override_reason'), 'soft (as shipped): 7 hours\' rest — 422 until a reason comes: "' . msg($b) . '"');
$since = last_activity_id();
[$c, $b] = rule($owner, 102, 'min_rest', 'hard');
ok($c === 200 && $b['changed'] === true && $b['severity'] === 'hard' && ($b['params']['hours'] ?? null) === 10 && str_contains($b['location'], '#rule-min_rest') && str_contains($b['location'], 'notice=rl_saved'), 'rule_save min_rest hard: 200; the reply carries the severity and values; the location ends in the rule\'s anchor');
ok(rule_of(102, 'min_rest') === ['severity' => 'hard', 'params' => ['hours' => 10]], 'the row: hard, hours 10 (values kept when none are sent)');
[$c, $b] = $try('SMOKE s7 let me');
ok($c === 422 && str_contains(msg($b), 'Less than 10 hours between shifts.') && !str_contains(msg($b), 'override_reason') && holder_of($open) === null, 'hard: even with a reason the assignment is refused — no override on offer: "' . msg($b) . '"');
$chk = req('GET', "/shifts/check.php?site=102&starts_at=" . urlencode(at($ws, 2, '06:00')) . "&ends_at=" . urlencode(at($ws, 2, '12:00')) . "&position=$srv&assignee=31", ['jar' => $owner])['body'];
ok(str_contains(html_entity_decode($chk), 'Less than 10 hours between shifts.'), 'the shift form\'s live check names it too');
$row = q("SELECT source, scope_id, actor_member_id, before, after FROM activity_log WHERE action = 'rule.update' AND id > :s", ['s' => $since]);
ok(count($row) === 1 && (int) $row[0]['scope_id'] === 102 && $row[0]['source'] === 'web' && json_decode($row[0]['before'], true) == ['rule' => 'min_rest', 'severity' => 'soft', 'params' => ['hours' => 10]] && json_decode($row[0]['after'], true) == ['rule' => 'min_rest', 'severity' => 'hard', 'params' => ['hours' => 10]], 'logged rule.update with the site: before soft, after hard (rule, severity, values)');
$since = last_activity_id();
[$c, $b] = rule($owner, 102, 'min_rest', 'hard');
ok($c === 200 && $b['changed'] === false && str_contains($b['did'], 'Nothing changed') && (int) one("SELECT count(*) FROM activity_log WHERE action = 'rule.update' AND id > :s", ['s' => $since]) === 0, 'the same again: "Nothing changed" and nothing logged');
[$c, $b] = rule($owner, 102, 'min_rest', 'off');
[$c2, $b2] = $try();
ok($b['severity'] === 'off' && $c2 === 200 && holder_of($open) === 31, 'off: the same assignment goes through with no warning and no reason');
$free();
[$c, $b] = rule($owner, 102, 'min_rest', 'soft', ['hours' => 6]);
[$c2, $b2] = $try();
ok($c === 200 && rule_of(102, 'min_rest') === ['severity' => 'soft', 'params' => ['hours' => 6]] && $c2 === 200, 'soft with 6 hours: 7 hours of rest is enough — no warning');
$free();
[$c, $b] = rule($owner, 102, 'min_rest', 'soft', ['hours' => 8]);
[$c2, $b2] = $try();
ok($c2 === 422 && str_contains(msg($b2), 'Less than 8 hours between shifts.'), 'soft with 8 hours: warns again, in the new number: "' . msg($b2) . '"');
[$c, $b] = $try('SMOKE s7 the crew knows');
ok($c === 200 && holder_of($open) === 31, 'with a reason: assigned');
$free();
[$c, $b] = act($owner, '/rules/save.php', ['site' => 102, 'rule' => 'min_rest', 'hours' => '9']);
ok($c === 200 && rule_of(102, 'min_rest')['params'] === ['hours' => 9] && rule_of(102, 'min_rest')['severity'] === 'soft', 'the value names sent flat (hours=9) work too, and the severity left out stays');
[$c, $b] = act($owner, '/rules/save.php', ['site' => 102, 'rule' => 'min_rest', 'params' => '{"hours": 11}', 'severity' => 'soft']);
ok($c === 200 && rule_of(102, 'min_rest')['params'] === ['hours' => 11], 'and params as a JSON object (an agent\'s way): 11');
[$c, $b] = rule($owner, 102, 'break_required', 'soft', ['after_hours' => '5.5', 'minutes' => '20']);
ok($c === 200 && rule_of(102, 'break_required')['params'] == ['after_hours' => 5.5, 'minutes' => 20], 'two values on one rule (break after 5.5 hours of 20 minutes) stored as numbers');
[$c, $b] = rule($owner, 102, 'minor_latest_end', 'hard', ['time' => '23:30']);
ok($c === 200 && rule_of(102, 'minor_latest_end')['params'] === ['time' => '23:30'], 'a time saved as HH:MM');

echo "3. Bad values are refused, in words, and nothing is saved\n";
$snap = json_encode(q('SELECT rule_key, severity, params FROM site_rules WHERE scope_id = 102 ORDER BY rule_key'));
$cases = [['minor_latest_end', 'hard', ['time' => '25:99'], 'A minor may work until is a time of day like 22:00.'], ['minor_latest_end', 'hard', ['time' => 'late'], null], ['min_rest', 'soft', ['hours' => '-1'], null], ['min_rest', 'soft', ['hours' => '0'], null],
          ['min_rest', 'soft', ['hours' => '49'], null], ['min_rest', 'soft', ['hours' => '10.5'], 'Hours of rest is a whole number of hours from 1 to 48.'], ['min_rest', 'soft', ['hours' => 'ten'], null], ['max_hours_day', 'soft', ['hours' => '25'], null],
          ['break_required', 'soft', ['minutes' => '0'], null], ['break_required', 'soft', ['minutes' => '30.5'], null], ['min_rest', 'medium', [], 'A rule is hard, soft or off.'], ['min_rest', 'soft', ['days' => '3'], null],
          ['position_not_held', 'hard', ['hours' => '3'], 'This rule has no values to set.'], ['no_such_rule', 'soft', [], 'There is no rule called no_such_rule.'], ['min_rest', 'soft', ['hours' => ['1']], null]];
$miss = [];
foreach ($cases as [$k, $sv, $pr, $words]) {
    [$c, $b] = act($owner, '/rules/save.php', ['site' => 102, 'rule' => $k, 'severity' => $sv] + ($pr ? ['params' => $pr] : []));
    if ($c !== 422 || msg($b) === '' || ($words !== null && msg($b) !== $words)) { $miss[] = "$k " . json_encode($pr) . " → $c " . msg($b); }
}
ok($miss === [] && json_encode(q('SELECT rule_key, severity, params FROM site_rules WHERE scope_id = 102 ORDER BY rule_key')) === $snap, count($cases) . ' bad calls (25:99, negative, zero, too big, 10.5 where the engine needs whole hours, words, an unknown value, an unknown rule, a severity of "medium") are each 422 and none saved' . ($miss ? ' — ' . implode('; ', $miss) : ''));
[$c, $b] = act($owner, '/rules/save.php', ['site' => 102, 'severity' => 'soft']);
ok($c === 422 && msg($b) === 'Say which rule.', 'no rule named: 422 "Say which rule."');

echo "4. Apply the Generic preset\n";
[$c, $b] = rule($owner, 102, 'position_not_held', 'off');
[$c, $b] = rule($owner, 102, 'overtime_week', 'hard');
$since = last_activity_id();
$changedNow = (int) one("SELECT count(*) FROM site_rules r, rule_presets p, jsonb_each(p.rules) e WHERE p.key = 'generic' AND r.scope_id = 102 AND r.rule_key = e.key AND (r.severity <> e.value->>'severity' OR r.params <> COALESCE(e.value->'params', '{}'::jsonb))");
[$c, $b] = act($owner, '/rules/preset.php', ['site' => 102, 'preset' => 'generic']);
ok($c === 200 && $b['rules_changed'] === $changedNow && $changedNow >= 5 && str_contains($b['location'], 'notice=rl_preset'), 'Apply preset: 200, ' . $changedNow . ' rules changed (position not held, overtime, rest, break, minor time …)');
$wrong = (int) one("SELECT count(*) FROM site_rules r, rule_presets p, jsonb_each(p.rules) e WHERE p.key = 'generic' AND r.scope_id = 102 AND r.rule_key = e.key AND (r.severity <> e.value->>'severity' OR r.params <> COALESCE(e.value->'params', '{}'::jsonb))");
ok($wrong === 0 && rule_of(102, 'position_not_held')['severity'] === 'hard' && rule_of(102, 'min_rest') === ['severity' => 'soft', 'params' => ['hours' => 10]], 'every rule is back to the preset\'s severity and values');
$row = q("SELECT scope_id, after FROM activity_log WHERE action = 'rule.preset' AND id > :s", ['s' => $since]);
$aft = json_decode($row[0]['after'] ?? '{}', true);
ok(count($row) === 1 && (int) $row[0]['scope_id'] === 102 && $aft['preset'] === 'generic' && $aft['rules_changed'] === $changedNow && count($aft['rules']) === $changedNow, 'logged rule.preset: the preset and how many rules changed');
[$c, $b] = act($owner, '/rules/preset.php', ['site' => 102, 'preset' => 'generic']);
ok($c === 200 && $b['rules_changed'] === 0, 'again: 0 rules changed');
[$c, $b] = act($owner, '/rules/preset.php', ['site' => 102, 'preset' => 'fair_workweek']);
ok($c === 422 && msg($b) === 'There is no preset called fair_workweek.', 'a preset that is not in the table (fair workweek, D8): 422');
admin_sql("INSERT INTO rule_presets (key, name, description, rules) VALUES ('smoke_strict', 'SMOKE Strict', 'a proof preset', '{\"min_rest\": {\"severity\": \"hard\", \"params\": {\"hours\": 12}}}')");
[$c, $b] = act($owner, '/rules/preset.php', ['site' => 102, 'preset' => 'smoke_strict']);
ok($c === 200 && $b['rules_changed'] === 1 && rule_of(102, 'min_rest') === ['severity' => 'hard', 'params' => ['hours' => 12]] && one("SELECT rule_preset FROM site_settings WHERE scope_id = 102") === 'smoke_strict', 'another preset is a row, not code: applying it changes the one rule it names and records the preset');
act($owner, '/rules/preset.php', ['site' => 102, 'preset' => 'generic']);
admin_sql("DELETE FROM rule_presets WHERE key = 'smoke_strict'");
ok(one("SELECT rule_preset FROM site_settings WHERE scope_id = 102") === 'generic', 'and Generic puts it back');

echo "5. Rights and the other restaurant\n";
$before = json_encode(q('SELECT rule_key, severity, params FROM site_rules ORDER BY scope_id, rule_key'));
foreach (['Mara' => $mara, 'Pat' => $pat, 'Dana' => $dana, 'Priya' => $priya] as $who => $jar) {
    [$c1, $b1] = rule($jar, 102, 'min_rest', 'off'); [$c2] = act($jar, '/rules/preset.php', ['site' => 102, 'preset' => 'generic']);
    ok($c1 === 403 && $c2 === 403 && msg($b1) === 'You may not change this restaurant\'s settings here.', "$who: rule_save and rule_preset_apply → 403");
}
[$c1] = rule($dex, 102, 'min_rest', 'off'); [$c2] = act($dex, '/rules/preset.php', ['site' => 102, 'preset' => 'generic']); [$c3] = req('GET', '/rules/?site=102', ['jar' => $dex])['code'] === 404 ? [404] : [0];
ok($c1 === 404 && $c2 === 404 && $c3 === 404, 'an admin of Downtown cannot change, reset or even read Airport\'s rules: 404');
[$c1] = rule($dee, 101, 'min_rest', 'off');
ok($c1 === 403, 'Dee, a Downtown manager without settings.manage: 403 there too');
[$c, $b] = rule($dex, 101, 'min_rest', 'off');
ok($c === 200 && rule_of(101, 'min_rest')['severity'] === 'off' && rule_of(102, 'min_rest')['severity'] === 'soft', 'Downtown\'s admin changes Downtown\'s min_rest only: off there, still soft at Airport');
rule($dex, 101, 'min_rest', 'soft');
[$c, $b] = rule($sol, 102, 'time_off', 'soft');
ok($c === 200 && rule_of(102, 'time_off')['severity'] === 'soft', 'Sol (settings.manage, not an admin role) may');
rule($sol, 102, 'time_off', 'hard');
$r = req('POST', '/rules/save.php', ['jar' => $owner, 'headers' => JSONH, 'form' => ['site' => 102, 'rule' => 'min_rest', 'severity' => 'off']]);
ok($r['code'] === 403 && req('POST', '/rules/save.php', ['headers' => JSONH, 'form' => ['site' => 102]])['code'] === 401 && req('GET', '/rules/save.php?site=102', ['jar' => $owner, 'headers' => JSONH])['code'] === 405 && req('GET', '/rules/preset.php?site=102', ['jar' => $owner, 'headers' => JSONH])['code'] === 405, 'no CSRF token: 403; no session: 401; a GET: 405');
ok(json_encode(q('SELECT rule_key, severity, params FROM site_rules ORDER BY scope_id, rule_key')) === $before, 'and no refused call changed a rule anywhere');

echo "6. The certification rule, through rule_save (owed by slice 4)\n";
$fh = kind(102, 'food_handler'); $aso = kind(102, 'alcohol_service');
[$c, $b] = act($owner, '/positions/save.php', ['position' => $srv, 'certifications' => [$fh]]);
ok($c === 200, 'Server needs a food handler card');
$ws2 = wk(171);
$sid = fx(102, $srv, null, $ws2, 2, '17:00', '23:00');
rule($owner, 102, 'cert_required', 'hard');
[$c, $b] = act($owner, '/shifts/assign.php', ['shift' => $sid, 'assignee' => 31]);
ok($c === 422 && str_contains(msg($b), 'Certification: Food handler missing') && !str_contains(msg($b), 'override_reason') && holder_of($sid) === null, 'rule_save cert_required hard: the builder refuses a shift for Lee, who has no card — "' . msg($b) . '"');
rule($owner, 102, 'cert_required', 'soft');
[$c, $b] = act($owner, '/shifts/assign.php', ['shift' => $sid, 'assignee' => 31]);
ok($c === 422 && str_contains(msg($b), 'Certification: Food handler missing') && str_contains(msg($b), 'override_reason'), 'soft: it warns and asks for a reason — "' . msg($b) . '"');
[$c, $b] = act($owner, '/shifts/assign.php', ['shift' => $sid, 'assignee' => 31, 'override_reason' => 'SMOKE s7 card is on its way']);
ok($c === 200 && holder_of($sid) === 31, 'soft with a reason: assigned');
$ov = q("SELECT o.rule_key, o.reason, o.overridden_by, o.member_id, o.context, o.scope_id FROM rule_overrides o WHERE o.reason = 'SMOKE s7 card is on its way'");
ok(count($ov) === 1 && $ov[0]['rule_key'] === 'cert_required' && (int) $ov[0]['overridden_by'] === 1 && (int) $ov[0]['member_id'] === 31 && $ov[0]['context'] === 'build' && (int) $ov[0]['scope_id'] === 102, 'the override is recorded: who, about whom, which rule, where');
rule($owner, 102, 'cert_required', 'off');
admin_sql("UPDATE shifts SET assignee_member_id = NULL WHERE id = $sid");
[$c, $b] = act($owner, '/shifts/assign.php', ['shift' => $sid, 'assignee' => 31]);
ok($c === 200, 'off: no warning at all');
admin_sql("UPDATE shifts SET assignee_member_id = NULL WHERE id = $sid");
rule($owner, 102, 'cert_required', 'soft');
$card = page($owner, '/rules/?site=102')['body'];
ok(str_contains(html_entity_decode($card), 'This restaurant\'s kinds: Alcohol service, Food handler.'), 'the rule\'s card names the restaurant\'s own kinds: "This restaurant\'s kinds: Alcohol service, Food handler."');
[$c, $b] = act($owner, '/certifications/kinds/save.php', ['site' => 102, 'name' => 'SMOKE Allergen course']);
$card = html_entity_decode(page($owner, '/rules/?site=102')['body']);
ok($c === 200 && str_contains($card, 'Alcohol service, Food handler, SMOKE Allergen course.'), 'add a kind of your own and the sentence lists it');
$dd = html_entity_decode(page($dex, '/rules/?site=101')['body']);
ok(!str_contains($dd, 'SMOKE Allergen course') && str_contains($dd, 'Alcohol service, Food handler.'), 'and Downtown\'s card lists Downtown\'s kinds only');

echo "7. The overrides list\n";
$rows = screen($owner, '/rules/?site=102')[1]['overrides'];
$mine = array_values(array_filter($rows, fn ($o) => str_starts_with($o['reason'], 'SMOKE s7')));
$reasons = array_column($mine, 'reason');
ok(in_array('SMOKE s7 card is on its way', $reasons, true) && in_array('SMOKE s7 the crew knows', $reasons, true) && in_array('SMOKE s7 the crew knows', $reasons, true), 'the list shows the reasons managers gave in the builder: ' . implode(' | ', $reasons));
$o = array_values(array_filter($mine, fn ($x) => $x['reason'] === 'SMOKE s7 card is on its way'))[0];
ok($o['rule_name'] === 'Certification' && $o['by'] === 'SMOKE Owner' && $o['person'] === 'SMOKE Lee' && str_contains($o['message'], 'Food handler missing') && $o['context'] === 'build', 'who (the owner), about whom (Lee), which rule (Certification), the warning and where');
$page = html_entity_decode(page($owner, '/rules/?site=102')['body']);
ok(str_contains($page, '“SMOKE s7 card is on its way”') && str_contains($page, 'id="override-' . $o['override_id'] . '-reason"'), 'the page prints the reason');
admin_sql("INSERT INTO rule_overrides (scope_id, shift_id, member_id, rule_key, message, reason, overridden_by, context, created_at) VALUES (102, NULL, 31, 'min_rest', 'old', 'SMOKE s7 forty days ago', 33, 'build', now() - interval '40 days')");
ok(!in_array('SMOKE s7 forty days ago', array_column(screen($owner, '/rules/?site=102')[1]['overrides'], 'reason'), true), 'an override older than 30 days is not on the list');
[, $j] = screen($pat, '/rules/?site=102');
ok(in_array('SMOKE s7 card is on its way', array_column($j['overrides'], 'reason'), true), 'Pat (builds) sees the overrides list; Mara too');
$fixed = q("SELECT id FROM rule_overrides WHERE reason = 'SMOKE s7 forty days ago'");
ok(wage_leaks(json_encode(q("SELECT before, after, route FROM activity_log WHERE action IN ('rule.update', 'rule.preset')"))) === [], 'no rule.update or rule.preset row holds a fixture wage');
reset7();
finish();
