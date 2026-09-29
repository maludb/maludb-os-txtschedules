<?php
/**
 * Helpers for the Phase 3 slice 2 proofs (docs/build-specs/week-builder.md, "Proof"). Run through tests/phase3/slice2/run.sh: a fresh SCRATCH database (never the
 * installed one), the application on :8191, a fake kernel and a fake MaluDB. Everything a proof makes is named "SMOKE …"; nothing is sent (the outbox only queues).
 * Builds on slice 1's helpers (the cast, as_member(), act(), screen(), admin_sql() …). Every proof takes its OWN weeks, far ahead (wk(n)), so no proof disturbs another.
 */
require dirname(__DIR__) . '/slice1/lib.php';

const TZA = 'America/Chicago';           // Airport
const TZD = 'America/New_York';          // Downtown

/** The world of slice 1 (positions, staff positions, people signed on) plus the two extra people slice 2 needs. */
function w2(): array
{
    static $W = null;
    if ($W !== null) { return $W; }
    $file = need('TS_DEV_STATE') . '/world2.json';
    if (is_file($file)) { return $W = json_decode((string) file_get_contents($file), true); }
    $W = world();
    // a role with schedule.build but NOT labor.view (the catalogue has none: a restaurant's own composition of rights is the kernel's to grant; the proof adds one to its scratch catalogue)
    admin_sql("INSERT INTO ts_roles (role_key, name, description, capability) VALUES ('planner', 'Planner', 'SMOKE builds the schedule, sees no pay', 'write') ON CONFLICT DO NOTHING;
               INSERT INTO ts_role_rights (role_key, right_key) SELECT 'planner', r FROM unnest(ARRAY['schedule.view_own','availability.edit','market.trade','schedule.build']) r ON CONFLICT DO NOTHING;");
    file_put_contents($file, json_encode($W));
    return $W;
}

/** A planner (builds, no pay) is a cast member of its own: id 35 at Airport with roles [planner]. */
function planner_claims(): array
{
    $c = cast()['33'];
    $c['display_name'] = 'SMOKE Pat'; $c['email'] = 'pat@example.invalid'; $c['role'] = 'planner'; $c['roles'] = ['planner'];
    foreach ($c['scopes'] as &$s) { $s['role'] = 'planner'; $s['roles'] = ['planner']; }
    return $c;
}
function as_planner(): string { [$j, $r] = sign_on(35, null, ['claims' => planner_claims()]); if ($r['code'] !== 302) { fwrite(STDERR, "planner sign-on failed {$r['code']}\n"); } return $j; }

/** The Monday of the week $n weeks from this one, in Chicago (a far week, $n ≥ 30, is a proof's own). */
function wk(int $n): string { return monday_of(new DateTimeImmutable('+' . $n . ' weeks'), TZA); }
function dayn(string $ws, int $i): string { return (new DateTimeImmutable($ws))->modify("+$i days")->format('Y-m-d'); }
/** "2026-10-09 17:00" on day $i of the week. */
function at(string $ws, int $i, string $t): string { return dayn($ws, $i) . ' ' . $t; }
function week_id_of(int $site, string $ws): ?int { $v = one('SELECT id FROM schedule_weeks WHERE scope_id = :s AND week_start = :w', ['s' => $site, 'w' => $ws]); return $v === false ? null : (int) $v; }
function week_status(int $site, string $ws): ?string { $v = one('SELECT status FROM schedule_weeks WHERE scope_id = :s AND week_start = :w', ['s' => $site, 'w' => $ws]); return $v === false ? null : (string) $v; }

/** A shift straight into the database (a fixture): local times in the site's zone, in the week's draft (made when absent) — or published when the week already is. */
function fx(int $site, int $pos, ?int $member, string $ws, int $day, string $from = '17:00', string $to = '23:00', array $o = []): int
{
    $tz = $site === 101 ? TZD : TZA;
    $w = week_id_of($site, $ws) ?? (int) one('INSERT INTO schedule_weeks (scope_id, week_start) VALUES (:s, :w) RETURNING id', ['s' => $site, 'w' => $ws]);
    $d = dayn($ws, $day);
    $endDay = $to <= $from ? dayn($ws, $day + 1) : $d;
    return (int) one("INSERT INTO shifts (scope_id, week_id, position_id, starts_at, ends_at, break_minutes, assignee_member_id, note) VALUES (:s, :w, :p, (CAST(:a AS timestamp) AT TIME ZONE :tz), (CAST(:b AS timestamp) AT TIME ZONE :tz2), :br, :m, :n) RETURNING id",
        ['s' => $site, 'w' => $w, 'p' => $pos, 'a' => "$d $from", 'b' => "$endDay $to", 'tz' => $tz, 'tz2' => $tz, 'br' => $o['break'] ?? 0, 'm' => $member, 'n' => $o['note'] ?? null]);
}
/** Publish a week the way the handler does, but as a fixture (no notices). */
function fx_publish(int $site, string $ws): void { q('SELECT ts_publish_week(:w, 1)', ['w' => week_id_of($site, $ws)]); }

/** The soft/hard state of a person for the proofs: everyone starts with no minor flag, no limit. */
function reset_people(): void { admin_sql("UPDATE staff_profiles SET is_minor = false, minor_until = NULL, max_hours_week = NULL; DELETE FROM availability_rules WHERE member_id >= 26; DELETE FROM time_off_requests WHERE member_id >= 26;"); }

/** Every notice a week's publish queued, by member id → count. */
function publish_notices(int $week): array
{
    $out = [];
    foreach (q("SELECT member_id, count(*) AS n FROM notification_outbox WHERE reference = :r AND kind = 'schedule_published' GROUP BY member_id ORDER BY member_id", ['r' => "week:$week"]) as $r) { $out[(int) $r['member_id']] = (int) $r['n']; }
    return $out;
}
function shift_notices(int $shift, ?int $since = null): array
{
    return q("SELECT member_id, kind, channel, subject, body FROM notification_outbox WHERE reference = :r AND kind = 'shift_changed' AND id > :s ORDER BY id", ['r' => "shift:$shift", 's' => $since ?? 0]);
}
function last_outbox_id(): int { return (int) one('SELECT COALESCE(max(id), 0) FROM notification_outbox'); }
function builder(string $jar, int $site, string $ws, string $q = ''): array { return screen($jar, "/builder?site=$site&week=$ws$q"); }
/** A post as JSON to a builder handler by a jar; returns [code, body]. */
function bp(string $jar, string $path, array $form): array { [$c, $b] = act($jar, $path, $form); return [$c, $b]; }
/** The shift rows of a week as the builder JSON shows them (id => row). */
function jshifts(string $jar, int $site, string $ws): array { [, $d] = builder($jar, $site, $ws); return array_column($d['shifts'] ?? [], null, 'shift_id'); }

/** A Downtown-only manager (id 36) — a restaurant Airport's people do not share. */
function dee_claims(): array
{
    $c = cast()['34'];
    $c['display_name'] = 'SMOKE Dee'; $c['email'] = 'dee@example.invalid'; $c['role'] = 'manager'; $c['roles'] = ['manager'];
    foreach ($c['scopes'] as &$s) { $s['role'] = 'manager'; $s['roles'] = ['manager']; }
    return $c;
}
function as_dee(): string { [$j, $r] = sign_on(36, null, ['claims' => dee_claims()]); if ($r['code'] !== 302) { fwrite(STDERR, "Dee sign-on failed {$r['code']}\n"); } return $j; }
/** Local "Y-m-d H:i" of a stored moment in Airport's zone. */
function loc(string $utc, string $tz = TZA): string { return (new DateTimeImmutable($utc))->setTimezone(new DateTimeZone($tz))->format('Y-m-d H:i'); }
/** A person's approved time off on a local day at Airport (a fixture, as the database owner). */
function time_off(int $member, string $date): void
{
    $tt = (int) one("SELECT id FROM time_off_types WHERE scope_id = 102 AND key = 'unpaid'");
    admin_sql("INSERT INTO time_off_requests (member_id, scope_id, type_id, starts_at, ends_at, status) VALUES ($member, 102, $tt, ('$date 00:00'::timestamp AT TIME ZONE '" . TZA . "'), (('$date 00:00'::timestamp + interval '1 day') AT TIME ZONE '" . TZA . "'), 'approved')");
}

/** Only these people are on the schedule (staff_profiles.active) — the auto-fill's pool; restore with only(null). */
function only(?array $ids): void { admin_sql($ids === null ? 'UPDATE staff_profiles SET active = true' : 'UPDATE staff_profiles SET active = (member_id IN (' . implode(',', $ids) . '))'); }
/** An approved availability window at Airport on a local weekday (0 = Sunday). */
function availability(int $member, int $weekday, string $kind, string $from = '00:00', string $to = '23:59'): void
{
    admin_sql("INSERT INTO availability_rules (member_id, scope_id, weekday, starts_at, ends_at, kind, effective_from, status) VALUES ($member, 102, $weekday, '$from', '$to', '$kind', current_date - 1, 'approved')");
}
