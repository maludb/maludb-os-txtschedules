<?php
/**
 * Proof — the calendar feed (spec "Proof: Calendar feed"): the link is shown once when made and only its hash is kept; the .ics parses (no calendar library is installed on this host, so a strict
 * RFC 5545 parser below stands in: CRLF, folding, balanced components, required properties, UTC times) and holds only the person's own published shifts — no other name, no pay, no note; Make a new link kills
 * the old one (404); a wrong token, a malformed one and a deactivated person's are 404 too.
 */
require __DIR__ . '/lib.php';
function app_url(string $p = ''): string { return BASE . '/' . ltrim($p, '/'); }
require dirname(__DIR__, 3) . '/app/features/calendar/ics.php';
$W = reset6();
/** A form post as a browser sends it (no Accept: json): answers the response with its Location. */
function bpost(string $jar, string $path, array $form = []): array { $tok = csrf_of(req('GET', '/', ['jar' => $jar])['body']); return req('POST', $path, ['jar' => $jar, 'form' => $form + ['csrf_token' => $tok]]); }
$srv = $W['aSrv'];
$priya = as_member(26); $ana = as_member(30);
/** A strict enough RFC 5545 reader: returns ['errors' => [...], 'events' => [[prop => value]]]. */
function parse_ics(string $raw): array
{
    $errors = []; $events = []; $cur = null; $stack = [];
    if (!str_ends_with($raw, "\r\n") || preg_match('/(?<!\r)\n/', $raw)) { $errors[] = 'lines must end CRLF'; }
    $lines = [];
    foreach (explode("\r\n", rtrim($raw, "\r\n")) as $l) {
        if ($l !== '' && ($l[0] === ' ' || $l[0] === "\t") && $lines) { $lines[count($lines) - 1] .= substr($l, 1); } else { $lines[] = $l; }
        if (strlen($l) > 75) { $errors[] = 'a line over 75 octets: ' . substr($l, 0, 30); }
    }
    foreach ($lines as $l) {
        if (!preg_match('/^([A-Z0-9-]+)((?:;[^:]+)*):(.*)$/s', $l, $m)) { $errors[] = "not a content line: $l"; continue; }
        [$all, $name, $params, $val] = $m;
        if ($name === 'BEGIN') { $stack[] = $val; if ($val === 'VEVENT') { $cur = []; } continue; }
        if ($name === 'END') { $top = array_pop($stack); if ($top !== $val) { $errors[] = "END:$val closes $top"; } if ($val === 'VEVENT') { $events[] = $cur; $cur = null; } continue; }
        if ($cur !== null) { $cur[$name] = $val; } else { $head[$name] = $val; }
    }
    if ($stack !== []) { $errors[] = 'unbalanced components'; }
    $head ??= [];
    if (($head['VERSION'] ?? '') !== '2.0' || !isset($head['PRODID'])) { $errors[] = 'VERSION:2.0 and PRODID are required'; }
    foreach ($events as $i => $e) {
        foreach (['UID', 'DTSTAMP', 'DTSTART', 'DTEND', 'SUMMARY'] as $req) { if (!isset($e[$req])) { $errors[] = "event $i lacks $req"; } }
        foreach (['DTSTAMP', 'DTSTART', 'DTEND'] as $t) { if (isset($e[$t]) && !preg_match('/^\d{8}T\d{6}Z$/', $e[$t])) { $errors[] = "event $i $t is not UTC: {$e[$t]}"; } }
        if (isset($e['DTSTART'], $e['DTEND']) && $e['DTEND'] <= $e['DTSTART']) { $errors[] = "event $i ends before it starts"; }
    }
    return ['errors' => $errors, 'events' => $events, 'head' => $head];
}
ok(true, 'the parser: ' . 'CRLF, folding, balance, UID/DTSTAMP/DTSTART/DTEND/SUMMARY, UTC times, lines ≤ 75 octets');
$long = 'SUMMARY:' . str_repeat('é', 120) . ',semi;colon';
$folded = ics_line($long);
$un = str_replace("\r\n ", '', rtrim($folded, "\r\n"));
ok($un === $long && count(array_filter(explode("\r\n", rtrim($folded, "\r\n")), fn ($l) => strlen($l) > 75)) === 0 && ics_text("a,b;c\\d\ne") === 'a\,b\;c\\\\d\ne', 'folding never splits a character or exceeds 75 octets, and unfolds to the original; text is escaped');
echo "1. Making the link\n";
ok(req('GET', '/settings/?tab=calendar', ['jar' => $priya])['body'] !== '' && str_contains(req('GET', '/settings/?tab=calendar', ['jar' => $priya])['body'], 'You have no link yet.'), 'before: "You have no link yet."');
$since = last_activity_id();
$r = bpost($priya, '/settings/calendar-feed.php');
$loc = explode('#', $r['location'])[0];
ok($r['code'] === 302 && str_contains($r['location'], '/settings/?tab=calendar&notice=cf_made') && !str_contains($r['body'], '/api/v1/calendar/'), 'calendar_feed_rotate from the browser: 302 to the settings page (the link is not in the reply)');
$page = req('GET', $loc, ['jar' => $priya])['body'];
ok(preg_match('#value="(http[^"]*/api/v1/calendar/([a-f0-9]{48})\.ics)"#', $page, $m) === 1, 'the next page shows the link once: /api/v1/calendar/{48 hex}.ics');
$url1 = html_entity_decode($m[1]); $tok1 = $m[2];
$again = req('GET', '/settings/?tab=calendar', ['jar' => $priya])['body'];
ok(!str_contains($again, $tok1) && str_contains($again, 'cannot be shown again'), 'a reload does not show it again ("cannot be shown again")');
$row = q('SELECT * FROM calendar_feeds WHERE member_id = 26')[0];
ok($row['token_hash'] === hash('sha256', $tok1) && !str_contains(json_encode($row), $tok1), 'only the SHA-256 is kept (' . substr($row['token_hash'], 0, 12) . '…)');
$lg = q("SELECT scope_id, after FROM activity_log WHERE action = 'calendar_feed.rotate' AND id > :s", ['s' => $since]);
ok(count($lg) === 1 && !str_contains(json_encode($lg), $tok1) && json_decode($lg[0]['after'], true) === ['replaced' => false], 'logged calendar_feed.rotate (replaced: false) — the token is not in the log');
echo "2. What the feed holds\n";
$mine = shift_in(102, $srv, 26, 300, 5);                         // 12 days out
$cancelled = shift_in(102, $srv, 26, 330, 5); admin_sql("UPDATE shifts SET status = 'cancelled', cancelled_at = now() WHERE id = $cancelled");
$far = shift_in(102, $srv, 26, 24 * 70, 5);                     // beyond 60 days
$theirs = shift_in(102, $srv, 30, 302, 5);
$open = mkshift(102, $srv, null, 304, 5, ['note' => 'SMOKE s6 open']);
$noted = shift_in(102, $srv, 26, 360, 4); admin_sql("UPDATE shifts SET note = 'Covering for Lee Marchbanks, call +15550001234' WHERE id = $noted");
$past = shift_in(102, $srv, 26, -30, 3);
$draftWeek = week_id_of(102, wk(1)); 
$r = req('GET', str_replace(BASE, '', $url1));
ok($r['code'] === 200 && str_starts_with($r['headers'], 'HTTP/') && preg_match('/^Content-Type: text\/calendar; charset=utf-8/mi', $r['headers']) === 1, 'the link answers 200 text/calendar with no login');
ok(preg_match('/^Cache-Control: private, no-store/mi', $r['headers']) === 1 && preg_match('/^X-Robots-Tag: noindex/mi', $r['headers']) === 1 && preg_match('/^Set-Cookie:/mi', $r['headers']) === 0, 'private, not cached, not indexed, and it sets no cookie');
$p = parse_ics($r['body']);
ok($p['errors'] === [], 'it parses as RFC 5545 with no error' . ($p['errors'] ? ': ' . implode('; ', $p['errors']) : '') . ' (' . count($p['events']) . ' events)');
$uids = array_map(fn ($e) => (int) preg_replace('/\D/', '', $e['UID']), $p['events']);
$expect = array_map('intval', array_column(q("SELECT s.id FROM shifts s JOIN schedule_weeks w ON w.id = s.week_id AND w.status = 'published' WHERE s.assignee_member_id = 26 AND s.status = 'scheduled' AND s.published_at IS NOT NULL AND s.ends_at >= now() AND s.starts_at < now() + interval '60 days' ORDER BY s.starts_at, s.id"), 'id'));
ok($uids === $expect && in_array($mine, $uids, true) && !in_array($cancelled, $uids, true) && !in_array($far, $uids, true) && !in_array($theirs, $uids, true) && !in_array($open, $uids, true) && !in_array($past, $uids, true) && in_array($noted, $uids, true),
    'exactly Priya\'s own published, scheduled shifts of the next 60 days (' . count($uids) . '): not the cancelled, the 70-day one, Ana\'s, the open one or the past one');
$ev = array_values(array_filter($p['events'], fn ($e) => str_contains($e['UID'], "shift-$mine@")))[0] ?? [];
$db = q('SELECT starts_at, ends_at FROM shifts WHERE id = :i', ['i' => $mine])[0];
ok(($ev['SUMMARY'] ?? '') === 'Server — SMOKE Airport' && ($ev['LOCATION'] ?? '') === 'SMOKE Airport' && ($ev['DTSTART'] ?? '') === gmdate('Ymd\THis\Z', strtotime($db['starts_at'])) && ($ev['DTEND'] ?? '') === gmdate('Ymd\THis\Z', strtotime($db['ends_at'])), 'an event: "Server — SMOKE Airport", the restaurant as the location, the shift\'s times in UTC');
$body = $r['body'];
$names = array_column(q('SELECT display_name FROM members WHERE display_name IS NOT NULL'), 'display_name');
$hit = array_values(array_filter($names, fn ($n) => str_contains($body, $n)));
ok($hit === [] && !str_contains($body, 'Marchbanks') && !str_contains($body, 'call +1555') && !str_contains($body, 'Covering'), 'no person\'s name (not even her own), no shift note is in it' . ($hit ? ': ' . implode(',', $hit) : ''));
$leak = notice_leaks($body, 26);
ok($leak === [] && !preg_match('/example\.invalid/', str_replace('https://example.invalid', '', $body)) , 'no wage, phone or email address in it' . ($leak ? ': ' . implode(',', $leak) : ''));
ok(q('SELECT last_used_at FROM calendar_feeds WHERE member_id = 26')[0]['last_used_at'] !== null, 'the feed records when it was last read');
$ana2 = ics_for(pdo(), 30);
ok(!str_contains($ana2, "shift-$mine@") && str_contains($ana2, "shift-$theirs@"), 'Ana\'s own calendar has her shift and not Priya\'s');
echo "3. A new link kills the old\n";
$since = last_activity_id();
$r = bpost($priya, '/settings/calendar-feed.php');
ok($r['code'] === 302 && str_contains($r['location'], 'notice=cf_rotated'), 'Make a new link: 302 (cf_rotated)');
$page = req('GET', explode('#', $r['location'])[0], ['jar' => $priya])['body'];
preg_match('#/api/v1/calendar/([a-f0-9]{48})\.ics#', $page, $m2);
$tok2 = $m2[1] ?? '';
ok($tok2 !== '' && $tok2 !== $tok1, 'a different token is shown');
ok(req('GET', "/api/v1/calendar/$tok1.ics")['code'] === 404, 'the OLD link is now 404');
$r2 = req('GET', "/api/v1/calendar/$tok2.ics");
ok($r2['code'] === 200 && parse_ics($r2['body'])['errors'] === [], 'the new link works');
$lg = q("SELECT after FROM activity_log WHERE action = 'calendar_feed.rotate' AND id > :s", ['s' => $since]);
ok(count($lg) === 1 && json_decode($lg[0]['after'], true) === ['replaced' => true], 'logged with replaced: true');
echo "4. Wrong tokens\n";
$codes = [];
foreach ([str_repeat('a', 48), str_repeat('0', 48), substr($tok2, 0, 47), $tok2 . 'a', strtoupper($tok2), 'x', "..%2f..%2fetc%2fpasswd"] as $t) { $codes[] = req('GET', "/api/v1/calendar/$t.ics")['code']; }
ok(array_unique($codes) === [404], 'a wrong, short, long, upper-cased or hostile token: all 404 (' . implode(',', $codes) . ')');
ok(req('GET', '/api/v1/calendar.php')['code'] === 404 && req('GET', '/api/v1/calendar.php?token=' . $tok2)['code'] === 200, 'no token: 404');
ok(req('POST', "/api/v1/calendar/$tok2.ics", ['raw' => 'x'])['code'] === 405, 'a POST is refused (405)');
admin_sql("UPDATE members SET status = 'inactive' WHERE id = 26");
ok(req('GET', "/api/v1/calendar/$tok2.ics")['code'] === 404, 'a deactivated person\'s link is 404');
admin_sql("UPDATE members SET status = 'active' WHERE id = 26");
ok(req('GET', "/api/v1/calendar/$tok2.ics")['code'] === 200, 'active again: it works');
echo "5. Rights and edges\n";
$r = req('POST', '/settings/calendar-feed.php', ['jar' => $priya, 'headers' => JSONH, 'form' => []]);
ok($r['code'] === 403, 'no CSRF token: 403');
ok(req('POST', '/settings/calendar-feed.php', ['headers' => JSONH, 'form' => []])['code'] === 401, 'anonymous: 401');
ok(req('GET', '/settings/calendar-feed.php', ['jar' => $priya])['code'] === 405, 'a GET: 405');
[, $d] = screen($priya, '/settings/?tab=calendar');
ok($d['calendar']['has_link'] === true && !str_contains(json_encode($d), $tok2), 'the settings JSON says whether there is a link and never the link');
[$c, $b] = act($ana, '/settings/calendar-feed.php', []);
ok($c === 200 && preg_match('#/api/v1/calendar/[a-f0-9]{48}\.ics$#', $b['link'] ?? '') === 1 && (int) one('SELECT count(*) FROM calendar_feeds') === 2 && one('SELECT token_hash FROM calendar_feeds WHERE member_id = 26') === hash('sha256', $tok2), 'Ana making hers (an API call answers with the link, once) changes nobody else\'s');
admin_sql("DELETE FROM calendar_feeds; DELETE FROM notification_outbox");
finish();
