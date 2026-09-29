<?php
/**
 * Helpers for the Phase 3 slice 4 proofs (docs/build-specs/people-positions.md, "Proof"). Run through tests/phase3/slice4/run.sh: a fresh SCRATCH database (never the installed one), the
 * application on :8191, a fake kernel and a fake MaluDB. Everything a proof makes is named "SMOKE …"; nothing is sent (the outbox only queues). Builds on slice 3's helpers.
 * The wages of the fixture are the numbers a page must never show to someone who may not see pay: wage_marks() — grep every page and reply for them.
 * Every proof starts with reset4(): the wages, positions, kinds, cards and rule severities back as the fixture has them.
 */
require dirname(__DIR__) . '/slice3/lib.php';

/** The fixture's rates (world()): Airport Server 21.37 default with Priya's own 24.61, Airport Bar 23.19, Downtown Server 22.83 — plus the numbers this slice's proofs set. */
function wage_marks(): array { return ['21.37', '24.61', '23.19', '22.83', '17.31', '18.43', '26.19', '31.07', '12.55']; }
/** A body without its timestamps' seconds ("16:11:46.71139" would otherwise contain a mark like 46.71 by chance). */
function strip_ts(string $body): string { return (string) preg_replace('/\d{2}:\d{2}:\d{2}\.\d+/', '', $body); }
function wage_leaks(string $body): array { $body = strip_ts($body); return array_values(array_filter(wage_marks(), fn (string $w) => str_contains($body, $w))); }

/** Start clean: cards, kinds and positions of the proofs gone, the fixture's rates and rules back, nobody a minor, everybody on the schedule with their main restaurant. */
function reset4(): array
{
    $W = reset3();
    admin_sql("DELETE FROM certifications; DELETE FROM position_certifications;
               DELETE FROM certification_kinds WHERE name LIKE 'SMOKE%';
               UPDATE certification_kinds SET archived_at = NULL, track_expiry = true, warn_days = 30, name = CASE key WHEN 'food_handler' THEN 'Food handler' WHEN 'alcohol_service' THEN 'Alcohol service' ELSE name END;
               DELETE FROM certification_kinds WHERE key NOT IN ('food_handler', 'alcohol_service');
               DELETE FROM staff_positions WHERE position_id IN (SELECT id FROM positions WHERE name LIKE 'SMOKE%');
               DELETE FROM positions WHERE name LIKE 'SMOKE%' AND id NOT IN (SELECT position_id FROM shifts);
               UPDATE positions SET archived_at = NULL WHERE name LIKE 'SMOKE%';
               UPDATE positions SET default_wage_rate = CASE id WHEN {$W['aSrv']} THEN 21.37 WHEN {$W['aBar']} THEN 23.19 WHEN {$W['dSrv']} THEN 22.83 END WHERE id IN ({$W['aSrv']}, {$W['aBar']}, {$W['dSrv']});
               UPDATE positions SET name = CASE id WHEN {$W['aSrv']} THEN 'Server' WHEN {$W['aBar']} THEN 'Bar' WHEN {$W['dSrv']} THEN 'Server' END WHERE id IN ({$W['aSrv']}, {$W['aBar']}, {$W['dSrv']});
               UPDATE staff_positions SET wage_override = NULL; UPDATE staff_positions SET wage_override = 24.61 WHERE member_id = 26 AND position_id = {$W['aSrv']};
               UPDATE site_rules SET severity = 'soft' WHERE rule_key = 'cert_required';
               UPDATE staff_profiles SET is_minor = false, minor_until = NULL, max_hours_week = NULL, active = true, notes = NULL;
               UPDATE staff_profiles SET main_scope_id = 101 WHERE member_id IN (27, 28, 34, 36); UPDATE staff_profiles SET main_scope_id = 102 WHERE member_id NOT IN (27, 28, 34, 36);
               DELETE FROM activity_log WHERE action IN ('wage.update', 'staff.save') AND actor_member_id IS NULL;");
    return $W;
}
/** The Airport Server, the Bar and the Downtown Server. */
function posid(string $k): int { return (int) w2()[$k]; }
function kind(int $site, string $key): int { return (int) one('SELECT id FROM certification_kinds WHERE scope_id = :s AND key = :k', ['s' => $site, 'k' => $key]); }
function cards_of(int $member): array { return q('SELECT c.*, k.scope_id, k.name FROM certifications c JOIN certification_kinds k ON k.id = c.kind_id WHERE c.member_id = :m AND c.removed_at IS NULL ORDER BY c.id', ['m' => $member]); }
function warns(int $member, int $site, int $position, string $day): array { return array_map(fn ($r) => $r['message'], q('SELECT * FROM ts_assignment_warnings(:m, :s, :p, CAST(:a AS timestamptz), CAST(:b AS timestamptz), 0) WHERE rule_key = \'cert_required\'', ['m' => $member, 's' => $site, 'p' => $position, 'a' => "$day 17:00-05", 'b' => "$day 23:00-05"])); }
function today_plus(int $days): string { return (new DateTimeImmutable('now', new DateTimeZone(TZA)))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d'); }
/** All wage-bearing rows of an activity_log / notification_outbox slice, as text — a proof greps it. */
function logged_text(int $since): string { return json_encode(q('SELECT action, before, after, route FROM activity_log WHERE id > :s', ['s' => $since])) . json_encode(q('SELECT subject, body FROM notification_outbox')); }
