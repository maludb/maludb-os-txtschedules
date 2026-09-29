<?php
/** Proof — the schema this slice stands on (db/007, db/013): no migration was needed; the database's own rules refuse what the handlers rely on it to refuse. */
require __DIR__ . '/lib.php';
$W = reset3();
$root = dirname(__DIR__, 3);
$files = glob($root . '/db/0*.sql'); sort($files);
ok(basename(end($files)) === '015_exchange_overlap.sql', 'slice 3 added no migration: db/015 is still the last');
foreach (['ts_time_off_post', 'ts_time_off_decide', 'ts_time_off_cancel', 'ts_time_off_hours', 'ts_time_off_request_check', 'ts_time_off_request_hours'] as $f) { ok((int) one('SELECT count(*) FROM pg_proc WHERE proname = :f', ['f' => $f]) === 1, "$f exists"); }
foreach (['mcp_availability', 'mcp_time_off_types', 'mcp_time_off_balances', 'mcp_time_off_requests', 'mcp_blackout_dates'] as $v) { ok((int) one("SELECT count(*) FROM pg_class WHERE relname = :v AND relkind = 'v' AND reloptions::text LIKE '%security_barrier=true%'", ['v' => $v]) === 1, "$v is a security-barrier view"); }
$vac = typ(102, 'vacation'); $nod = typ(102, 'unpaid');
ok((int) one("SELECT count(*) FROM time_off_types WHERE scope_id = 102 AND key IN ('vacation', 'sick', 'unpaid')") === 3, 'a restaurant is born with vacation, sick and unpaid');
try { admin_sql("SELECT ts_time_off_post(26, $vac, -5, 'adjustment', NULL, 'SMOKE', 1)"); $r = admin_sql("SELECT ts_time_off_post(26, $vac, -5, 'adjustment', NULL, 'SMOKE', 1)"); ok(str_contains($r, 'Not enough vacation / pto left'), 'ts_time_off_post refuses below zero when the kind does not allow it: ' . trim(explode("\n", $r)[0])); } catch (Throwable $e) { ok(false, $e->getMessage()); }
ok(bal(26, $vac) === 0.0 && count(ledger_rows(26, $vac)) === 0, 'the refused post left no balance and no ledger row (one transaction)');
ok(trim(admin_sql("SELECT ts_time_off_post(26, $nod, 5, 'grant', NULL, 'SMOKE', 1) IS NULL")) === 't' , 'a kind that keeps no balance posts nothing (returns NULL)');
admin_sql("INSERT INTO time_off_requests (member_id, scope_id, type_id, starts_at, ends_at) VALUES (26, 102, $nod, ('2029-05-07 00:00'::timestamp AT TIME ZONE 'America/Chicago'), ('2029-05-09 00:00'::timestamp AT TIME ZONE 'America/Chicago'))");
ok((float) one("SELECT hours FROM time_off_requests WHERE member_id = 26 AND starts_at = ('2029-05-07 00:00'::timestamp AT TIME ZONE 'America/Chicago')") === 16.0, 'the trigger counts a request that gives no hours (2 days × 8 h)');
$r = admin_sql("INSERT INTO time_off_requests (member_id, scope_id, type_id, starts_at, ends_at) VALUES (26, 102, (SELECT id FROM time_off_types WHERE scope_id = 101 AND key = 'unpaid'), now() + interval '9 days', now() + interval '10 days')");
ok(str_contains($r, 'That kind of time off is not offered here.'), 'a kind of another restaurant is refused by the database itself');
$r = admin_sql("INSERT INTO blackout_dates (scope_id, on_date, reason) VALUES (102, '2029-06-01', 'SMOKE x'); INSERT INTO time_off_requests (member_id, scope_id, type_id, starts_at, ends_at) VALUES (26, 102, $nod, '2029-05-31 20:00-05', '2029-06-01 08:00-05')");
ok(str_contains($r, 'No time off on that date: SMOKE x.'), 'a request touching a blackout date is refused by the database itself');
$before = (int) one('SELECT count(*) FROM time_off_requests');
$r = admin_sql("SELECT ts_time_off_decide(999999, true, 1, NULL)");
ok(str_contains($r, 'Request not found.'), 'deciding a request that does not exist: "Request not found."');
finish();
