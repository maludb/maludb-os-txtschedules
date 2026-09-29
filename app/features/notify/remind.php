<?php
declare(strict_types=1);

/**
 * What the worker (bin/notifications.php) does besides sending (slice 6): expire offers nobody took, queue shift reminders, warn managers of certifications, and once a day fill the next
 * 14 days of each forecast from Reservations. The worker has no signed-in person: it reads BASE tables, except the manager's warning list, which it reads through `mcp_certifications_due`
 * as a manager of the restaurant (the view is the one source of who is due — the screen and the notice can never disagree).
 */
require_once dirname(__DIR__) . '/shifts/present.php';
require_once dirname(__DIR__) . '/labor/queries.php';
require_once dirname(__DIR__) . '/labor/reservations.php';

/** Set (or clear) the acting member on the connection — what app_current_member_id() reads. */
function set_acting_member(PDO $pdo, ?int $memberId): void
{
    $pdo->prepare('SELECT set_config(:k, :v, false)')->execute(['k' => 'app.member_id', 'v' => $memberId === null ? '' : (string) $memberId]);
}

/** Active humans who hold a right at a restaurant (member_site_roles × ts_role_rights). */
function find_right_holder_ids(PDO $pdo, int $siteId, string $right): array
{
    $st = $pdo->prepare("SELECT DISTINCT r.member_id FROM member_site_roles r
                           JOIN members m ON m.id = r.member_id AND m.status = 'active' AND m.capability IS NOT NULL AND m.member_kind = 'human'
                           JOIN ts_role_rights rr ON rr.role_key = ANY (r.roles) AND rr.right_key = :right
                          WHERE r.scope_id = :s ORDER BY r.member_id");
    $st->execute(['right' => $right, 's' => $siteId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

// ---- expiring offers -------------------------------------------------------------------------------------------------------------------------------
/**
 * The database expires what is past its time (ts_exchanges_expire()); then each expired offer or ask, seen once, tells the person who made it (the holder; for an open shift or a coverage request,
 * whoever posted it) and logs `exchange.expire`. The shift stays with its holder. Idempotent: a trade already told is not told again. Answers the number of trades told this pass.
 */
function expire_exchanges(PDO $pdo): int
{
    $pdo->query('SELECT ts_exchanges_expire()');
    $st = $pdo->query("SELECT x.id, x.scope_id, x.kind, x.from_member_id, x.created_by, x.to_member_id, tm.display_name AS to_name,
                              s.starts_at, s.ends_at, st.timezone, st.name AS site_name, p.name AS position_name
                         FROM exchanges x JOIN shifts s ON s.id = x.shift_id JOIN sites st ON st.scope_id = x.scope_id JOIN positions p ON p.id = s.position_id
                         LEFT JOIN members tm ON tm.id = x.to_member_id
                        WHERE x.status = 'expired' AND x.updated_at > now() - interval '3 days' ORDER BY x.id");
    $told = 0;
    foreach ($st->fetchAll() as $x) {
        $who = $x['from_member_id'] !== null ? (int) $x['from_member_id'] : ($x['created_by'] !== null ? (int) $x['created_by'] : null);
        $facts = $x['position_name'] . ', ' . shift_when((string) $x['starts_at'], (string) $x['ends_at'], (string) $x['timezone'], true) . ', ' . $x['site_name'] . '.';
        $first = $x['to_name'] === null ? null : explode(' ', trim((string) $x['to_name']))[0];
        $line = match (true) {
            $x['kind'] === 'give' || $x['kind'] === 'swap' => 'Your ' . $x['kind'] . ' request to ' . $first . ' ran out without an answer',
            $x['kind'] === 'open' || $x['kind'] === 'coverage' => 'Nobody took the open shift before it ran out',
            default => 'Nobody took your offered shift before it ran out',
        };
        $body = $line . ': ' . $facts . ($who === (int) ($x['from_member_id'] ?? 0) ? ' The shift stays yours.' : '') . ' ' . app_url('/exchanges/' . (int) $x['id']);
        $n = $who === null ? 0 : notify($pdo, $who, (int) $x['scope_id'], 'exchange', 'A trade ran out', $body, 'exchange:' . (int) $x['id'], 'expire:' . (int) $x['id'] . ':' . $who);
        $logged = $pdo->prepare("SELECT 1 FROM activity_log WHERE action = 'exchange.expire' AND entity_type = 'exchange' AND entity_id = :i LIMIT 1");
        $logged->execute(['i' => (int) $x['id']]);
        if ($logged->fetchColumn() === false) {
            log_activity($pdo, 'exchange.expire', 'exchange', (int) $x['id'], ['scope_id' => (int) $x['scope_id'], 'actor_member_id' => null,
                'after' => ['kind' => $x['kind'], 'told' => $who, 'queued' => $n]]);
            $told++;
        }
    }
    return $told;
}

// ---- shift reminders -------------------------------------------------------------------------------------------------------------------------------
/**
 * Published, scheduled, assigned shifts that start within the holder's lead time (their `reminder_minutes`, else the restaurant's `reminder_minutes_before`) — an active person, a live restaurant.
 * Whether a reminder was ALREADY queued is the outbox's unique key, not this query's business: notify() queues nothing twice.
 */
function due_reminders(PDO $pdo, int $limit = 200): array
{
    $st = $pdo->prepare("SELECT s.id AS shift_id, s.scope_id, s.starts_at, s.ends_at, s.assignee_member_id AS member_id, p.name AS position_name, st.name AS site_name, st.timezone,
                                COALESCE(np.reminder_minutes, ss.reminder_minutes_before) AS lead
                           FROM shifts s
                           JOIN schedule_weeks w ON w.id = s.week_id AND w.status = 'published'
                           JOIN positions p ON p.id = s.position_id
                           JOIN sites st ON st.scope_id = s.scope_id AND st.removed_at IS NULL
                           JOIN site_settings ss ON ss.scope_id = s.scope_id
                           JOIN members m ON m.id = s.assignee_member_id AND m.status = 'active' AND m.member_kind = 'human'
                           LEFT JOIN notification_prefs np ON np.member_id = m.id
                          WHERE s.status = 'scheduled' AND s.published_at IS NOT NULL AND s.starts_at > now()
                            AND s.starts_at <= now() + COALESCE(np.reminder_minutes, ss.reminder_minutes_before) * interval '1 minute'
                          ORDER BY s.starts_at, s.id LIMIT :n");
    $st->bindValue('n', $limit, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}

/** "today", "tomorrow", or "Sat Oct 10" — the shift's day in the restaurant's zone. */
function day_word(string $startUtc, string $tz): string
{
    $start = local_dt($startUtc, $tz);
    $now = new DateTimeImmutable('now', new DateTimeZone($tz));
    return match ($start->format('Y-m-d')) {
        $now->format('Y-m-d') => 'today',
        $now->modify('+1 day')->format('Y-m-d') => 'tomorrow',
        default => $start->format('D M j'),
    };
}

/** "Server at Airport today 5:00–11:00 pm." (+ the zone when the person works in two zones) — the shift's own facts and nothing of anyone else. */
function reminder_line(array $r, bool $zone): string
{
    return $r['position_name'] . ' at ' . $r['site_name'] . ' ' . day_word((string) $r['starts_at'], (string) $r['timezone']) . ' ' . shift_time_range((string) $r['starts_at'], (string) $r['ends_at'], (string) $r['timezone'])
        . ($zone ? ' ' . local_dt((string) $r['starts_at'], (string) $r['timezone'])->format('T') : '') . '.';
}

/** Queue one reminder per channel the person has on, once per shift, person and channel (`reminder:{shift}:{member}:{channel}`). Answers the number of rows queued. */
function queue_reminders(PDO $pdo, int $limit = 200): int
{
    $zones = $pdo->prepare("SELECT count(DISTINCT s.timezone) FROM member_site_roles r JOIN sites s ON s.scope_id = r.scope_id AND s.removed_at IS NULL WHERE r.member_id = :m");
    $queued = 0;
    foreach (due_reminders($pdo, $limit) as $r) {
        $zones->execute(['m' => (int) $r['member_id']]);
        $line = reminder_line($r, (int) $zones->fetchColumn() > 1);
        $queued += notify($pdo, (int) $r['member_id'], (int) $r['scope_id'], 'reminder', 'Shift reminder: ' . $line, $line . ' ' . app_url('/shifts/' . (int) $r['shift_id']),
            'shift:' . (int) $r['shift_id'], 'reminder:' . (int) $r['shift_id'] . ':' . (int) $r['member_id']);
    }
    return $queued;
}

// ---- once a day ------------------------------------------------------------------------------------------------------------------------------------
/** Has this daily step already run today (UTC) for this restaurant? The record is the activity log's own `notification.daily` row — no second table. */
function daily_done(PDO $pdo, string $step, int $siteId): bool
{
    $st = $pdo->prepare("SELECT 1 FROM activity_log WHERE action = 'notification.daily' AND scope_id = :s AND after->>'step' = :step AND occurred_at >= date_trunc('day', now()) LIMIT 1");
    $st->execute(['s' => $siteId, 'step' => $step]);
    return $st->fetchColumn() !== false;
}

function daily_mark(PDO $pdo, string $step, int $siteId, array $detail = []): void
{
    log_activity($pdo, 'notification.daily', null, null, ['scope_id' => $siteId, 'actor_member_id' => null, 'after' => ['step' => $step] + $detail]);
}

/** The restaurants that are live, with the fields the daily jobs need. */
function live_sites(PDO $pdo): array
{
    return $pdo->query("SELECT s.scope_id, s.location_id, s.name, s.timezone, ss.week_start FROM sites s JOIN site_settings ss ON ss.scope_id = s.scope_id WHERE s.removed_at IS NULL ORDER BY s.scope_id")->fetchAll();
}

/** A restaurant's live day-parts read from the base table (the worker has no acting member, so no view). Same keys as find_day_parts(). */
function find_day_parts_base(PDO $pdo, int $siteId): array
{
    $st = $pdo->prepare('SELECT id AS day_part_id, key, name, service_name FROM day_parts WHERE scope_id = :s AND archived_at IS NULL ORDER BY sort_order, id');
    $st->execute(['s' => $siteId]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['day_part_id'] = (int) $r['day_part_id'];
    }
    unset($r);
    return $rows;
}

/**
 * Once a day, for each restaurant: ask Reservations (through the kernel, K7 — ONE call for today and the 13 days after) for booked covers and fill the forecast's cells for those days, week by week,
 * exactly as the manager's "Fill from Reservations" does (typed and copied cells are left alone). A refusal degrades to a logged word and is not retried the same day, except an unreachable
 * provider (`provider_failed`), which is tried again on the next pass. Answers ['sites' => n asked, 'written' => cells written, 'refused' => n refused].
 */
function daily_forecast_fill(PDO $pdo, int $days = 14): array
{
    $out = ['sites' => 0, 'written' => 0, 'refused' => 0];
    foreach (live_sites($pdo) as $site) {
        $sid = (int) $site['scope_id'];
        if (daily_done($pdo, 'forecast_fill', $sid)) {
            continue;
        }
        $tz = new DateTimeZone((string) $site['timezone']);
        $from = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
        $to = (new DateTimeImmutable($from, $tz))->modify('+' . ($days - 1) . ' days')->format('Y-m-d');
        $answer = (int) $site['location_id'] < 1 ? ['refusal' => 'no_location'] : fetch_reservation_covers((int) $site['location_id'], $from, $to);   // the kernel is asked BEFORE any write
        $out['sites']++;
        if (isset($answer['refusal'])) {
            $out['refused']++;
            log_activity($pdo, 'forecast.fill', 'forecast', null, ['scope_id' => $sid, 'actor_member_id' => null, 'after' => ['via' => 'timer', 'from' => $from, 'to' => $to, 'refusal' => $answer['refusal']]]);
            if ($answer['refusal'] !== 'provider_failed') {
                daily_mark($pdo, 'forecast_fill', $sid, ['refusal' => $answer['refusal']]);
            }
            continue;
        }
        $answer['rows'] = array_values(array_filter($answer['rows'], static fn (array $r): bool => $r['date'] >= $from && $r['date'] <= $to));   // the next 14 days, not the rest of the last week
        $parts = find_day_parts_base($pdo, $sid);
        $written = 0;
        $weeks = [];
        for ($d = new DateTimeImmutable($from, $tz); $d->format('Y-m-d') <= $to; $d = $d->modify('+1 day')) {
            $weeks[week_start_of($d->format('Y-m-d'), (int) $site['week_start'])] = true;
        }
        $pdo->beginTransaction();
        try {
            foreach (array_keys($weeks) as $ws) {
                $r = fill_forecast($pdo, $sid, $ws, false, null, $answer, $parts);
                $written += $r['written'];
                log_activity($pdo, 'forecast.fill', 'forecast', null, ['scope_id' => $sid, 'actor_member_id' => null, 'after' => ['via' => 'timer', 'week_start' => $ws, 'written' => $r['written'],
                    'skipped' => $r['skipped'], 'unchanged' => $r['unchanged'], 'unmapped' => $r['unmapped'], 'refusal' => null, 'replace_manual' => false]]);
            }
            daily_mark($pdo, 'forecast_fill', $sid, ['written' => $written]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $out['written'] += $written;
    }
    return $out;
}

// ---- the manager's certification warning -------------------------------------------------------------------------------------------------------------
/**
 * Each restaurant's managers (schedule.build) are told when a card comes inside its warning days ('due') and again when it has run out ('expired'): one notice per card, state and manager
 * (`cert:{card}:{state}:{manager}` — a renewal is a new card and warns afresh). The list is read from `mcp_certifications_due` AS a manager of the restaurant. Answers the rows queued.
 */
function queue_certification_warnings(PDO $pdo): int
{
    $queued = 0;
    foreach (live_sites($pdo) as $site) {
        $sid = (int) $site['scope_id'];
        $managers = find_right_holder_ids($pdo, $sid, 'schedule.build');
        if ($managers === []) {
            continue;
        }
        set_acting_member($pdo, $managers[0]);
        try {
            $st = $pdo->prepare("SELECT certification_id, display_name, kind_name, expires_on, state, days_left FROM mcp_certifications_due
                                  WHERE site_id = :s AND state IN ('due', 'expired') AND certification_id IS NOT NULL ORDER BY certification_id");
            $st->execute(['s' => $sid]);
            $rows = $st->fetchAll();
        } finally {
            set_acting_member($pdo, null);
        }
        foreach ($rows as $c) {
            $when = format_date($c['expires_on']);
            $line = $c['state'] === 'expired'
                ? $c['display_name'] . '\'s ' . $c['kind_name'] . ' card ran out on ' . $when . ' (' . $site['name'] . ').'
                : $c['display_name'] . '\'s ' . $c['kind_name'] . ' card runs out on ' . $when . ' — ' . (int) $c['days_left'] . ' day' . ((int) $c['days_left'] === 1 ? '' : 's') . ' left (' . $site['name'] . ').';
            foreach ($managers as $m) {
                $queued += notify($pdo, $m, $sid, 'reminder', $c['state'] === 'expired' ? 'A certification has run out' : 'A certification is running out',
                    $line . ' ' . app_url('/certifications/?site=' . $sid), 'certification:' . (int) $c['certification_id'], 'cert:' . (int) $c['certification_id'] . ':' . $c['state'] . ':' . $m);
            }
        }
    }
    return $queued;
}
