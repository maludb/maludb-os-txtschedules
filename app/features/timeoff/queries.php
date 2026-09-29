<?php
declare(strict_types=1);

/**
 * Time off (slice 3: docs/build-specs/availability-time-off.md) — types per restaurant, requests a manager decides, balances in hours with the ledger behind them, blackout dates.
 * Read through the mcp_* views; a balance moves ONLY through the database's ts_time_off_post() (decide, cancel and adjust call it). The database is the referee: it refuses a blackout
 * date, a type the restaurant does not offer, a request past the balance (SQLSTATE P0001) in the sentence a person reads; these functions pass it through. Hours, never pay.
 */

const TIME_OFF_SELECT = 'SELECT q.request_id, q.member_id, q.member_name, q.site_id, st.name AS site_name, st.timezone, q.type_id, q.type_name, t.paid, t.tracks_balance, t.allow_negative,
        q.starts_at, q.ends_at, q.hours, q.note, q.status, q.decided_by, dm.display_name AS decided_by_name, q.decided_at, q.decision_note, q.created_at,
        (SELECT b.balance_hours FROM mcp_time_off_balances b WHERE b.member_id = q.member_id AND b.type_id = q.type_id) AS balance_hours
   FROM mcp_time_off_requests q
   JOIN mcp_sites st ON st.site_id = q.site_id
   JOIN mcp_time_off_types t ON t.type_id = q.type_id
   LEFT JOIN members dm ON dm.id = q.decided_by';

function decode_time_off_rows(array $rows): array
{
    foreach ($rows as &$r) {
        foreach (['request_id', 'member_id', 'site_id', 'type_id'] as $k) {
            $r[$k] = (int) $r[$k];
        }
        $r['decided_by'] = $r['decided_by'] === null ? null : (int) $r['decided_by'];
        $r['hours'] = (float) $r['hours'];
        $r['balance_hours'] = $r['balance_hours'] === null ? null : (float) $r['balance_hours'];
        foreach (['paid', 'tracks_balance', 'allow_negative'] as $k) {
            $r[$k] = (bool) $r[$k];
        }
    }
    unset($r);
    return $rows;
}

/**
 * Requests the caller may see (the view's rule: their own, and at restaurants where they approve or build). $filters: member_id, site_id, site_ids (array — only these), status, from, to (local dates:
 * the request touches that range in its restaurant's zone), on (a local date the request covers — the "who is off" list), page. Answers ['rows' => …, 'more' => bool, 'page' => int].
 */
function find_time_off(PDO $pdo, array $filters, int $page = 1, int $per = 50): array
{
    $where = ['true'];
    $args = [];
    if (!empty($filters['member_id'])) {
        $where[] = 'q.member_id = :m';
        $args['m'] = (int) $filters['member_id'];
    }
    if (!empty($filters['site_id'])) {
        $where[] = 'q.site_id = :s';
        $args['s'] = (int) $filters['site_id'];
    }
    if (isset($filters['site_ids'])) {
        $where[] = 'q.site_id = ANY (CAST(:sids AS bigint[]))';
        $args['sids'] = '{' . implode(',', array_map('intval', $filters['site_ids'])) . '}';
    }
    if (!empty($filters['status'])) {
        $where[] = 'q.status = :st';
        $args['st'] = $filters['status'];
    }
    if (!empty($filters['from'])) {
        $where[] = "((q.ends_at - interval '1 second') AT TIME ZONE st.timezone)::date >= CAST(:from AS date)";
        $args['from'] = $filters['from'];
    }
    if (!empty($filters['to'])) {
        $where[] = '(q.starts_at AT TIME ZONE st.timezone)::date <= CAST(:to AS date)';
        $args['to'] = $filters['to'];
    }
    if (!empty($filters['on'])) {
        $where[] = "q.status = 'approved' AND (q.starts_at AT TIME ZONE st.timezone)::date <= CAST(:on1 AS date) AND ((q.ends_at - interval '1 second') AT TIME ZONE st.timezone)::date >= CAST(:on2 AS date)";
        $args['on1'] = $filters['on'];
        $args['on2'] = $filters['on'];
    }
    $page = max(1, $page);
    $sql = TIME_OFF_SELECT . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . (!empty($filters['on']) ? 'q.member_name' : "CASE q.status WHEN 'pending' THEN 0 ELSE 1 END, q.starts_at DESC") . ', q.request_id DESC LIMIT ' . ($per + 1) . ' OFFSET ' . (($page - 1) * $per);
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $rows = decode_time_off_rows($st->fetchAll());
    $more = count($rows) > $per;
    return ['rows' => array_slice($rows, 0, $per), 'more' => $more, 'page' => $page];
}

function find_time_off_request(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare(TIME_OFF_SELECT . ' WHERE q.request_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : decode_time_off_rows([$r])[0];
}

/** The request's own site — the one base-table read: a write derives its site from the record, never the session. Null when there is none. */
function time_off_site_id(PDO $pdo, int $requestId): ?int
{
    $st = $pdo->prepare('SELECT scope_id FROM time_off_requests WHERE id = :id');
    $st->execute(['id' => $requestId]);
    $v = $st->fetchColumn();
    return $v === false ? null : (int) $v;
}

/** The request as the database has it now, locked when asked: [id, member_id, scope_id, type_id, starts_at, ends_at, hours, status, timezone, site_name, type_name]. */
function time_off_snapshot(PDO $pdo, int $id, bool $lock = false): ?array
{
    $st = $pdo->prepare('SELECT q.id AS request_id, q.member_id, m.display_name AS member_name, q.scope_id AS site_id, q.type_id, t.name AS type_name, t.tracks_balance, q.starts_at, q.ends_at, q.hours, q.note, q.status,
                                st.timezone, st.name AS site_name
                           FROM time_off_requests q JOIN members m ON m.id = q.member_id JOIN time_off_types t ON t.id = q.type_id JOIN sites st ON st.scope_id = q.scope_id
                          WHERE q.id = :id' . ($lock ? ' FOR UPDATE OF q' : ''));
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    foreach (['request_id', 'member_id', 'site_id', 'type_id'] as $k) {
        $r[$k] = (int) $r[$k];
    }
    $r['hours'] = (float) $r['hours'];
    $r['tracks_balance'] = (bool) $r['tracks_balance'];
    return $r;
}

/**
 * The published, scheduled shifts of the request's person at its restaurant that the time would cover and that have not ended — the shifts "Also open those shifts" would open.
 * Base tables: the approver reads the shifts of the person they decide for, at their own restaurant (the handler and the screen gate by requests.approve there).
 */
function request_shifts(PDO $pdo, int $requestId, bool $onlyFuture = true): array
{
    $st = $pdo->prepare("SELECT s.id AS shift_id, s.starts_at, s.ends_at, p.name AS position_name, p.color AS position_color, st.timezone, w.week_start::text AS week_start
                           FROM time_off_requests q
                           JOIN shifts s ON s.assignee_member_id = q.member_id AND s.scope_id = q.scope_id AND s.status = 'scheduled'
                           JOIN schedule_weeks w ON w.id = s.week_id AND w.status = 'published'
                           JOIN positions p ON p.id = s.position_id
                           JOIN sites st ON st.scope_id = s.scope_id
                          WHERE q.id = :id AND tstzrange(s.starts_at, s.ends_at) && tstzrange(q.starts_at, q.ends_at)" . ($onlyFuture ? ' AND s.ends_at > now()' : '') . '
                          ORDER BY s.starts_at, s.id');
    $st->execute(['id' => $requestId]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['shift_id'] = (int) $r['shift_id'];
    }
    unset($r);
    return $rows;
}

/** Requests waiting for the caller: pending, at restaurants where they hold requests.approve, never their own — oldest first. */
function find_time_off_approvals(PDO $pdo, array $siteIds, int $memberId): array
{
    if ($siteIds === []) {
        return [];
    }
    $st = $pdo->prepare(TIME_OFF_SELECT . " WHERE q.status = 'pending' AND q.member_id <> :me AND q.site_id = ANY (CAST(:s AS bigint[])) ORDER BY q.created_at, q.request_id");
    $st->execute(['me' => $memberId, 's' => '{' . implode(',', array_map('intval', $siteIds)) . '}']);
    return decode_time_off_rows($st->fetchAll());
}

/** A person's own requests for My requests: 'waiting' (pending), 'decided' (approved, declined, cancelled) or 'all'. */
function find_my_time_off(PDO $pdo, int $memberId, string $state): array
{
    $cond = $state === 'waiting' ? " AND q.status = 'pending'" : ($state === 'decided' ? " AND q.status <> 'pending'" : '');
    $st = $pdo->prepare(TIME_OFF_SELECT . ' WHERE q.member_id = :m' . $cond . ' ORDER BY q.starts_at DESC, q.request_id DESC LIMIT 100');
    $st->execute(['m' => $memberId]);
    return decode_time_off_rows($st->fetchAll());
}

/** One request's story, newest first (mcp_activity_log, entity time_off_request). */
function time_off_history(PDO $pdo, int $id, int $limit = 50): array
{
    $st = $pdo->prepare("SELECT activity_id, occurred_at, actor_member_id, actor_name, source, action, entity_type, entity_id, before, after
                           FROM mcp_activity_log WHERE action <> 'screen.view' AND entity_type = 'time_off_request' AND entity_id = :id
                          ORDER BY occurred_at DESC, activity_id DESC LIMIT " . max(1, min(200, $limit)));
    $st->execute(['id' => $id]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['after'] = $r['after'] === null ? [] : (json_decode((string) $r['after'], true) ?: []);
        $r['before'] = $r['before'] === null ? [] : (json_decode((string) $r['before'], true) ?: []);
    }
    unset($r);
    return $rows;
}

/** How many requests wait for the caller at a site (part of the menu badge). */
function count_time_off_approvals(PDO $pdo, int $siteId, int $memberId): int
{
    return count(find_time_off_approvals($pdo, has_right('requests.approve', $siteId) ? [$siteId] : [], $memberId));
}

// ---- types and blackout dates ---------------------------------------------------------------------------------------------------
/** A restaurant's types (mcp_time_off_types), active ones by default, in the restaurant's order. */
function find_time_off_types(PDO $pdo, int $siteId, bool $withArchived = false): array
{
    $st = $pdo->prepare('SELECT t.type_id, t.site_id, t.key, t.name, t.paid, t.tracks_balance, t.allow_negative, t.archived_at, x.sort_order
                           FROM mcp_time_off_types t JOIN time_off_types x ON x.id = t.type_id
                          WHERE t.site_id = :s' . ($withArchived ? '' : ' AND t.archived_at IS NULL') . ' ORDER BY (t.archived_at IS NOT NULL), x.sort_order, t.name, t.type_id');
    $st->execute(['s' => $siteId]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['type_id'] = (int) $r['type_id'];
        $r['site_id'] = (int) $r['site_id'];
        $r['sort_order'] = (int) $r['sort_order'];
        foreach (['paid', 'tracks_balance', 'allow_negative'] as $k) {
            $r[$k] = (bool) $r[$k];
        }
    }
    unset($r);
    return $rows;
}

/** One type by id (mcp_time_off_types — held restaurants only). */
function find_time_off_type(PDO $pdo, int $typeId): ?array
{
    $st = $pdo->prepare('SELECT t.type_id, t.site_id, t.key, t.name, t.paid, t.tracks_balance, t.allow_negative, t.archived_at, x.sort_order
                           FROM mcp_time_off_types t JOIN time_off_types x ON x.id = t.type_id WHERE t.type_id = :id');
    $st->execute(['id' => $typeId]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    foreach (['type_id', 'site_id', 'sort_order'] as $k) {
        $r[$k] = (int) $r[$k];
    }
    foreach (['paid', 'tracks_balance', 'allow_negative'] as $k) {
        $r[$k] = (bool) $r[$k];
    }
    return $r;
}

/** The type's own site (base table) for a write; null when none. */
function time_off_type_site_id(PDO $pdo, int $typeId): ?int
{
    $st = $pdo->prepare('SELECT scope_id FROM time_off_types WHERE id = :id');
    $st->execute(['id' => $typeId]);
    $v = $st->fetchColumn();
    return $v === false ? null : (int) $v;
}

function find_blackouts(PDO $pdo, int $siteId, bool $upcomingOnly = true): array
{
    $st = $pdo->prepare('SELECT blackout_id, site_id, on_date::text AS on_date, reason FROM mcp_blackout_dates WHERE site_id = :s' . ($upcomingOnly ? ' AND on_date >= current_date - 1' : '') . ' ORDER BY on_date');
    $st->execute(['s' => $siteId]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['blackout_id'] = (int) $r['blackout_id'];
        $r['site_id'] = (int) $r['site_id'];
    }
    unset($r);
    return $rows;
}

function blackout_site_id(PDO $pdo, int $blackoutId): ?int
{
    $st = $pdo->prepare('SELECT scope_id FROM blackout_dates WHERE id = :id');
    $st->execute(['id' => $blackoutId]);
    $v = $st->fetchColumn();
    return $v === false ? null : (int) $v;
}

// ---- balances --------------------------------------------------------------------------------------------------------------------
/**
 * A person's balances: one row per balance-keeping type at the restaurants in $siteIds (an archived type only when a balance remains), the balance 0 when none was ever posted.
 * The caller passes only the restaurants they may read (their own person: every one they work at; another: where they hold requests.approve).
 */
function find_balances(PDO $pdo, int $memberId, array $siteIds): array
{
    if ($siteIds === []) {
        return [];
    }
    $st = $pdo->prepare('SELECT t.type_id, t.site_id, st.name AS site_name, t.name, t.key, t.paid, t.allow_negative, t.archived_at, COALESCE(b.balance_hours, 0) AS balance_hours, b.updated_at
                           FROM mcp_time_off_types t
                           JOIN mcp_sites st ON st.site_id = t.site_id
                           JOIN time_off_types x ON x.id = t.type_id
                           LEFT JOIN mcp_time_off_balances b ON b.type_id = t.type_id AND b.member_id = :m
                          WHERE t.tracks_balance AND t.site_id = ANY (CAST(:s AS bigint[])) AND (t.archived_at IS NULL OR b.balance_hours IS NOT NULL)
                          ORDER BY st.name, (t.archived_at IS NOT NULL), x.sort_order, t.name');
    $st->execute(['m' => $memberId, 's' => '{' . implode(',', array_map('intval', $siteIds)) . '}']);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['type_id'] = (int) $r['type_id'];
        $r['site_id'] = (int) $r['site_id'];
        $r['balance_hours'] = (float) $r['balance_hours'];
        $r['allow_negative'] = (bool) $r['allow_negative'];
        $r['paid'] = (bool) $r['paid'];
    }
    unset($r);
    return $rows;
}

/** The ledger behind a balance, newest first: grants, approvals, cancellations, adjustments. The caller has already been allowed to read this person's balance. */
function balance_ledger(PDO $pdo, int $memberId, int $typeId, int $limit = 50): array
{
    $st = $pdo->prepare('SELECT l.id AS ledger_id, l.created_at, l.delta_hours, l.reason, l.request_id, l.note, l.recorded_by, rm.display_name AS recorded_by_name
                           FROM time_off_ledger l LEFT JOIN members rm ON rm.id = l.recorded_by
                          WHERE l.member_id = :m AND l.type_id = :t ORDER BY l.created_at DESC, l.id DESC LIMIT ' . max(1, min(200, $limit)));
    $st->execute(['m' => $memberId, 't' => $typeId]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['ledger_id'] = (int) $r['ledger_id'];
        $r['delta_hours'] = (float) $r['delta_hours'];
        $r['request_id'] = $r['request_id'] === null ? null : (int) $r['request_id'];
    }
    unset($r);
    return $rows;
}

/** A person's balance of one type right now (0 when none was ever posted); the base table, for a write's answer. */
function current_balance(PDO $pdo, int $memberId, int $typeId): float
{
    $st = $pdo->prepare('SELECT balance_hours FROM time_off_balances WHERE member_id = :m AND type_id = :t');
    $st->execute(['m' => $memberId, 't' => $typeId]);
    $v = $st->fetchColumn();
    return $v === false ? 0.0 : (float) $v;
}

// ---- writes (each inside the handler's transaction; each logs nothing itself) ---------------------------------------------------------
/**
 * Insert a request. $f = member_id, site_id (the type's), type_id, starts, ends (DateTimeImmutable, UTC-convertible), hours (?float — null lets the database count it, D13), note.
 * The database's trigger refuses a blackout date and a type the restaurant does not offer; a request that overlaps one already pending or approved is refused here. Answers [id, hours].
 */
function create_time_off(PDO $pdo, array $f, int $by): array
{
    $clash = $pdo->prepare("SELECT 1 FROM time_off_requests WHERE member_id = :m AND status IN ('pending', 'approved') AND tstzrange(starts_at, ends_at) && tstzrange(CAST(:a AS timestamptz), CAST(:b AS timestamptz)) LIMIT 1");
    $clash->execute(['m' => $f['member_id'], 'a' => utc_text($f['starts']), 'b' => utc_text($f['ends'])]);
    if ($clash->fetchColumn() !== false) {
        throw new DomainException('There is already time off asked for or approved then.');
    }
    $st = $pdo->prepare('INSERT INTO time_off_requests (member_id, scope_id, type_id, starts_at, ends_at, hours, note) VALUES (:m, :s, :t, :a, :b, :h, :n) RETURNING id, hours');
    $st->execute(['m' => $f['member_id'], 's' => $f['site_id'], 't' => $f['type_id'], 'a' => utc_text($f['starts']), 'b' => utc_text($f['ends']), 'h' => $f['hours'], 'n' => $f['note']]);
    $r = $st->fetch();
    return ['id' => (int) $r['id'], 'hours' => (float) $r['hours']];
}

/** Decide a pending request through ts_time_off_decide() (approve draws the balance down or refuses with "Not enough …"). Answers ['status', 'balance_hours' (?float, when the type keeps one)]. */
function decide_time_off(PDO $pdo, int $id, bool $approve, int $by, ?string $note): array
{
    $st = $pdo->prepare('SELECT ts_time_off_decide(:id, :ap, :by, :n)');
    $st->bindValue('id', $id, PDO::PARAM_INT);
    $st->bindValue('ap', $approve, PDO::PARAM_BOOL);
    $st->bindValue('by', $by, PDO::PARAM_INT);
    $st->bindValue('n', $note);
    $st->execute();
    $status = (string) $st->fetchColumn();
    $s = time_off_snapshot($pdo, $id);
    return ['status' => $status, 'balance_hours' => $s !== null && $s['tracks_balance'] ? current_balance($pdo, $s['member_id'], $s['type_id']) : null];
}

/** Cancel a pending or approved request through ts_time_off_cancel() (an approved one gives its hours back). Answers the status. */
function cancel_time_off(PDO $pdo, int $id, int $by): string
{
    $st = $pdo->prepare('SELECT ts_time_off_cancel(:id, :by)');
    $st->execute(['id' => $id, 'by' => $by]);
    return (string) $st->fetchColumn();
}

/** Move a balance by hand through ts_time_off_post(): a positive delta is a `grant`, a negative an `adjustment`. Answers ['balance' => the new balance (null when the kind keeps none), 'ledger_id' => the row it wrote]. */
function adjust_balance(PDO $pdo, int $memberId, int $typeId, float $deltaHours, string $reason, int $by): array
{
    $st = $pdo->prepare('SELECT ts_time_off_post(:m, :t, :d, :r, NULL, :n, :by)');
    $st->execute(['m' => $memberId, 't' => $typeId, 'd' => round($deltaHours, 2), 'r' => $deltaHours > 0 ? 'grant' : 'adjustment', 'n' => $reason, 'by' => $by]);
    $v = $st->fetchColumn();
    $l = $pdo->prepare('SELECT max(id) FROM time_off_ledger WHERE member_id = :m AND type_id = :t');
    $l->execute(['m' => $memberId, 't' => $typeId]);
    return ['balance' => $v === false || $v === null ? null : (float) $v, 'ledger_id' => (int) $l->fetchColumn()];
}

/** "Vacation / PTO" → "vacation_pto": a key the restaurant has not used (a number is added when it has). */
function time_off_type_key(PDO $pdo, int $siteId, string $name): string
{
    $base = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($name)), '_');
    $base = $base === '' || !ctype_alpha($base[0]) ? 'type_' . $base : $base;
    $base = rtrim(substr($base, 0, 26), '_');
    $key = $base;
    $st = $pdo->prepare('SELECT 1 FROM time_off_types WHERE scope_id = :s AND key = :k');
    for ($i = 2; $i < 100; $i++) {
        $st->execute(['s' => $siteId, 'k' => $key]);
        if ($st->fetchColumn() === false) {
            return $key;
        }
        $key = $base . '_' . $i;
    }
    throw new DomainException('Choose a different name for this kind of time off.');
}

/** Add ($id null) or change a type. $f = name, paid, tracks_balance, allow_negative, sort_order. Answers [id, before (?array)]. */
function save_time_off_type(PDO $pdo, int $siteId, ?int $id, array $f, int $by): array
{
    $name = trim((string) $f['name']);
    if ($name === '' || mb_strlen($name) > 60) {
        throw new DomainException('Give the kind of time off a name of up to 60 characters.');
    }
    if ($f['allow_negative'] && !$f['tracks_balance']) {
        throw new DomainException('Only a kind that keeps a balance can go below zero.');
    }
    if ($id === null) {
        $ins = $pdo->prepare('INSERT INTO time_off_types (scope_id, key, name, paid, tracks_balance, allow_negative, sort_order) VALUES (:s, :k, :n, :p, :t, :a, :o) RETURNING id');
        $ins->bindValue('s', $siteId, PDO::PARAM_INT);
        $ins->bindValue('k', time_off_type_key($pdo, $siteId, $name));
        $ins->bindValue('n', $name);
        $ins->bindValue('p', (bool) $f['paid'], PDO::PARAM_BOOL);
        $ins->bindValue('t', (bool) $f['tracks_balance'], PDO::PARAM_BOOL);
        $ins->bindValue('a', (bool) $f['allow_negative'], PDO::PARAM_BOOL);
        $ins->bindValue('o', (int) $f['sort_order'], PDO::PARAM_INT);
        $ins->execute();
        return ['id' => (int) $ins->fetchColumn(), 'before' => null];
    }
    $before = find_time_off_type($pdo, $id) ?? throw new DomainException('That kind of time off is not here.');
    if ($before['archived_at'] !== null) {
        throw new DomainException('That kind of time off is archived.');
    }
    $up = $pdo->prepare('UPDATE time_off_types SET name = :n, paid = :p, tracks_balance = :t, allow_negative = :a, sort_order = :o WHERE id = :id');
    $up->bindValue('n', $name);
    $up->bindValue('p', (bool) $f['paid'], PDO::PARAM_BOOL);
    $up->bindValue('t', (bool) $f['tracks_balance'], PDO::PARAM_BOOL);
    $up->bindValue('a', (bool) $f['allow_negative'], PDO::PARAM_BOOL);
    $up->bindValue('o', (int) $f['sort_order'], PDO::PARAM_INT);
    $up->bindValue('id', $id, PDO::PARAM_INT);
    $up->execute();
    return ['id' => $id, 'before' => $before];
}

/** Archive a type: it stays on old requests and balances and leaves the picker. */
function archive_time_off_type(PDO $pdo, int $id, int $by): array
{
    $before = find_time_off_type($pdo, $id) ?? throw new DomainException('That kind of time off is not here.');
    if ($before['archived_at'] !== null) {
        throw new DomainException('That kind of time off is already archived.');
    }
    $pdo->prepare('UPDATE time_off_types SET archived_at = now() WHERE id = :id')->execute(['id' => $id]);
    return $before;
}

/** Black out a date: no time off may be asked that touches it (requests already asked stay as they are). Answers the id. */
function save_blackout(PDO $pdo, int $siteId, string $date, string $reason, int $by): int
{
    $reason = trim($reason);
    if ($reason === '' || mb_strlen($reason) > 200) {
        throw new DomainException('Give the reason in a few words (up to 200 characters).');
    }
    $st = $pdo->prepare('INSERT INTO blackout_dates (scope_id, on_date, reason) VALUES (:s, :d, :r) ON CONFLICT (scope_id, on_date) DO NOTHING RETURNING id');
    $st->execute(['s' => $siteId, 'd' => $date, 'r' => $reason]);
    $id = $st->fetchColumn();
    if ($id === false) {
        throw new DomainException('That date is already blacked out.');
    }
    return (int) $id;
}

function remove_blackout(PDO $pdo, int $id, int $by): array
{
    $st = $pdo->prepare('DELETE FROM blackout_dates WHERE id = :id RETURNING scope_id, on_date::text AS on_date, reason');
    $st->execute(['id' => $id]);
    return $st->fetch() ?: throw new DomainException('That date is not blacked out.');
}
