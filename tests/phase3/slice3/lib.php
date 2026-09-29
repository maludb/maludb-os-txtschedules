<?php
/**
 * Helpers for the Phase 3 slice 3 proofs (docs/build-specs/availability-time-off.md, "Proof"). Run through tests/phase3/slice3/run.sh: a fresh SCRATCH database (never the installed
 * one), the application on :8191, a fake kernel and a fake MaluDB. Everything a proof makes is named "SMOKE …"; nothing is sent (the outbox only queues). Builds on slice 2's helpers.
 * Every proof starts with reset3(): no availability, no time off, no balances, no blackout dates, the restaurants' settings as they ship.
 */
require dirname(__DIR__) . '/slice2/lib.php';

/** Start clean: the slice's own tables emptied (ledger first — it points at requests), settings back to their defaults, the shipped types only. */
function reset3(): array
{
    $W = w2();
    admin_sql("DELETE FROM time_off_ledger; DELETE FROM time_off_balances; DELETE FROM time_off_requests; DELETE FROM availability_rules; DELETE FROM blackout_dates;
               DELETE FROM time_off_types WHERE name LIKE 'SMOKE%';
               UPDATE time_off_types SET archived_at = NULL, allow_negative = false WHERE key IN ('vacation', 'sick', 'unpaid');
               UPDATE site_settings SET availability_needs_approval = true, time_off_day_hours = 8;
               UPDATE staff_profiles SET is_minor = false, minor_until = NULL, max_hours_week = NULL, active = true;");
    return $W;
}
function typ(int $site, string $key): int { return (int) one('SELECT id FROM time_off_types WHERE scope_id = :s AND key = :k', ['s' => $site, 'k' => $key]); }
function bal(int $member, int $type): float { $v = one('SELECT balance_hours FROM time_off_balances WHERE member_id = :m AND type_id = :t', ['m' => $member, 't' => $type]); return $v === false ? 0.0 : (float) $v; }
function ledger_rows(int $member, int $type): array { return q('SELECT delta_hours, reason, request_id, note FROM time_off_ledger WHERE member_id = :m AND type_id = :t ORDER BY id', ['m' => $member, 't' => $type]); }
/** A balance straight into the ledger, as the database owner (a fixture — not through the handler under test). */
function grant(int $member, int $type, float $hours): void { admin_sql("SELECT ts_time_off_post($member, $type, $hours, 'grant', NULL, 'SMOKE fixture', 1)"); }
function req_off(string $jar, int $type, string $from, string $to, array $more = []): array { return act($jar, '/time-off/request.php', ['time_off_type' => $type, 'from' => $from, 'to' => $to] + $more); }
function status_off(int $id): string { return (string) one('SELECT status FROM time_off_requests WHERE id = :i', ['i' => $id]); }
function outbox_ref(string $ref, ?string $kind = null): array { return q('SELECT * FROM notification_outbox WHERE reference = :r' . ($kind ? ' AND kind = :k' : '') . ' ORDER BY id', ['r' => $ref] + ($kind ? ['k' => $kind] : [])); }
function told_ref(string $ref): array { $m = array_values(array_unique(array_map(fn ($r) => (int) $r['member_id'], outbox_ref($ref)))); sort($m); return $m; }
/** Blocks of a person as rows: [weekday, from, to, kind, status]. */
function blocks_of(int $member): array { return q("SELECT id, weekday, to_char(starts_at, 'HH24:MI') AS a, to_char(ends_at, 'HH24:MI') AS b, kind, status, scope_id FROM availability_rules WHERE member_id = :m ORDER BY id", ['m' => $member]); }
/** A published shift on local day $day of week $ws for a member — fixture, published. */
function pub_shift(int $site, int $pos, ?int $member, string $ws, int $day, string $from = '17:00', string $to = '23:00'): int
{
    $id = fx($site, $pos, $member, $ws, $day, $from, $to);
    if (week_status($site, $ws) !== 'published') { fx_publish($site, $ws); }
    return $id;
}
