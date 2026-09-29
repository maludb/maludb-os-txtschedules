<?php
declare(strict_types=1);

/**
 * Availability (slice 3: docs/build-specs/availability-time-off.md). A person's recurring weekly blocks — available, unavailable, preferred — that take effect from a date,
 * approved at once or waiting for a manager as the restaurant's `availability_needs_approval` says. Read through `mcp_availability`; written to the base table (the views
 * hide what is not pending or approved). A function refuses with a DomainException (the handler's guard makes it a 422); none opens its own transaction and none logs.
 * A block with no restaurant (scope_id NULL) is for every restaurant the person works at: it is approved by, and removed by, someone who holds the right at ANY of them.
 */

const AVAIL_KINDS = ['available' => 'Available', 'unavailable' => 'Unavailable', 'preferred' => 'Preferred'];
const WEEKDAY_NAMES = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

function decode_availability_rows(array $rows): array
{
    foreach ($rows as &$r) {
        foreach (['availability_id', 'member_id', 'weekday'] as $k) {
            $r[$k] = (int) $r[$k];
        }
        $r['site_id'] = $r['site_id'] === null ? null : (int) $r['site_id'];
        $r['starts_at'] = substr((string) $r['starts_at'], 0, 5);
        $r['ends_at'] = substr((string) $r['ends_at'], 0, 5);
    }
    unset($r);
    return $rows;
}

const AVAILABILITY_SELECT = 'SELECT a.availability_id, a.member_id, m.display_name AS member_name, a.site_id, st.name AS site_name, st.timezone, a.weekday, a.starts_at, a.ends_at, a.kind,
        a.effective_from::text AS effective_from, a.effective_to::text AS effective_to, a.status, a.decided_by, a.decided_at
   FROM mcp_availability a
   JOIN members m ON m.id = a.member_id
   LEFT JOIN mcp_sites st ON st.site_id = a.site_id';

/** A person's blocks the caller may see (the view's rule: their own; others\' at restaurants where the caller builds). $siteId keeps that restaurant's and the every-restaurant ones. */
function find_availability(PDO $pdo, int $memberId, ?int $siteId = null, ?string $status = null): array
{
    $sql = AVAILABILITY_SELECT . ' WHERE a.member_id = :m';
    $args = ['m' => $memberId];
    if ($siteId !== null) {
        $sql .= ' AND (a.site_id = :s OR a.site_id IS NULL)';
        $args['s'] = $siteId;
    }
    if ($status !== null) {
        $sql .= ' AND a.status = :st';
        $args['st'] = $status;
    }
    $st = $pdo->prepare($sql . ' ORDER BY a.weekday, a.starts_at, a.availability_id');
    $st->execute($args);
    return decode_availability_rows($st->fetchAll());
}

function find_availability_row(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare(AVAILABILITY_SELECT . ' WHERE a.availability_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : decode_availability_rows([$r])[0];
}

/** A person's own blocks that are gone — declined or replaced — for My requests (their own rows: no view is needed to say so). */
function find_my_availability_history(PDO $pdo, int $memberId, int $limit = 30): array
{
    $st = $pdo->prepare("SELECT a.id AS availability_id, a.member_id, m.display_name AS member_name, a.scope_id AS site_id, st.name AS site_name, st.timezone, a.weekday, a.starts_at, a.ends_at, a.kind,
            a.effective_from::text AS effective_from, a.effective_to::text AS effective_to, a.status, a.decided_by, a.decided_at, a.decision_note
       FROM availability_rules a JOIN members m ON m.id = a.member_id LEFT JOIN sites st ON st.scope_id = a.scope_id
      WHERE a.member_id = :m AND a.status IN ('declined', 'replaced') ORDER BY COALESCE(a.decided_at, a.created_at) DESC, a.id DESC LIMIT " . max(1, min(100, $limit)));
    $st->execute(['m' => $memberId]);
    return decode_availability_rows($st->fetchAll());
}

/** The restaurants a block applies to: its own, or every one the person works at. The base table — a write derives its sites from the record. */
function availability_row_sites(PDO $pdo, int $memberId, ?int $scopeId): array
{
    if ($scopeId !== null) {
        return [$scopeId];
    }
    return member_site_ids($pdo, $memberId);
}

/** The restaurants a person works at (member_site_roles). */
function member_site_ids(PDO $pdo, int $memberId): array
{
    $st = $pdo->prepare('SELECT scope_id FROM member_site_roles WHERE member_id = :m ORDER BY scope_id');
    $st->execute(['m' => $memberId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** The base row a write starts from, locked: [id, member_id, scope_id, status, weekday, starts_at, ends_at, kind, effective_from, effective_to]. Null when none. */
function availability_write_row(PDO $pdo, int $id, bool $lock = false): ?array
{
    $st = $pdo->prepare('SELECT id, member_id, scope_id, weekday, starts_at::text AS starts_at, ends_at::text AS ends_at, kind, effective_from::text AS effective_from, effective_to::text AS effective_to, status
                           FROM availability_rules WHERE id = :id' . ($lock ? ' FOR UPDATE' : ''));
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    foreach (['id', 'member_id', 'weekday'] as $k) {
        $r[$k] = (int) $r[$k];
    }
    $r['scope_id'] = $r['scope_id'] === null ? null : (int) $r['scope_id'];
    $r['starts_at'] = substr($r['starts_at'], 0, 5);
    $r['ends_at'] = substr($r['ends_at'], 0, 5);
    return $r;
}

/** Do any of these restaurants ask for a manager's approval of a change? */
function availability_needs_approval(PDO $pdo, array $siteIds): bool
{
    if ($siteIds === []) {
        return true;
    }
    $st = $pdo->prepare('SELECT bool_or(availability_needs_approval) FROM site_settings WHERE scope_id = ANY (CAST(:s AS bigint[]))');
    $st->execute(['s' => '{' . implode(',', array_map('intval', $siteIds)) . '}']);
    return (bool) $st->fetchColumn();
}

/** "17:00" → minutes since midnight; null when it is not a time. */
function hhmm_minutes(string $t): ?int
{
    return preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $t, $m) ? (int) $m[1] * 60 + (int) $m[2] : null;
}

/**
 * Submit a block. $f = member_id, site_id (?int: null = every restaurant), weekday, starts_at, ends_at ("HH:MM"; both 00:00 = the whole day), kind, effective_from, effective_to (?), approve_now (bool: a manager
 * who may approve entered it). Pending unless the restaurant(s) do not need approval or approve_now; an approved one replaces what it overlaps at once. Answers [id, status, replaced].
 */
function submit_availability(PDO $pdo, array $f, int $by): array
{
    if (!isset(AVAIL_KINDS[$f['kind']])) {
        throw new DomainException('Choose available, unavailable or preferred.');
    }
    if ($f['weekday'] < 0 || $f['weekday'] > 6) {
        throw new DomainException('Choose a day of the week.');
    }
    $a = hhmm_minutes((string) $f['starts_at']);
    $b = hhmm_minutes((string) $f['ends_at']);
    if ($a === null || $b === null) {
        throw new DomainException('Give the times as hours and minutes, like 17:00.');
    }
    if ($a === $b && $a !== 0) {
        throw new DomainException('A block needs a start and an end that differ (00:00 to 00:00 is the whole day).');
    }
    if ($f['effective_to'] !== null && $f['effective_to'] < $f['effective_from']) {
        throw new DomainException('A block cannot end before it takes effect.');
    }
    $sites = availability_row_sites($pdo, (int) $f['member_id'], $f['site_id']);
    if ($sites === [] || ($f['site_id'] !== null && !in_array($f['site_id'], member_site_ids($pdo, (int) $f['member_id']), true))) {
        throw new DomainException('That person does not work at this restaurant.');
    }
    $now = !empty($f['approve_now']) || !availability_needs_approval($pdo, $sites);
    $ins = $pdo->prepare('INSERT INTO availability_rules (member_id, scope_id, weekday, starts_at, ends_at, kind, effective_from, effective_to, status, decided_by, decided_at)
                          VALUES (:m, :s, :wd, :a, :b, :k, :from, :to, :st, :by, CASE WHEN :st2 = \'approved\' THEN now() END) RETURNING id');
    $ins->execute(['m' => $f['member_id'], 's' => $f['site_id'], 'wd' => $f['weekday'], 'a' => $f['starts_at'], 'b' => $f['ends_at'], 'k' => $f['kind'], 'from' => $f['effective_from'], 'to' => $f['effective_to'],
                   'st' => $now ? 'approved' : 'pending', 'st2' => $now ? 'approved' : 'pending', 'by' => $now ? $by : null]);
    $id = (int) $ins->fetchColumn();
    $replaced = $now ? replace_availability($pdo, availability_write_row($pdo, $id) ?? throw new DomainException('Not found.')) : 0;
    return ['id' => $id, 'status' => $now ? 'approved' : 'pending', 'replaced' => $replaced];
}

/**
 * An approved block replaces the older approved blocks of the same person, weekday and restaurant whose hours and dates it overlaps: they become `replaced`. A block that takes effect
 * LATER than today does not wipe the present: an older one it overlaps simply ends the day before it starts. Answers how many were replaced or ended.
 */
function replace_availability(PDO $pdo, array $new): int
{
    $a = (int) hhmm_minutes($new['starts_at']);
    $b = (int) hhmm_minutes($new['ends_at']);
    if ($b <= $a) {
        $b += 1440;
    }
    $where = "o.member_id = :m AND o.id <> :id AND o.status = 'approved' AND o.weekday = :wd AND o.scope_id IS NOT DISTINCT FROM :s
              AND int4range((EXTRACT(EPOCH FROM o.starts_at) / 60)::int, (EXTRACT(EPOCH FROM o.ends_at) / 60)::int + CASE WHEN o.ends_at <= o.starts_at THEN 1440 ELSE 0 END) && int4range(:a, :b)
              AND o.effective_from <= COALESCE(CAST(:to1 AS date), 'infinity'::date) AND COALESCE(o.effective_to, 'infinity'::date) >= CAST(:from1 AS date)";
    $args = ['m' => $new['member_id'], 'id' => $new['id'], 'wd' => $new['weekday'], 's' => $new['scope_id'], 'a' => $a, 'b' => $b, 'to1' => $new['effective_to'], 'from1' => $new['effective_from']];
    $st = $pdo->prepare("UPDATE availability_rules o SET status = 'replaced' WHERE $where AND CAST(:from2 AS date) <= GREATEST(o.effective_from, current_date)");
    $st->execute($args + ['from2' => $new['effective_from']]);
    $n = $st->rowCount();
    $st = $pdo->prepare("UPDATE availability_rules o SET effective_to = CAST(:from3 AS date) - 1 WHERE $where AND CAST(:from2 AS date) > GREATEST(o.effective_from, current_date)");
    $st->execute($args + ['from2' => $new['effective_from'], 'from3' => $new['effective_from']]);
    return $n + $st->rowCount();
}

/** Remove a block (own or a builder's call — the handler checks): it is no longer in effect (`replaced`, kept in the record). Answers the block as it was. */
function remove_availability(PDO $pdo, int $id, int $by): array
{
    $r = availability_write_row($pdo, $id, true) ?? throw new DomainException('Not found.');
    if (!in_array($r['status'], ['pending', 'approved'], true)) {
        throw new DomainException('That block is already gone.');
    }
    $pdo->prepare("UPDATE availability_rules SET status = 'replaced', decided_by = :by, decided_at = now(), decision_note = 'Removed' WHERE id = :id")->execute(['by' => $by, 'id' => $id]);
    return $r;
}

/** Approve or decline a pending block. Answers ['status', 'replaced', 'row' (before)]. */
function decide_availability(PDO $pdo, int $id, bool $approve, int $by, ?string $note): array
{
    $r = availability_write_row($pdo, $id, true) ?? throw new DomainException('Not found.');
    if ($r['status'] !== 'pending') {
        throw new DomainException('This change was already ' . ($r['status'] === 'replaced' ? 'withdrawn' : $r['status']) . '.');
    }
    $pdo->prepare('UPDATE availability_rules SET status = :st, decided_by = :by, decided_at = now(), decision_note = :n WHERE id = :id')
        ->execute(['st' => $approve ? 'approved' : 'declined', 'by' => $by, 'n' => $note, 'id' => $id]);
    $replaced = $approve ? replace_availability($pdo, ['status' => 'approved'] + $r) : 0;
    return ['status' => $approve ? 'approved' : 'declined', 'replaced' => $replaced, 'row' => $r];
}

/** Pending blocks waiting for the caller: at restaurants where they hold requests.approve (a block for every restaurant when the person works at one of them), never their own. */
function find_availability_approvals(PDO $pdo, array $siteIds, int $memberId): array
{
    if ($siteIds === []) {
        return [];
    }
    $st = $pdo->prepare(AVAILABILITY_SELECT . " WHERE a.status = 'pending' AND a.member_id <> :me
            AND (a.site_id = ANY (CAST(:s1 AS bigint[])) OR (a.site_id IS NULL AND EXISTS (SELECT 1 FROM member_site_roles r WHERE r.member_id = a.member_id AND r.scope_id = ANY (CAST(:s2 AS bigint[])))))
          ORDER BY a.availability_id");
    $lit = '{' . implode(',', array_map('intval', $siteIds)) . '}';
    $st->execute(['me' => $memberId, 's1' => $lit, 's2' => $lit]);
    return decode_availability_rows($st->fetchAll());
}

/** May the caller act on this block with $right: at its restaurant, or — a block for every restaurant — at any restaurant of the person's that they hold. */
function availability_right_at(PDO $pdo, string $right, array $row): bool
{
    $held = array_column(held_sites(), 'scope_id');
    foreach (availability_row_sites($pdo, (int) $row['member_id'], $row['scope_id'] ?? null) as $s) {
        if (in_array($s, $held, true) && has_right($right, $s)) {
            return true;
        }
    }
    return false;
}

/** The site a block's log row carries: its own, else the current one if the person works there, else the first restaurant they work at. */
function availability_log_site(PDO $pdo, array $row): ?int
{
    if (($row['scope_id'] ?? null) !== null) {
        return (int) $row['scope_id'];
    }
    $sites = availability_row_sites($pdo, (int) $row['member_id'], null);
    $cur = current_site_id();
    return $cur !== null && in_array($cur, $sites, true) ? $cur : ($sites[0] ?? null);
}
