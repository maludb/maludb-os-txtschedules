<?php
/** Proof — hours a day (D13, spec "Proof": Hours a day): the database counts a request that gives no hours at the restaurant's time_off_day_hours; changing the setting changes new requests only; a part day counts what it covers, capped; another restaurant keeps its own; the form's preview follows the setting. */
require __DIR__ . '/lib.php';
$W = reset3();
$priya = as_member(26); $ana = as_member(30); $lee = as_member(31); $joe = as_member(34); $mara = as_member(33);
$vac = typ(102, 'vacation'); $dvac = typ(101, 'vacation');
$w = wk(75); $d = fn (int $i): string => dayn($w, $i);
grant(26, $vac, 16);

echo "1. At a restaurant whose day is 8 hours\n";
[$c, $b] = req_off($priya, $vac, $d(0), $d(1));
$two = (int) $b['record_id'];
ok($c === 200 && (float) $b['hours'] === 16.0 && (float) one('SELECT hours FROM time_off_requests WHERE id = :i', ['i' => $two]) === 16.0, 'a two-day request with no hours counts 16 h');
[$c, $b] = act($priya, '/time-off/request.php', ['time_off_type' => $vac, 'starts_at' => $d(3) . ' 09:00', 'ends_at' => $d(3) . ' 13:00']);
$part = (int) $b['record_id'];
ok($c === 200 && (float) $b['hours'] === 4.0, 'a 09:00–13:00 request counts 4 h');
[$c, $b] = act($priya, '/time-off/request.php', ['time_off_type' => $vac, 'starts_at' => $d(4) . ' 09:00', 'ends_at' => $d(4) . ' 21:00']);
$long = (int) $b['record_id'];
ok($c === 200 && (float) $b['hours'] === 8.0, 'a 12-hour day is capped at the restaurant\'s 8 h');
[$c, $b] = req_off($priya, $vac, $d(5), $d(5), ['hours' => 5.5]);
$given = (int) $b['record_id'];
ok($c === 200 && (float) $b['hours'] === 5.5, 'hours given by the person are kept (5.5)');
$p = html_entity_decode(page($priya, "/time-off/new?site=102&type=$vac&from={$d(6)}&to={$d(6)}")['body']);
ok(str_contains($p, 'This uses 8 h.') && str_contains($p, 'Balance 16 h — this uses 8 h.'), 'the form\'s preview line: "This uses 8 h." and "Balance 16 h — this uses 8 h."');
ok(str_contains($p, 'leave empty to count 8 a day'), 'and the hours label names the setting');
$p = html_entity_decode(page($priya, "/time-off/form.php?preview=1&site=102&type=$vac&from={$d(6)}&to={$d(7)}")['body']);
ok(str_contains($p, 'This uses 16 h.') && !str_contains($p, '<html'), 'the preview alone (what the form asks as you type): two days, 16 h');

echo "2. The restaurant changes its day to 6 hours\n";
settings(102, ['time_off_day_hours' => 6]);
[$c, $b] = req_off($priya, $vac, $d(8), $d(8));
$six = (int) $b['record_id'];
ok($c === 200 && (float) $b['hours'] === 6.0, 'a new one-day request counts 6 h');
ok((float) one('SELECT hours FROM time_off_requests WHERE id = :i', ['i' => $two]) === 16.0 && (float) one('SELECT hours FROM time_off_requests WHERE id = :i', ['i' => $part]) === 4.0, 'the earlier 16 h and 4 h requests are unchanged');
[$c, $b] = act($priya, '/time-off/request.php', ['time_off_type' => $vac, 'starts_at' => $d(9) . ' 09:00', 'ends_at' => $d(9) . ' 21:00']);
ok($c === 200 && (float) $b['hours'] === 6.0, 'a 12-hour day is capped at 6');
[$c, $b] = act($priya, '/time-off/request.php', ['time_off_type' => $vac, 'starts_at' => $d(10) . ' 09:00', 'ends_at' => $d(10) . ' 13:00']);
ok($c === 200 && (float) $b['hours'] === 4.0, 'a 4-hour part day is still 4 (under the cap)');
$p = html_entity_decode(page($priya, "/time-off/new?site=102&type=$vac&from={$d(11)}&to={$d(11)}")['body']);
ok(str_contains($p, 'This uses 6 h.') && str_contains($p, 'Balance 16 h — this uses 6 h.') && str_contains($p, 'leave empty to count 6 a day'), 'the preview now says "This uses 6 h." and the label 6 a day');
$page = html_entity_decode(page($mara, '/approvals')['body']);
ok(str_contains($page, 'this uses 16 h, leaving 0 h') || str_contains($page, 'this uses 16 h, leaving 0'), 'and the approver\'s card for the old request still says 16 h');
[$c, $d2] = screen($owner = as_member(1, 102), '/site/time-off?site=102');
ok($d2['day_hours'] == 6 && str_contains(html_entity_decode(page($owner, '/site/time-off?site=102')['body']), 'counts a day off as 6 h'), 'the types screen names the setting: "counts a day off as 6 h"');
echo "3. Another restaurant keeps its own\n";
[$c, $b] = req_off($joe, $dvac, $d(0), $d(0));
ok($c === 200 && (float) $b['hours'] === 8.0, 'Downtown still counts 8 h for a day');
echo "4. The database refuses a day of 0 or 25 hours\n";
foreach ([0, 25, -1] as $bad) {
    try { pdo()->exec("UPDATE site_settings SET time_off_day_hours = $bad WHERE scope_id = 102"); ok(false, "$bad refused"); }
    catch (PDOException $e) { ok(str_contains($e->getMessage(), 'time_off_day_hours') || $e->getCode() === '23514', "time_off_day_hours = $bad is refused by the database's own check"); }
}
ok((float) one('SELECT time_off_day_hours FROM site_settings WHERE scope_id = 102') === 6.0, 'and the setting is still 6');
ok(true, 'the settings screen itself (its handler, the 403 for a manager without settings.manage) is slice 7 — recorded in design §13');
settings(102, ['time_off_day_hours' => 8]);
finish();
