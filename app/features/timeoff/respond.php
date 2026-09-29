<?php
declare(strict_types=1);

/**
 * What every availability and time-off handler shares: the database's refusal in its own words, the request's words, the notices a step queues, the log rows a step writes, and the
 * one step that reaches into the schedule — opening the shifts an approved absence covers. A handler is: gate at the record's site → one transaction → log with the site →
 * emit_action_status → saved_go.
 */

/** Run a step: a DomainException (our own sentence) or the database's RAISE (P0001) is a 422 — 'Request not found.' is the request's absence, a 404. Anything else is a 500. */
function timeoff_guard(PDO $pdo, callable $step): mixed
{
    try {
        return $step();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof DomainException) {
            $m = $e->getMessage();
            refuse($m === 'Not found.' ? 404 : 422, $m === 'Not found.' ? 'That is not here any more.' : $m);
        }
        if ($e instanceof PDOException && (string) $e->getCode() === 'P0001') {
            $m = db_message($e, 'That could not be done.');
            refuse($m === 'Request not found.' ? 404 : 422, $m);
        }
        if ($e instanceof PDOException && (string) $e->getCode() === '23505') {
            refuse(422, 'That is already there.');
        }
        throw $e;
    }
}

function request_time_off_id(): int
{
    $id = request_integer('request') ?? request_integer('request_id');
    if ($id === null || $id < 1) {
        refuse(422, 'Say which request.');
    }
    return $id;
}

function request_availability_id(): int
{
    $id = request_integer('availability') ?? request_integer('availability_id');
    if ($id === null || $id < 1) {
        refuse(422, 'Say which availability block.');
    }
    return $id;
}

/** A local moment the request gave — "2026-10-09 09:00" (T allowed) or a bare date (start 00:00; an end date means THROUGH that day, so it ends at the next midnight). Null when malformed. */
function time_off_moment(string $s, DateTimeZone $tz, bool $isEnd): ?DateTimeImmutable
{
    $s = trim($s);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $s, $tz);
        $err = DateTimeImmutable::getLastErrors();
        if ($d === false || ($err !== false && ($err['warning_count'] > 0 || $err['error_count'] > 0))) {
            return null;
        }
        return $isEnd ? $d->modify('+1 day') : $d;
    }
    return local_moment($s, $tz);
}

/**
 * The start and end a request names, in the restaurant's zone: `starts_at` and `ends_at` ("2026-10-09 09:00"), or the form's `from` and `to` dates with optional `from_time` and `to_time`
 * (no times = whole days, the end date included). Returns [start, end] as DateTimeImmutable, or the sentence why not (a string).
 */
function time_off_range_parse(DateTimeZone $tz): array|string
{
    $a = req_val('starts_at');
    $b = req_val('ends_at');
    if ($a === null && $b === null) {
        $from = req_val('from');
        $to = req_val('to');
        $to = ($to === null || $to === '') ? $from : $to;
        $ft = req_val('from_time');
        $tt = req_val('to_time');
        if ($from === null || $from === '') {
            return 'Say when the time off starts.';
        }
        $a = ($ft !== null && $ft !== '') ? $from . ' ' . $ft : $from;
        $b = ($tt !== null && $tt !== '') ? $to . ' ' . $tt : $to;
    }
    $start = time_off_moment((string) $a, $tz, false);
    $end = time_off_moment((string) $b, $tz, true);
    if ($start === null || $end === null) {
        return 'Give the dates like 2026-10-09, or with a time like 2026-10-09 09:00.';
    }
    if ($end <= $start) {
        return 'The time off has to end after it starts.';
    }
    if ($end > $start->modify('+366 days')) {
        return 'A request may cover at most a year.';
    }
    return [$start, $end];
}

/** time_off_range_parse() for a handler: a refusal in words when it does not parse. */
function time_off_range_from_request(DateTimeZone $tz): array
{
    $r = time_off_range_parse($tz);
    return is_string($r) ? refuse(422, $r) : $r;
}

/**
 * The form's preview line for a request as it stands (the same fields the handler reads): what it would use in hours (the database counts them, D13 — `ts_time_off_hours()`), the balance
 * before and after for a kind that keeps one, and the reason it would be refused when it touches a blackout date. Answers ['error' => ?string, 'hours' => ?float, 'balance' => ?float, 'over' => bool, 'note' => ?string].
 */
function time_off_preview(PDO $pdo, int $siteId, ?array $type, int $memberId): array
{
    $tz = new DateTimeZone(site_timezone($siteId));
    $r = time_off_range_parse($tz);
    if (is_string($r)) {
        return ['error' => $r, 'hours' => null, 'balance' => null, 'over' => false, 'note' => null];
    }
    [$start, $end] = $r;
    $given = req_val('hours');
    if ($given !== null && $given !== '' && (!is_numeric($given) || (float) $given <= 0)) {
        return ['error' => 'Hours must be a number above 0.', 'hours' => null, 'balance' => null, 'over' => false, 'note' => null];
    }
    $st = $pdo->prepare('SELECT ts_time_off_hours(:s, CAST(:a AS timestamptz), CAST(:b AS timestamptz))');
    $st->execute(['s' => $siteId, 'a' => utc_text($start), 'b' => utc_text($end)]);
    $hours = $given !== null && $given !== '' ? round((float) $given, 2) : (float) $st->fetchColumn();
    $balance = $type !== null && $type['tracks_balance'] ? current_balance($pdo, $memberId, (int) $type['type_id']) : null;
    $b = $pdo->prepare("SELECT reason FROM mcp_blackout_dates WHERE site_id = :s AND on_date BETWEEN CAST(:a AS date) AND CAST(:b AS date) ORDER BY on_date LIMIT 1");
    $b->execute(['s' => $siteId, 'a' => $start->format('Y-m-d'), 'b' => $end->modify('-1 second')->format('Y-m-d')]);
    $black = $b->fetchColumn();
    return ['error' => $black === false ? null : 'No time off on that date: ' . $black . '.', 'hours' => $hours, 'balance' => $balance,
            'over' => $balance !== null && $hours > $balance && !($type['allow_negative'] ?? false), 'note' => null];
}

/** The request's hours if it gave any (a number over 0 and at most 2000), null when it left them for the database to count (D13). */
function request_time_off_hours(): ?float
{
    $h = req_val('hours');
    if ($h === null || $h === '') {
        return null;
    }
    if (!is_numeric($h) || (float) $h <= 0 || (float) $h > 2000) {
        refuse(422, 'Hours must be a number above 0.');
    }
    return round((float) $h, 2);
}

/** Queue the notices of one step. $s = time_off_snapshot() AFTER the step; events: requested (the approvers), decided (the person), cancelled (the person, when a manager did it; the approvers when the person cancelled an approved one). */
function time_off_notify(PDO $pdo, string $event, array $s, int $actor, array $extra = []): void
{
    $site = (int) $s['site_id'];
    $ref = 'time_off:' . (int) $s['request_id'];
    $link = ' ' . app_url('/time-off/' . (int) $s['request_id']);
    $facts = time_off_facts($s);
    switch ($event) {
        case 'requested':
            foreach (find_approver_ids($pdo, $site, false, $actor) as $m) {
                if ($m !== (int) $s['member_id']) {
                    notify($pdo, $m, $site, 'request_decided', 'Time off to approve', $s['member_name'] . ' asked for time off: ' . $facts . $link, $ref);
                }
            }
            break;
        case 'decided':
            $word = $extra['word'] ?? 'decided';
            if ((int) $s['member_id'] !== $actor) {
                notify($pdo, (int) $s['member_id'], $site, 'request_decided', 'Your time off was ' . $word,
                    'Your time off was ' . $word . ': ' . $facts . (($extra['note'] ?? '') !== '' ? ' Note: ' . $extra['note'] : '') . $link, $ref);
            }
            break;
        case 'cancelled':
            if ((int) $s['member_id'] !== $actor) {
                notify($pdo, (int) $s['member_id'], $site, 'request_decided', 'Your time off was cancelled', 'Your time off was cancelled: ' . $facts . $link, $ref);
            } elseif (($extra['was'] ?? '') === 'approved') {
                foreach (find_approver_ids($pdo, $site, false, $actor) as $m) {
                    notify($pdo, $m, $site, 'request_decided', 'Approved time off was cancelled', $s['member_name'] . ' cancelled approved time off: ' . $facts . $link, $ref);
                }
            }
            break;
    }
}

/** Log one timeoff.* row with the site — entity time_off_request. Hours, never pay. */
function log_time_off(PDO $pdo, string $action, array $s, array $after = [], ?array $before = null): void
{
    log_activity($pdo, $action, 'time_off_request', (int) $s['request_id'], ['scope_id' => (int) $s['site_id'],
        'after' => ['request_id' => (int) $s['request_id'], 'member_id' => (int) $s['member_id'], 'type' => $s['type_name']] + $after] + ($before === null ? [] : ['before' => $before]));
}

/**
 * "Also open those shifts": each published, scheduled shift the approved time off covers (at its restaurant, not yet over) goes to nobody — the database stamps it changed after publishing,
 * a trade going on it is withdrawn, the old holder is told and the gap shows in the builder and the coverage tools. One `shift.change` row each (after.via = 'time_off'). Answers the shifts opened.
 */
function open_covered_shifts(PDO $pdo, array $s, int $by): array
{
    $opened = [];
    foreach (request_shifts($pdo, (int) $s['request_id']) as $row) {
        $id = (int) $row['shift_id'];
        $shift = shift_snapshot($pdo, $id, true);
        if ($shift === null || $shift['assignee_member_id'] !== (int) $s['member_id'] || $shift['status'] !== 'scheduled' || $shift['week_status'] !== 'published') {
            continue;
        }
        $pdo->prepare('UPDATE shifts SET assignee_member_id = NULL WHERE id = :id')->execute(['id' => $id]);
        $cancelled = cancel_live_exchanges($pdo, $id, (int) $s['site_id'], $by, 'time_off');
        notify_shift($pdo, (int) $s['member_id'], $shift, 'A shift was taken off your schedule', 'A shift was taken off your schedule because your time off was approved:');
        log_activity($pdo, 'shift.change', 'shift', $id, ['scope_id' => (int) $s['site_id'],
            'before' => ['assignee_member_id' => (int) $s['member_id']],
            'after' => ['assignee_member_id' => null, 'via' => 'time_off', 'request_id' => (int) $s['request_id'], 'exchange_cancelled' => $cancelled, 'notified' => [(int) $s['member_id']],
                        'week_id' => (int) $shift['week_id']]]);
        $opened[] = $id;
    }
    return $opened;
}

/** The standard tail: report through emit_action_status(), then land. $path is where the person (or the location of a create) goes. */
function time_off_done(string $did, ?int $recordId, string $path, array $data = []): never
{
    emit_action_status(true, ['did' => $did] + ($recordId === null ? [] : ['record_id' => $recordId]) + ['refresh' => 'timeOffChanged'] + $data);
    saved_go($path, 'timeOffChanged');
}

/** "Unavailable, Fri 5:00–11:00 pm, from Oct 9" — what a notice and a `did` say about a block. */
function availability_facts(array $b): string
{
    $kind = availability_kind_badge((string) $b['kind'])[0];
    return $kind . ', ' . substr(WEEKDAY_NAMES[(int) $b['weekday']], 0, 3) . ' ' . availability_span((string) $b['starts_at'], (string) $b['ends_at']) . ', ' . availability_dates($b);
}

/**
 * Queue the notices of an availability step. $b = the block (member_id, member_name, scope_id, weekday, starts_at, ends_at, kind, effective_from, effective_to). events: submitted (the approvers of its
 * restaurants, when it waits), decided (the person). The site on the row is the block's own, or the first restaurant the person works at when it is for every one.
 */
function availability_notify(PDO $pdo, string $event, array $b, int $actor, array $extra = []): void
{
    $sites = availability_row_sites($pdo, (int) $b['member_id'], $b['scope_id'] ?? null);
    $ref = 'availability:' . (int) ($b['id'] ?? $b['availability_id']);
    $facts = availability_facts($b);
    if ($event === 'submitted') {
        $told = [];
        foreach ($sites as $site) {
            foreach (find_approver_ids($pdo, $site, false, $actor) as $m) {
                if ($m !== (int) $b['member_id'] && !isset($told[$m])) {
                    $told[$m] = true;
                    notify($pdo, $m, $site, 'request_decided', 'An availability change to approve', ($b['member_name'] ?? 'Someone') . ' asked to change their availability: ' . $facts . ' ' . app_url('/approvals'), $ref);
                }
            }
        }
    } elseif ($event === 'decided' && (int) $b['member_id'] !== $actor && $sites !== []) {
        $word = $extra['word'] ?? 'decided';
        notify($pdo, (int) $b['member_id'], $sites[0], 'request_decided', 'Your availability change was ' . $word,
            'Your availability change was ' . $word . ': ' . $facts . (($extra['note'] ?? '') !== '' ? ' Note: ' . $extra['note'] : '') . ' ' . app_url('/availability'), $ref);
    }
}

/** Log one availability.* row: entity availability, the block's site (see availability_log_site()). */
function log_availability(PDO $pdo, string $action, array $b, array $after = [], ?array $before = null): void
{
    $id = (int) ($b['id'] ?? $b['availability_id']);
    log_activity($pdo, $action, 'availability', $id, ['scope_id' => availability_log_site($pdo, ['member_id' => $b['member_id'], 'scope_id' => $b['scope_id'] ?? null]),
        'after' => ['availability_id' => $id, 'member_id' => (int) $b['member_id'], 'site_id' => $b['scope_id'] ?? null, 'weekday' => (int) $b['weekday'], 'starts_at' => $b['starts_at'], 'ends_at' => $b['ends_at'],
                    'kind' => $b['kind']] + $after] + ($before === null ? [] : ['before' => $before]));
}

/** A path with the banner key (?notice=) and, for a record on a page of records, its anchor — the location of a create ends in the record id: /availability?member=5&notice=av_saved#availability-block-12. */
function land_at(string $path, string $notice, string $anchor = ''): string
{
    $path = explode('#', $path, 2)[0];
    return land_with_notice($path, $notice) . ($anchor !== '' ? '#' . $anchor : '');
}

/** A yes/no field: what was sent (1, true, on, yes = yes), else $keep when the field was left out (a change keeps what it was), else no. */
function req_yes(string $name, ?bool $keep = null): bool
{
    if (!req_has($name)) {
        return $keep ?? false;
    }
    return in_array(strtolower((string) req_val($name)), ['1', 'true', 'on', 'yes'], true);
}
