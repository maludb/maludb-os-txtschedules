<?php
/** Proof — time zones (spec "Proof", 10): a Downtown (New York) and an Airport (Chicago) shift, each in its own zone, the zone named when a person holds both. */
require __DIR__ . '/lib.php';
$W = world();
$marco = as_member(27, 101); $priya = as_member(26);
$want = function (int $shift, string $tz): string {
    $r = q('SELECT starts_at, ends_at FROM shifts WHERE id = :s', ['s' => $shift])[0];
    $a = (new DateTimeImmutable($r['starts_at']))->setTimezone(new DateTimeZone($tz)); $b = (new DateTimeImmutable($r['ends_at']))->setTimezone(new DateTimeZone($tz));
    return $a->format('D M j') . ' · ' . $a->format('g:i') . ($a->format('a') === $b->format('a') ? '' : ' ' . $a->format('a')) . '–' . $b->format('g:i a') . ' ' . $a->format('T');
};
[$c, $d] = screen($marco, '/my-schedule?view=list');
$by = array_column($d['shifts'], null, 'shift_id');
ok(isset($by[$W['m1']], $by[$W['m2']]), 'Marco holds a Downtown shift and an Airport shift');
ok($by[$W['m1']]['when'] === $want($W['m1'], 'America/New_York') && str_ends_with($by[$W['m1']]['when'], 'EDT'), 'the Downtown shift in New York time with its zone: "' . $by[$W['m1']]['when'] . '"');
ok($by[$W['m2']]['when'] === $want($W['m2'], 'America/Chicago') && str_ends_with($by[$W['m2']]['when'], 'CDT'), 'the Airport shift in Chicago time with its zone: "' . $by[$W['m2']]['when'] . '"');
$page = html_entity_decode(page($marco, '/my-schedule?view=list')['body']);
ok(str_contains($page, $want($W['m1'], 'America/New_York')) && str_contains($page, $want($W['m2'], 'America/Chicago')), 'the same words are on the rendered page');
ok(str_contains($page, 'SMOKE Downtown') && str_contains($page, 'SMOKE Airport'), 'and the restaurant\'s name on each card (he holds more than one)');
$page = html_entity_decode(page($priya, '/my-schedule?view=list')['body']);
ok(!preg_match('/\b(CDT|CST|EDT|EST)\b/', explode('<footer', explode('id="page-content"', $page)[1] ?? '')[0]) && !str_contains(explode('id="my-schedule-list"', $page)[1] ?? '', 'SMOKE Airport ·'), 'Priya holds one restaurant: no zone name and no restaurant name on her cards');
$d1 = page($marco, '/team-schedule?site=101')['body'];
ok(str_contains(html_entity_decode($d1), 'EDT') && !str_contains(html_entity_decode(explode('team-schedule-day-title', $d1)[1] ?? ''), 'CDT'), 'the team schedule of Downtown names EDT');
$d2 = page($marco, '/team-schedule?site=102')['body'];
ok(str_contains(html_entity_decode(explode('team-schedule-day-title', $d2)[1] ?? ''), 'CDT'), 'and Airport\'s names CDT');
finish();
