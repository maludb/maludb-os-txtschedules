<?php
declare(strict_types=1);

/**
 * Writing shifts (slice 2: docs/build-specs/week-builder.md). Two families that never mix: a DRAFT week's shifts (create / update / assign /
 * delete — the scheduling assistant drafts freely) and a PUBLISHED week's (add / change / cancel — separate actions an agent's call must pause on,
 * each telling the people it touches). The database is the referee (overlap, the 16 hours, the published-shift guard); the rules engine
 * (`ts_check_assignment`) is asked before every save: a hard rule refuses in its sentence, a soft one needs an `override_reason`, recorded
 * as one `rule.override` row each. A function refuses with a DomainException (the handler's guard turns it into a 422); none manages its own
 * transaction — the handler opens one — and none logs the shift's own row (the handler does, with the site); overrides and a first-time
 * week are logged here because they are recorded here.
 */

// ---- the request's words -------------------------------------------------------------------------------------------------------
/** Was any of these names sent (POST or GET)? An absent field keeps what a shift has; a present empty one clears it. */
function req_has(string ...$names): bool
{
    foreach ($names as $n) {
        if (array_key_exists($n, $_POST) || array_key_exists($n, $_GET)) {
            return true;
        }
    }
    return false;
}

/** The first of these names that was sent, trimmed; null when none was. */
function req_val(string ...$names): ?string
{
    foreach ($names as $n) {
        if (array_key_exists($n, $_POST)) {
            return trim((string) $_POST[$n]);
        }
        if (array_key_exists($n, $_GET)) {
            return trim((string) $_GET[$n]);
        }
    }
    return null;
}

/** A local "2026-10-09 17:00" (or with T, or seconds) in a zone; null when malformed. */
function local_moment(string $s, DateTimeZone $tz): ?DateTimeImmutable
{
    $s = trim(str_replace('T', ' ', $s));
    foreach (['!Y-m-d H:i', '!Y-m-d H:i:s'] as $format) {
        $d = DateTimeImmutable::createFromFormat($format, $s, $tz);
        $err = DateTimeImmutable::getLastErrors();
        if ($d !== false && ($err === false || ($err['warning_count'] === 0 && $err['error_count'] === 0))) {
            return $d;
        }
    }
    return null;
}

/** A moment as the UTC text a query takes. */
function utc_text(DateTimeInterface $t): string
{
    return DateTimeImmutable::createFromInterface($t)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:sP');
}

function local_from_db(string $utc, DateTimeZone $tz): DateTimeImmutable
{
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone($tz);
}

// ---- a shift as it is now ------------------------------------------------------------------------------------------------------
/** One shift from the base tables with what a notice or a log needs (site, zone, position, holder, week). Null when none. */
function shift_snapshot(PDO $pdo, int $id, bool $lock = false): ?array
{
    $st = $pdo->prepare('SELECT s.id AS shift_id, s.scope_id AS site_id, s.week_id, s.position_id, p.name AS position_name, s.starts_at, s.ends_at, s.break_minutes,
                                s.assignee_member_id, m.display_name AS assignee_name, s.status, s.note, s.published_at, s.changed_after_publish_at, s.cancel_reason,
                                w.status AS week_status, w.week_start::text AS week_start, st.timezone, st.name AS site_name, ss.week_start AS week_dow
                           FROM shifts s
                           JOIN positions p ON p.id = s.position_id
                           JOIN schedule_weeks w ON w.id = s.week_id
                           JOIN sites st ON st.scope_id = s.scope_id
                           JOIN site_settings ss ON ss.scope_id = s.scope_id
                           LEFT JOIN members m ON m.id = s.assignee_member_id
                          WHERE s.id = :id' . ($lock ? ' FOR UPDATE OF s' : ''));
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    foreach (['shift_id', 'site_id', 'week_id', 'position_id', 'week_dow'] as $k) {
        $r[$k] = (int) $r[$k];
    }
    $r['assignee_member_id'] = $r['assignee_member_id'] === null ? null : (int) $r['assignee_member_id'];
    return $r;
}

/** What a shift's log row carries: the changeable fields, times in UTC ISO, never a wage or a cost. */
function shift_log_state(array $s): array
{
    return ['position_id' => (int) $s['position_id'], 'starts_at' => json_ts((string) $s['starts_at']), 'ends_at' => json_ts((string) $s['ends_at']),
            'break_minutes' => (int) $s['break_minutes'], 'assignee_member_id' => $s['assignee_member_id'] === null ? null : (int) $s['assignee_member_id'],
            'note' => $s['note'] ?? null];
}

/** "Server, Fri Oct 9 · 5:00–11:00 pm CDT, Airport." — what a notice says about a shift (no pay, no phone). */
function shift_facts(array $s): string
{
    return $s['position_name'] . ', ' . shift_when((string) $s['starts_at'], (string) $s['ends_at'], (string) $s['timezone'], true) . ', ' . $s['site_name'] . '.';
}

// ---- the fields a shift form or an action carries ------------------------------------------------------------------------------
/**
 * Read a shift's fields from the request. $cur = the shift as it is (an update: an absent field keeps it) or null (a create).
 * Times: `starts_at`/`ends_at` are the site's local "2026-10-09 17:00" (an end before the start is refused); the form's `date`, `starts`,
 * `ends` are a day and two times (an end at or before the start means the next day — overnight); a lone `date` MOVES a shift to that day
 * keeping its times. Answers ['position_id','starts' (local),'ends' (local),'break','assignee','note','reason'].
 */
function shift_fields_from_request(?array $cur, string $timezone): array
{
    $tz = new DateTimeZone($timezone);
    $f = [];
    // position
    $p = req_val('position', 'position_id');
    if ($p !== null && $p !== '') {
        if (filter_var($p, FILTER_VALIDATE_INT) === false || (int) $p < 1) {
            throw new DomainException('Pick one of this restaurant\'s positions.');
        }
        $f['position_id'] = (int) $p;
    } elseif ($cur !== null) {
        $f['position_id'] = (int) $cur['position_id'];
    } else {
        throw new DomainException('Pick a position.');
    }
    // break
    $b = req_val('break_minutes', 'break');
    if ($b !== null && $b !== '') {
        if (!in_array($b, ['0', '15', '30', '45', '60'], true)) {
            throw new DomainException('A break is 0, 15, 30, 45 or 60 minutes.');
        }
        $f['break'] = (int) $b;
    } else {
        $f['break'] = $cur === null ? 0 : (int) $cur['break_minutes'];
    }
    // the person
    if (req_has('assignee', 'assignee_member_id')) {
        $a = req_val('assignee', 'assignee_member_id');
        if ($a === null || $a === '' || $a === '0') {
            $f['assignee'] = null;
        } elseif (filter_var($a, FILTER_VALIDATE_INT) !== false && (int) $a > 0) {
            $f['assignee'] = (int) $a;
        } else {
            throw new DomainException('Pick a person from the list, or leave the shift open.');
        }
    } else {
        $f['assignee'] = $cur === null ? null : $cur['assignee_member_id'];
    }
    // the note
    if (req_has('note')) {
        $n = (string) req_val('note');
        if (mb_strlen($n) > 200) {
            throw new DomainException('A note may be up to 200 characters.');
        }
        $f['note'] = $n === '' ? null : $n;
    } else {
        $f['note'] = $cur === null ? null : $cur['note'];
    }
    $f['reason'] = null;
    $why = req_val('override_reason');
    if ($why !== null && $why !== '') {
        $f['reason'] = $why;
    }
    // the times
    $curS = $cur === null ? null : local_from_db((string) $cur['starts_at'], $tz);
    $curE = $cur === null ? null : local_from_db((string) $cur['ends_at'], $tz);
    $parts = req_val('date', 'starts', 'ends');
    $sAt = req_val('starts_at');
    $eAt = req_val('ends_at');
    if ($sAt !== null || $eAt !== null) {
        $start = $sAt !== null && $sAt !== '' ? local_moment($sAt, $tz) : $curS;
        if ($start === null) {
            throw new DomainException($sAt !== null && $sAt !== '' ? 'Start the shift with a date and time like 2026-10-09 17:00.' : 'Say when the shift starts.');
        }
        if ($eAt !== null && $eAt !== '') {
            $end = local_moment($eAt, $tz);
            if ($end === null) {
                throw new DomainException('End the shift with a date and time like 2026-10-09 23:00.');
            }
        } elseif ($curS !== null && $curE !== null) {
            $end = $start->modify('+' . (int) round(($curE->getTimestamp() - $curS->getTimestamp()) / 60) . ' minutes');
        } else {
            throw new DomainException('Say when the shift ends.');
        }
    } elseif ($parts !== null) {
        $date = req_val('date');
        if ($date === null || $date === '') {
            $base = $curS;
            if ($base === null) {
                throw new DomainException('Pick the day of the shift.');
            }
            $date = $base->format('Y-m-d');
        }
        $st = req_val('starts');
        $en = req_val('ends');
        $moveOnly = ($st === null || $st === '') && ($en === null || $en === '') && $curS !== null && $curE !== null;
        if ($moveOnly) {
            $start = local_moment($date . ' ' . $curS->format('H:i'), $tz);
            if ($start === null) {
                throw new DomainException('Pick the day of the shift.');
            }
            $days = (int) $curS->setTime(0, 0)->diff($curE->setTime(0, 0))->format('%r%a');
            $endDay = $start->setTime(0, 0)->modify("+$days days");
            $end = local_moment($endDay->format('Y-m-d') . ' ' . $curE->format('H:i'), $tz);
        } else {
            $st = $st === null || $st === '' ? ($curS?->format('H:i')) : $st;
            $en = $en === null || $en === '' ? ($curE?->format('H:i')) : $en;
            if ($st === null || $en === null) {
                throw new DomainException('Say when the shift starts and ends.');
            }
            $start = local_moment($date . ' ' . $st, $tz);
            $end = local_moment($date . ' ' . $en, $tz);
            if ($start === null || $end === null) {
                throw new DomainException('Pick a real day and times for the shift.');
            }
            if ($end <= $start) {
                $end = $end->modify('+1 day');                   // past midnight
            }
        }
        if ($end === null) {
            throw new DomainException('Pick a real day and times for the shift.');
        }
    } elseif ($curS !== null && $curE !== null) {
        $start = $curS;
        $end = $curE;
    } else {
        throw new DomainException('Say when the shift starts and ends.');
    }
    if ((int) $start->format('i') % 15 !== 0 || (int) $end->format('i') % 15 !== 0) {
        throw new DomainException('Times go in 15-minute steps.');
    }
    if ($end <= $start) {
        throw new DomainException('A shift must end after it starts.');
    }
    $minutes = (int) round(($end->getTimestamp() - $start->getTimestamp()) / 60);
    if ($minutes > 16 * 60) {
        throw new DomainException('A shift can be at most 16 hours.');
    }
    if ($f['break'] >= $minutes) {
        throw new DomainException('The break is longer than the shift.');
    }
    $f['starts'] = $start;
    $f['ends'] = $end;
    return $f;
}

/** The week (its Monday, or the site's first day) a local moment falls in. */
function week_start_for(DateTimeImmutable $localStart, int $weekDow): string
{
    return week_start_of($localStart->format('Y-m-d'), $weekDow);
}

/** Is this position one of the restaurant's, and live (not archived)? Throws the sentence when not; a position a shift already has is not re-judged. */
function require_position(PDO $pdo, int $siteId, int $positionId, ?int $keep = null): void
{
    if ($keep === $positionId) {
        return;
    }
    $st = $pdo->prepare('SELECT 1 FROM positions WHERE id = :p AND scope_id = :s AND archived_at IS NULL');
    $st->execute(['p' => $positionId, 's' => $siteId]);
    if ($st->fetchColumn() === false) {
        throw new DomainException('Pick one of this restaurant\'s positions.');
    }
}

// ---- the rules -----------------------------------------------------------------------------------------------------------------
/**
 * What is said about giving this person this shift, without refusing anything: ['name', 'hard' => [sentence…], 'soft' => [warning…]]. A person who does not work
 * here, or who already has a shift then, or a HARD rule that would break, are `hard`; the soft warnings (rule_key, severity, message, member_id, site_id, member_name)
 * are what a manager may go ahead with by giving a reason. An open shift (no person) has nothing to say.
 */
function assignment_findings(PDO $pdo, int $siteId, ?int $memberId, int $positionId, DateTimeImmutable $start, DateTimeImmutable $end, int $break, ?int $ignoreShift, string $tz): array
{
    if ($memberId === null) {
        return ['name' => null, 'hard' => [], 'soft' => []];
    }
    $who = $pdo->prepare("SELECT m.display_name FROM members m JOIN member_site_roles r ON r.member_id = m.id AND r.scope_id = :s
                           WHERE m.id = :m AND m.status = 'active' AND m.member_kind = 'human'");
    $who->execute(['s' => $siteId, 'm' => $memberId]);
    $name = $who->fetchColumn();
    if ($name === false) {
        return ['name' => null, 'hard' => ['That person does not work at this restaurant.'], 'soft' => []];
    }
    $a = utc_text($start);
    $b = utc_text($end);
    $clash = $pdo->prepare("SELECT starts_at, ends_at FROM shifts WHERE assignee_member_id = :m AND status = 'scheduled' AND id IS DISTINCT FROM :ig
                              AND tstzrange(starts_at, ends_at) && tstzrange(:a::timestamptz, :b::timestamptz) ORDER BY starts_at LIMIT 1");
    $clash->execute(['m' => $memberId, 'ig' => $ignoreShift, 'a' => $a, 'b' => $b]);
    if (($c = $clash->fetch()) !== false) {
        return ['name' => (string) $name, 'hard' => [$name . ' already has a shift then (' . shift_when((string) $c['starts_at'], (string) $c['ends_at'], $tz) . ').'], 'soft' => []];
    }
    $hard = [];
    $soft = [];
    foreach (check_assignment($pdo, $memberId, $siteId, $positionId, $a, $b, $break, $ignoreShift) as $w) {
        if ($w['severity'] === 'hard') {
            $hard[] = $name . ': ' . $w['message'];
        } elseif ($w['severity'] === 'soft') {
            $soft[] = $w + ['member_id' => $memberId, 'site_id' => $siteId, 'member_name' => (string) $name];
        }
    }
    return ['name' => (string) $name, 'hard' => $hard, 'soft' => $soft];
}

/**
 * May this person have this shift? Refuses (DomainException) when they do not work here, already have a shift then, or a HARD rule would break; a SOFT rule
 * needs the manager's reason. Answers the soft warnings the reason covers (rule_key, severity, message, member_id, site_id).
 */
function vet_assignment(PDO $pdo, int $siteId, ?int $memberId, int $positionId, DateTimeImmutable $start, DateTimeImmutable $end, int $break, ?int $ignoreShift, ?string $reason, string $tz): array
{
    $f = assignment_findings($pdo, $siteId, $memberId, $positionId, $start, $end, $break, $ignoreShift, $tz);
    if ($f['hard'] !== []) {
        throw new DomainException(implode(' ', $f['hard']));
    }
    if ($f['soft'] !== []) {
        if ($reason === null) {
            throw new DomainException($f['name'] . ': ' . implode(' ', array_column($f['soft'], 'message')) . ' Give a reason (override_reason) to go ahead.');
        }
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
            throw new DomainException('Give a reason of a few words (3 to 500 characters) to go ahead.');
        }
    }
    return $f['soft'];
}

/**
 * A manager went ahead despite soft warnings: one rule_overrides row and one `rule.override` log row each, the same reason. $warnings carry
 * rule_key, message, member_id, site_id. Returns how many were recorded. (context: build | publish)
 */
function record_overrides(PDO $pdo, array $warnings, string $reason, ?int $shiftId, int $by, string $context = 'build'): int
{
    $ins = $pdo->prepare('INSERT INTO rule_overrides (scope_id, shift_id, member_id, rule_key, message, reason, overridden_by, context)
                          VALUES (:s, :sh, :m, :k, :msg, :r, :by, :c) RETURNING id');
    $n = 0;
    foreach ($warnings as $w) {
        $sid = $shiftId ?? (isset($w['shift_id']) ? (int) $w['shift_id'] : null);
        $ins->execute(['s' => (int) $w['site_id'], 'sh' => $sid, 'm' => $w['member_id'] ?? null, 'k' => $w['rule_key'], 'msg' => (string) $w['message'],
                       'r' => $reason, 'by' => $by, 'c' => $context]);
        log_activity($pdo, 'rule.override', 'shift', $sid, ['scope_id' => (int) $w['site_id'],
            'after' => ['override_id' => (int) $ins->fetchColumn(), 'rule_key' => $w['rule_key'], 'message' => (string) $w['message'], 'member_id' => $w['member_id'] ?? null,
                        'reason' => $reason, 'context' => $context]]);
        $n++;
    }
    return $n;
}

// ---- weeks made on the way -----------------------------------------------------------------------------------------------------
/** ensure_week() that logs `week.create` when this call made the week. */
function ensure_week_logged(PDO $pdo, int $siteId, string $weekStart, int $by, string $via = 'shift'): array
{
    $w = ensure_week($pdo, $siteId, $weekStart, $by);
    if ($w['created']) {
        log_activity($pdo, 'week.create', 'week', $w['week_id'], ['scope_id' => $siteId, 'after' => ['week_id' => $w['week_id'], 'week_start' => $weekStart, 'via' => $via]]);
    }
    return $w;
}

/** Insert one shift row; the database's guards (overlap, position of the site, 16 hours) answer for anything the checks missed. */
function insert_shift(PDO $pdo, int $siteId, int $weekId, array $f, int $by): int
{
    $st = $pdo->prepare('INSERT INTO shifts (scope_id, week_id, position_id, starts_at, ends_at, break_minutes, assignee_member_id, note, created_by)
                         VALUES (:s, :w, :p, :a, :b, :br, :m, :n, :by) RETURNING id');
    $st->execute(['s' => $siteId, 'w' => $weekId, 'p' => $f['position_id'], 'a' => utc_text($f['starts']), 'b' => utc_text($f['ends']), 'br' => $f['break'],
                  'm' => $f['assignee'], 'n' => $f['note'], 'by' => $by]);
    return (int) $st->fetchColumn();
}

// ---- a DRAFT week's shifts -----------------------------------------------------------------------------------------------------
/** shift_create: a shift in the DRAFT week its date falls in (the week is made on the way). Answers shift_id, week_id, overridden (the soft warnings covered). */
function create_shift(PDO $pdo, int $siteId, array $f, int $by): array
{
    $site = find_site_row($pdo, $siteId) ?? throw new DomainException('Not found.');
    require_position($pdo, $siteId, $f['position_id']);
    $ws = week_start_for($f['starts'], (int) $site['week_start']);
    $week = ensure_week_logged($pdo, $siteId, $ws, $by);
    if ($week['status'] !== 'draft') {
        throw new DomainException('This week is published — use shift_add.');
    }
    $soft = vet_assignment($pdo, $siteId, $f['assignee'], $f['position_id'], $f['starts'], $f['ends'], $f['break'], null, $f['reason'], (string) $site['timezone']);
    $id = insert_shift($pdo, $siteId, $week['week_id'], $f, $by);
    $n = $soft === [] ? 0 : record_overrides($pdo, $soft, (string) $f['reason'], $id, $by);
    return ['shift_id' => $id, 'week_id' => $week['week_id'], 'week_start' => $ws, 'overridden' => $n];
}

/** What changed between two shift states: [before-only-changed, after-only-changed]. */
function shift_diff(array $before, array $after): array
{
    $b = [];
    $a = [];
    foreach ($after as $k => $v) {
        if (($before[$k] ?? null) !== $v) {
            $b[$k] = $before[$k] ?? null;
            $a[$k] = $v;
        }
    }
    return [$b, $a];
}

/** UPDATE the changeable columns of a shift to $f. */
function write_shift_fields(PDO $pdo, int $id, array $f): void
{
    $st = $pdo->prepare('UPDATE shifts SET position_id = :p, starts_at = :a, ends_at = :b, break_minutes = :br, assignee_member_id = :m, note = :n WHERE id = :id');
    $st->execute(['p' => $f['position_id'], 'a' => utc_text($f['starts']), 'b' => utc_text($f['ends']), 'br' => $f['break'], 'm' => $f['assignee'], 'n' => $f['note'], 'id' => $id]);
}

/** The fields of a shift as they will stand, in the shape shift_log_state() answers. */
function fields_state(array $f): array
{
    return ['position_id' => $f['position_id'], 'starts_at' => json_ts(utc_text($f['starts'])), 'ends_at' => json_ts(utc_text($f['ends'])),
            'break_minutes' => $f['break'], 'assignee_member_id' => $f['assignee'], 'note' => $f['note']];
}

/** shift_update: change a DRAFT shift (any field; an absent one stays). Answers before/after (only what changed), overridden, and the snapshot after. */
function update_shift(PDO $pdo, int $id, int $by): array
{
    $s = shift_snapshot($pdo, $id, true) ?? throw new DomainException('Shift not found.');
    if ($s['status'] !== 'scheduled') {
        throw new DomainException('That shift is cancelled.');
    }
    if ($s['week_status'] === 'published' || $s['published_at'] !== null) {
        throw new DomainException('This week is published — use shift_change.');
    }
    $f = shift_fields_from_request($s, (string) $s['timezone']);
    require_position($pdo, (int) $s['site_id'], $f['position_id'], (int) $s['position_id']);
    if (week_start_for($f['starts'], (int) $s['week_dow']) !== $s['week_start']) {
        throw new DomainException('A shift stays in its own week. Add one in the other week and delete this one.');
    }
    [$before, $after] = shift_diff(shift_log_state($s), fields_state($f));
    if ($after === []) {
        throw new DomainException('Nothing changed.');
    }
    $soft = vet_assignment($pdo, (int) $s['site_id'], $f['assignee'], $f['position_id'], $f['starts'], $f['ends'], $f['break'], $id, $f['reason'], (string) $s['timezone']);
    write_shift_fields($pdo, $id, $f);
    $n = $soft === [] ? 0 : record_overrides($pdo, $soft, (string) $f['reason'], $id, $by);
    return ['shift' => $s, 'before' => $before, 'after' => $after, 'overridden' => $n];
}

/** shift_assign: a DRAFT shift to a person (or back to open). Answers the snapshot, the new holder's name, overridden. */
function assign_shift(PDO $pdo, int $id, ?int $memberId, int $by, ?string $reason): array
{
    $s = shift_snapshot($pdo, $id, true) ?? throw new DomainException('Shift not found.');
    if ($s['status'] !== 'scheduled') {
        throw new DomainException('That shift is cancelled.');
    }
    if ($s['week_status'] === 'published' || $s['published_at'] !== null) {
        throw new DomainException('This week is published — use shift_change.');
    }
    if ($memberId === $s['assignee_member_id']) {
        throw new DomainException('Nothing changed.');
    }
    $tz = new DateTimeZone((string) $s['timezone']);
    $soft = vet_assignment($pdo, (int) $s['site_id'], $memberId, (int) $s['position_id'], local_from_db((string) $s['starts_at'], $tz), local_from_db((string) $s['ends_at'], $tz),
        (int) $s['break_minutes'], $id, $reason, (string) $s['timezone']);
    $st = $pdo->prepare('UPDATE shifts SET assignee_member_id = :m WHERE id = :id');
    $st->execute(['m' => $memberId, 'id' => $id]);
    $n = $soft === [] ? 0 : record_overrides($pdo, $soft, (string) $reason, $id, $by);
    $name = null;
    if ($memberId !== null) {
        $q = $pdo->prepare('SELECT display_name FROM members WHERE id = :m');
        $q->execute(['m' => $memberId]);
        $name = (string) $q->fetchColumn();
    }
    return ['shift' => $s, 'assignee_name' => $name, 'overridden' => $n];
}

/** shift_delete: a DRAFT shift is deleted; a published one never is (the database's trigger says so as well). Answers the snapshot it had. */
function delete_shift(PDO $pdo, int $id, int $by): array
{
    $s = shift_snapshot($pdo, $id, true) ?? throw new DomainException('Shift not found.');
    if ($s['published_at'] !== null || $s['week_status'] === 'published') {
        throw new DomainException('A published shift is cancelled, never deleted.');
    }
    $pdo->prepare('DELETE FROM shifts WHERE id = :id')->execute(['id' => $id]);
    return $s;
}

// ---- a PUBLISHED week's shifts -------------------------------------------------------------------------------------------------
/** Withdraw the trades still going on a shift (it changed under them). Returns how many. */
function cancel_live_exchanges(PDO $pdo, int $shiftId, int $siteId, int $by, string $why): int
{
    $st = $pdo->prepare("SELECT id FROM exchanges WHERE (shift_id = :s1 OR swap_shift_id = :s2) AND status IN ('open', 'pending_acceptance', 'pending_approval') ORDER BY id FOR UPDATE");
    $st->execute(['s1' => $shiftId, 's2' => $shiftId]);
    $n = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $xid) {
        $pdo->prepare('SELECT ts_exchange_cancel(:x, :by)')->execute(['x' => (int) $xid, 'by' => $by]);
        log_activity($pdo, 'exchange.cancel', 'exchange', (int) $xid, ['scope_id' => $siteId, 'after' => ['exchange_id' => (int) $xid, 'shift_id' => $shiftId, 'status' => 'cancelled', 'via' => $why]]);
        $n++;
    }
    return $n;
}

/** Queue a `shift_changed` notice to one person (email and text by their choice). No pay, no phone: the facts and a link. */
function notify_shift(PDO $pdo, int $memberId, array $s, string $subject, string $lead): void
{
    notify($pdo, $memberId, (int) $s['site_id'], 'shift_changed', $subject, $lead . ' ' . shift_facts($s) . ' ' . app_url('/shifts/' . (int) $s['shift_id']), 'shift:' . (int) $s['shift_id']);
}

/** shift_add: a shift in a PUBLISHED week — live at once, its holder told. Answers shift_id, notified, overridden. */
function add_live_shift(PDO $pdo, int $siteId, array $f, int $by): array
{
    $site = find_site_row($pdo, $siteId) ?? throw new DomainException('Not found.');
    require_position($pdo, $siteId, $f['position_id']);
    $ws = week_start_for($f['starts'], (int) $site['week_start']);
    $week = find_week($pdo, $siteId, $ws);
    if ($week === null || $week['status'] !== 'published') {
        throw new DomainException('That week is not published yet — use shift_create.');
    }
    $soft = vet_assignment($pdo, $siteId, $f['assignee'], $f['position_id'], $f['starts'], $f['ends'], $f['break'], null, $f['reason'], (string) $site['timezone']);
    $id = insert_shift($pdo, $siteId, $week['week_id'], $f, $by);
    $n = $soft === [] ? 0 : record_overrides($pdo, $soft, (string) $f['reason'], $id, $by);
    $s = shift_snapshot($pdo, $id) ?? throw new DomainException('Shift not found.');
    $notified = [];
    if ($s['assignee_member_id'] !== null) {
        notify_shift($pdo, $s['assignee_member_id'], $s, 'A shift was added to your schedule', 'A shift was added to your schedule:');
        $notified[] = $s['assignee_member_id'];
    }
    return ['shift_id' => $id, 'week_id' => $week['week_id'], 'notified' => $notified, 'overridden' => $n, 'shift' => $s];
}

/** shift_change: change a shift of a PUBLISHED week (stamped by the trigger); the holder — the old one and the new one on a reassignment — is told; a trade going on it is withdrawn. */
function change_live_shift(PDO $pdo, int $id, int $by): array
{
    $s = shift_snapshot($pdo, $id, true) ?? throw new DomainException('Shift not found.');
    if ($s['week_status'] !== 'published') {
        throw new DomainException('This week is a draft — use shift_update.');
    }
    if ($s['status'] !== 'scheduled') {
        throw new DomainException('That shift is cancelled.');
    }
    if (strtotime((string) $s['ends_at']) < time()) {
        throw new DomainException('That shift is over.');
    }
    $f = shift_fields_from_request($s, (string) $s['timezone']);
    require_position($pdo, (int) $s['site_id'], $f['position_id'], (int) $s['position_id']);
    if (week_start_for($f['starts'], (int) $s['week_dow']) !== $s['week_start']) {
        throw new DomainException('A shift stays in its own week. Cancel it and add one in the other week.');
    }
    [$before, $after] = shift_diff(shift_log_state($s), fields_state($f));
    if ($after === []) {
        throw new DomainException('Nothing changed.');
    }
    $soft = vet_assignment($pdo, (int) $s['site_id'], $f['assignee'], $f['position_id'], $f['starts'], $f['ends'], $f['break'], $id, $f['reason'], (string) $s['timezone']);
    write_shift_fields($pdo, $id, $f);
    $n = $soft === [] ? 0 : record_overrides($pdo, $soft, (string) $f['reason'], $id, $by);
    $now = shift_snapshot($pdo, $id) ?? throw new DomainException('Shift not found.');
    $cancelled = 0;
    if (array_intersect(array_keys($after), ['position_id', 'starts_at', 'ends_at', 'assignee_member_id']) !== []) {
        $cancelled = cancel_live_exchanges($pdo, $id, (int) $s['site_id'], $by, 'shift_change');
    }
    $notified = [];
    $old = $s['assignee_member_id'];
    $new = $now['assignee_member_id'];
    if ($old !== $new) {
        if ($old !== null) {
            notify_shift($pdo, $old, $s, 'A shift was taken off your schedule', 'A shift was taken off your schedule:');
            $notified[] = $old;
        }
        if ($new !== null) {
            notify_shift($pdo, $new, $now, 'A shift was added to your schedule', 'A shift was added to your schedule:');
            $notified[] = $new;
        }
    } elseif ($new !== null) {
        notify_shift($pdo, $new, $now, 'Your shift changed', 'Your shift changed (it was ' . shift_when((string) $s['starts_at'], (string) $s['ends_at'], (string) $s['timezone'], true) . '):');
        $notified[] = $new;
    }
    return ['shift' => $now, 'before' => $before, 'after' => $after, 'notified' => $notified, 'exchange_cancelled' => $cancelled, 'overridden' => $n];
}

/** shift_cancel: a shift of a PUBLISHED week is cancelled and kept; its trade is withdrawn and its holder told. */
function cancel_live_shift(PDO $pdo, int $id, string $reason, int $by): array
{
    if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
        throw new DomainException('Give a reason of a few words (3 to 500 characters).');
    }
    $s = shift_snapshot($pdo, $id, true) ?? throw new DomainException('Shift not found.');
    if ($s['week_status'] !== 'published') {
        throw new DomainException('This week is a draft — delete the shift instead (shift_delete).');
    }
    if ($s['status'] !== 'scheduled') {
        throw new DomainException('That shift is already cancelled.');
    }
    if (strtotime((string) $s['ends_at']) < time()) {
        throw new DomainException('That shift is over.');
    }
    $pdo->prepare("UPDATE shifts SET status = 'cancelled', cancelled_at = now(), cancel_reason = :r WHERE id = :id")->execute(['r' => $reason, 'id' => $id]);
    $cancelled = cancel_live_exchanges($pdo, $id, (int) $s['site_id'], $by, 'shift_cancel');
    $notified = [];
    if ($s['assignee_member_id'] !== null) {
        notify_shift($pdo, $s['assignee_member_id'], $s, 'Your shift was cancelled', 'Your shift was cancelled (' . $reason . '):');
        $notified[] = $s['assignee_member_id'];
    }
    return ['shift' => $s, 'notified' => $notified, 'exchange_cancelled' => $cancelled];
}

// ---- who may be put on a shift without asking (templates, copying, auto-fill) ----------------------------------------------------
/**
 * Would placing this person on this shift be refused outright? Returns ['reason' => ?string, 'soft' => [warning…]]: a reason when they no longer work here,
 * have approved time off then (whatever the rule's severity — a person on leave is never placed), already have a shift then, or a HARD rule would break;
 * the soft warnings travel along (the builder shows them, publishing asks for the reason). Used where nothing can ask a manager: copying, templates, auto-fill.
 */
function assignment_blocker(PDO $pdo, int $siteId, int $memberId, int $positionId, DateTimeImmutable $start, DateTimeImmutable $end, int $break, string $tz): array
{
    $who = $pdo->prepare("SELECT m.display_name FROM members m JOIN member_site_roles r ON r.member_id = m.id AND r.scope_id = :s
                           WHERE m.id = :m AND m.status = 'active' AND m.member_kind = 'human'");
    $who->execute(['s' => $siteId, 'm' => $memberId]);
    $name = $who->fetchColumn();
    if ($name === false) {
        return ['reason' => 'That person no longer works here.', 'soft' => []];
    }
    $a = utc_text($start);
    $b = utc_text($end);
    $off = $pdo->prepare("SELECT 1 FROM time_off_requests WHERE member_id = :m AND status = 'approved' AND tstzrange(starts_at, ends_at) && tstzrange(:a::timestamptz, :b::timestamptz) LIMIT 1");
    $off->execute(['m' => $memberId, 'a' => $a, 'b' => $b]);
    if ($off->fetchColumn() !== false) {
        return ['reason' => $name . ' has time off ' . $start->format('l') . '.', 'soft' => []];
    }
    $clash = $pdo->prepare("SELECT 1 FROM shifts WHERE assignee_member_id = :m AND status = 'scheduled' AND tstzrange(starts_at, ends_at) && tstzrange(:a::timestamptz, :b::timestamptz) LIMIT 1");
    $clash->execute(['m' => $memberId, 'a' => $a, 'b' => $b]);
    if ($clash->fetchColumn() !== false) {
        return ['reason' => $name . ' already has a shift ' . $start->format('l') . ' then.', 'soft' => []];
    }
    $hard = [];
    $soft = [];
    foreach (check_assignment($pdo, $memberId, $siteId, $positionId, $a, $b, $break, null) as $w) {
        if ($w['severity'] === 'hard') {
            $hard[] = $w['message'];
        } elseif ($w['severity'] === 'soft') {
            $soft[] = $w + ['member_id' => $memberId, 'site_id' => $siteId, 'member_name' => $name];
        }
    }
    if ($hard !== []) {
        return ['reason' => $name . ': ' . implode(' ', $hard), 'soft' => []];
    }
    return ['reason' => null, 'soft' => $soft];
}

/** Does an approved availability row of this kind (unavailable | preferred) overlap the shift? The same window arithmetic as the rules engine (db/009). */
function availability_overlaps(PDO $pdo, int $memberId, int $siteId, string $kind, DateTimeImmutable $start, DateTimeImmutable $end, string $tz): bool
{
    $day = $start->format('Y-m-d');
    $st = $pdo->prepare("SELECT 1 FROM availability_rules a
                          WHERE a.member_id = :m AND a.status = 'approved' AND a.kind = :k AND (a.scope_id IS NULL OR a.scope_id = :s)
                            AND a.weekday = EXTRACT(DOW FROM CAST(:d1 AS date))::int
                            AND a.effective_from <= CAST(:d2 AS date) AND (a.effective_to IS NULL OR a.effective_to >= CAST(:d3 AS date))
                            AND tstzrange((CAST(:d4 AS date) + a.starts_at) AT TIME ZONE :tz1,
                                          (CAST(:d5 AS date) + a.ends_at + CASE WHEN a.ends_at <= a.starts_at THEN interval '1 day' ELSE interval '0' END) AT TIME ZONE :tz2)
                                && tstzrange(:a::timestamptz, :b::timestamptz) LIMIT 1");
    $st->execute(['m' => $memberId, 'k' => $kind, 's' => $siteId, 'd1' => $day, 'd2' => $day, 'd3' => $day, 'd4' => $day, 'd5' => $day, 'tz1' => $tz, 'tz2' => $tz,
                  'a' => utc_text($start), 'b' => utc_text($end)]);
    return $st->fetchColumn() !== false;
}

/**
 * The live check behind the shift form (and its first render): read the fields as they stand (an absent one falls back to $snap, the shift being edited) and say what the rules
 * think. Answers ['error' => sentence] when the fields do not yet make a shift, else ['findings' => assignment_findings(), 'starts' => local, 'ends' => local, 'assignee' => ?int].
 */
function shift_form_findings(PDO $pdo, int $siteId, string $tz, ?array $snap): array
{
    try {
        $f = shift_fields_from_request($snap, $tz);
        require_position($pdo, $siteId, $f['position_id'], $snap === null ? null : (int) $snap['position_id']);
    } catch (DomainException $e) {
        return ['error' => $e->getMessage()];
    }
    return ['findings' => assignment_findings($pdo, $siteId, $f['assignee'], $f['position_id'], $f['starts'], $f['ends'], $f['break'], $snap === null ? null : (int) $snap['shift_id'], $tz),
            'starts' => $f['starts'], 'ends' => $f['ends'], 'assignee' => $f['assignee']];
}
