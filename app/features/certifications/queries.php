<?php
declare(strict_types=1);

/**
 * Certifications (slice 4, the owner's D15): each restaurant's own KINDS, a person's CARDS with an expiry, a manager who VERIFIES, and the DUE LIST a manager works from.
 * Reads go through the mcp_* views (a person's own cards, a manager's at their restaurant); writes are base-table statements the handler gates at the kind's restaurant.
 * An unverified card counts as held for the rule (db/009); a card a manager enters is verified at once; a person editing a verified card clears it.
 */

const CERT_STATES = ['expired' => 'Expired', 'missing' => 'Missing', 'due' => 'Due soon', 'to_verify' => 'To verify'];

function find_certification_kinds(PDO $pdo, int $siteId, bool $archived = false): array
{
    $st = $pdo->prepare('SELECT kind_id, site_id, key, name, track_expiry, warn_days, archived_at, required_position_ids FROM mcp_certification_kinds
                          WHERE site_id = :s AND (archived_at IS NULL) = :live ORDER BY lower(name), kind_id');
    $st->bindValue('s', $siteId, PDO::PARAM_INT);
    $st->bindValue('live', $archived ? 'f' : 't');
    $st->execute();
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['kind_id'] = (int) $r['kind_id'];
        $r['site_id'] = (int) $r['site_id'];
        $r['warn_days'] = (int) $r['warn_days'];
        $r['track_expiry'] = (bool) $r['track_expiry'];
        $r['required_position_ids'] = pg_int_array($r['required_position_ids']);
    }
    unset($r);
    return $rows;
}

function certification_kind_site_id(PDO $pdo, int $id): ?int
{
    $st = $pdo->prepare('SELECT scope_id FROM certification_kinds WHERE id = :id');
    $st->execute(['id' => $id]);
    $v = $st->fetchColumn();
    return $v === false ? null : (int) $v;
}

/** One kind for its edit form (base table): the fields and the position ids that need it. */
function find_certification_kind(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT id AS kind_id, scope_id AS site_id, key, name, track_expiry, warn_days, archived_at FROM certification_kinds WHERE id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $r['kind_id'] = (int) $r['kind_id'];
    $r['site_id'] = (int) $r['site_id'];
    $r['warn_days'] = (int) $r['warn_days'];
    $r['track_expiry'] = (bool) $r['track_expiry'];
    $p = $pdo->prepare('SELECT position_id FROM position_certifications WHERE kind_id = :id ORDER BY position_id');
    $p->execute(['id' => $id]);
    $r['position_ids'] = array_map('intval', $p->fetchAll(PDO::FETCH_COLUMN));
    return $r;
}

function certification_kind_state(array $k): array
{
    return ['name' => $k['name'], 'track_expiry' => $k['track_expiry'], 'warn_days' => $k['warn_days'], 'position_ids' => $k['position_ids']];
}

/** "Food handler" → "food_handler"; unique among the restaurant's keys ("food_handler_2" …). */
function certification_kind_key(PDO $pdo, int $siteId, string $name): string
{
    $base = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($name)), '_');
    $base = substr(preg_match('/^[a-z]/', $base) ? $base : 'kind_' . $base, 0, 34);
    $key = $base;
    $n = 1;
    $st = $pdo->prepare('SELECT 1 FROM certification_kinds WHERE scope_id = :s AND key = :k');
    while (true) {
        $st->execute(['s' => $siteId, 'k' => $key]);
        if ($st->fetchColumn() === false) {
            return $key;
        }
        $key = $base . '_' . (++$n);
    }
}

/**
 * Add ($id null) or change a kind. $f: name, track_expiry, warn_days, position_ids (the restaurant's live positions that need it, replaced whole). A live name is unique in the restaurant; a position
 * of another restaurant is refused (the database refuses it too). Answers ['id', 'before' => ?array, 'after' => array].
 */
function save_certification_kind(PDO $pdo, int $siteId, ?int $id, array $f, int $by): array
{
    $dupe = $pdo->prepare('SELECT 1 FROM certification_kinds WHERE scope_id = :s AND lower(name) = lower(:n) AND archived_at IS NULL AND id IS DISTINCT FROM :id');
    $dupe->execute(['s' => $siteId, 'n' => $f['name'], 'id' => $id]);
    if ($dupe->fetchColumn() !== false) {
        throw new DomainException($f['name'] . ' is already a certification here.');
    }
    $ids = array_values(array_unique(array_map('intval', $f['position_ids'])));
    if ($ids !== []) {
        $live = $pdo->prepare('SELECT id FROM positions WHERE scope_id = :s AND archived_at IS NULL AND id = ANY (CAST(:p AS bigint[]))');
        $live->execute(['s' => $siteId, 'p' => '{' . implode(',', $ids) . '}']);
        if (count($live->fetchAll()) !== count($ids)) {
            throw new DomainException('Only this restaurant\'s own positions can need a certification.');
        }
    }
    $before = null;
    if ($id === null) {
        $st = $pdo->prepare('INSERT INTO certification_kinds (scope_id, key, name, track_expiry, warn_days) VALUES (:s, :k, :n, :t, :w) RETURNING id');
        $st->bindValue('s', $siteId, PDO::PARAM_INT);
        $st->bindValue('k', certification_kind_key($pdo, $siteId, $f['name']));
        $st->bindValue('n', $f['name']);
        $st->bindValue('t', $f['track_expiry'] ? 't' : 'f');
        $st->bindValue('w', $f['warn_days'], PDO::PARAM_INT);
        $st->execute();
        $id = (int) $st->fetchColumn();
    } else {
        $before = certification_kind_state(find_certification_kind($pdo, $id) ?? throw new DomainException('Not found.'));
        $st = $pdo->prepare('UPDATE certification_kinds SET name = :n, track_expiry = :t, warn_days = :w WHERE id = :id');
        $st->bindValue('n', $f['name']);
        $st->bindValue('t', $f['track_expiry'] ? 't' : 'f');
        $st->bindValue('w', $f['warn_days'], PDO::PARAM_INT);
        $st->bindValue('id', $id, PDO::PARAM_INT);
        $st->execute();
    }
    $pdo->prepare('DELETE FROM position_certifications WHERE kind_id = :id')->execute(['id' => $id]);
    $ins = $pdo->prepare('INSERT INTO position_certifications (position_id, kind_id) VALUES (:p, :k)');
    foreach ($ids as $p) {
        $ins->execute(['p' => $p, 'k' => $id]);
    }
    return ['id' => $id, 'before' => $before, 'after' => certification_kind_state(find_certification_kind($pdo, $id))];
}

/** Archive a kind: it leaves the pickers and the rule (db/009 ignores archived kinds); cards already entered stay. Answers its name. */
function archive_certification_kind(PDO $pdo, int $id, int $by): string
{
    $k = find_certification_kind($pdo, $id) ?? throw new DomainException('Not found.');
    if ($k['archived_at'] !== null) {
        throw new DomainException($k['name'] . ' is already archived.');
    }
    $pdo->prepare('UPDATE certification_kinds SET archived_at = now() WHERE id = :id')->execute(['id' => $id]);
    return $k['name'];
}

/**
 * The SQL of the due list as a manager works from it: mcp_certifications_due without the rows an OLDER card of the same kind leaves behind — a person who renewed is not "expired" because
 * last year's card still exists. A card is replaced when the person holds another live card of that kind that is current, or that runs later.
 */
const CERT_DUE_CURRENT = "(SELECT d.* FROM mcp_certifications_due d
     WHERE d.certification_id IS NULL OR NOT EXISTS (SELECT 1 FROM mcp_certifications c WHERE c.member_id = d.member_id AND c.kind_id = d.kind_id AND c.certification_id <> d.certification_id
        AND ((d.state IN ('expired', 'due') AND (NOT (c.expired OR c.due_soon) OR c.expires_on > d.expires_on OR (c.expires_on = d.expires_on AND c.certification_id > d.certification_id)))
             OR (d.state = 'to_verify' AND c.verified AND c.certification_id > d.certification_id))))";

/** The manager's list for a restaurant: [certification_id?, member_id, display_name, kind_id, kind_name, expires_on, state, days_left], most urgent first. $state: expired, due, missing, to_verify. */
function find_certifications_due(PDO $pdo, int $siteId, ?string $state = null, ?int $positionId = null): array
{
    $where = ['d.site_id = :s'];
    $args = ['s' => $siteId];
    if ($state !== null && isset(CERT_STATES[$state])) {
        $where[] = 'd.state = :st';
        $args['st'] = $state;
    }
    if ($positionId !== null) {
        $where[] = 'EXISTS (SELECT 1 FROM mcp_staff_positions sp WHERE sp.member_id = d.member_id AND sp.position_id = :p)';
        $args['p'] = $positionId;
    }
    $st = $pdo->prepare('SELECT d.certification_id, d.member_id, d.display_name, d.kind_id, d.kind_name, d.expires_on::text AS expires_on, d.state, d.days_left FROM ' . CERT_DUE_CURRENT . ' d
                          WHERE ' . implode(' AND ', $where) . "
                          ORDER BY CASE d.state WHEN 'expired' THEN 0 WHEN 'missing' THEN 1 WHEN 'due' THEN 2 ELSE 3 END, d.days_left NULLS LAST, lower(d.display_name), d.kind_name");
    $st->execute($args);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['certification_id'] = $r['certification_id'] === null ? null : (int) $r['certification_id'];
        $r['member_id'] = (int) $r['member_id'];
        $r['kind_id'] = (int) $r['kind_id'];
        $r['days_left'] = $r['days_left'] === null ? null : (int) $r['days_left'];
    }
    unset($r);
    return $rows;
}

/** How many people at a restaurant have an expired certification (the staff list's danger chip): member id → count, from the same list. */
function expired_counts(PDO $pdo, int $siteId): array
{
    $st = $pdo->prepare("SELECT d.member_id, count(*) AS n FROM " . CERT_DUE_CURRENT . " d WHERE d.site_id = :s AND d.state = 'expired' GROUP BY d.member_id");
    $st->execute(['s' => $siteId]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[(int) $r['member_id']] = (int) $r['n'];
    }
    return $out;
}

/** A person's live cards the caller may see (their own; a manager's restaurant's), newest expiry first within a kind. `replaced` = an older card another card of the kind supersedes. */
function find_certifications(PDO $pdo, int $memberId, ?array $siteIds = null): array
{
    $st = $pdo->prepare('SELECT c.certification_id, c.member_id, c.site_id, st.name AS site_name, c.kind_id, c.kind_name, c.issued_on::text AS issued_on, c.expires_on::text AS expires_on, c.reference,
                                c.expired, c.due_soon, c.verified, vm.display_name AS verified_by_name, c.verified_at, k.track_expiry, k.warn_days,
                                (c.expires_on - current_date) AS days_left
                           FROM mcp_certifications c
                           JOIN mcp_sites st ON st.site_id = c.site_id
                           JOIN mcp_certification_kinds k ON k.kind_id = c.kind_id
                           LEFT JOIN members vm ON vm.id = c.verified_by
                          WHERE c.member_id = :m' . ($siteIds === null ? '' : ' AND c.site_id = ANY (CAST(:sites AS bigint[]))') . '
                          ORDER BY st.name, lower(c.kind_name), c.expires_on DESC NULLS FIRST, c.certification_id DESC');
    $args = ['m' => $memberId];
    if ($siteIds !== null) {
        $args['sites'] = '{' . implode(',', array_map('intval', $siteIds)) . '}';
    }
    $st->execute($args);
    $rows = $st->fetchAll();
    $seen = [];
    foreach ($rows as &$r) {
        foreach (['certification_id', 'member_id', 'site_id', 'kind_id'] as $k) {
            $r[$k] = (int) $r[$k];
        }
        foreach (['expired', 'due_soon', 'verified', 'track_expiry'] as $k) {
            $r[$k] = (bool) $r[$k];
        }
        $r['days_left'] = $r['days_left'] === null ? null : (int) $r['days_left'];
        $r['warn_days'] = (int) $r['warn_days'];
        // rows are ordered newest first within a kind: a later row of the same kind that is not current is an old card
        $key = $r['kind_id'];
        $r['replaced'] = isset($seen[$key]) && ($r['expired'] || $r['due_soon']) && !$seen[$key];
        $seen[$key] = $seen[$key] ?? ($r['expired'] || $r['due_soon']);
    }
    unset($r);
    return $rows;
}

/** The card as the database has it, for a write (base tables): null when there is none or it was removed. Locked on request. */
function certification_snapshot(PDO $pdo, int $id, bool $lock = false): ?array
{
    $st = $pdo->prepare('SELECT c.id AS certification_id, c.member_id, m.display_name AS member_name, c.kind_id, k.scope_id AS site_id, k.name AS kind_name, k.track_expiry, k.archived_at AS kind_archived_at,
                                c.issued_on::text AS issued_on, c.expires_on::text AS expires_on, c.reference, c.verified_by, c.verified_at
                           FROM certifications c JOIN certification_kinds k ON k.id = c.kind_id JOIN members m ON m.id = c.member_id
                          WHERE c.id = :id AND c.removed_at IS NULL' . ($lock ? ' FOR UPDATE OF c' : ''));
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    foreach (['certification_id', 'member_id', 'kind_id', 'site_id'] as $k) {
        $r[$k] = (int) $r[$k];
    }
    $r['track_expiry'] = (bool) $r['track_expiry'];
    $r['verified'] = $r['verified_at'] !== null;
    return $r;
}

/** What a card's log rows carry: ids and dates — never a document, never a wage. */
function certification_log_state(array $c): array
{
    return ['member_id' => $c['member_id'], 'kind_id' => $c['kind_id'], 'issued_on' => $c['issued_on'], 'expires_on' => $c['expires_on'], 'reference' => $c['reference'], 'verified' => $c['verified']];
}

function valid_date_text(?string $d): bool
{
    if ($d === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
        return false;
    }
    $t = DateTimeImmutable::createFromFormat('!Y-m-d', $d);
    return $t !== false && $t->format('Y-m-d') === $d;
}

/** The dates of a card must be dates, and it cannot expire before it was issued. */
function check_certification_dates(?string $issued, ?string $expires): void
{
    foreach (['The issue date' => $issued, 'The expiry date' => $expires] as $label => $d) {
        if ($d !== null && !valid_date_text($d)) {
            throw new DomainException($label . ' is a date like 2026-10-09.');
        }
    }
    if ($issued !== null && $expires !== null && $expires < $issued) {
        throw new DomainException('The card cannot expire before it was issued.');
    }
}

/** A kind named by id or by name → [kind_id, name] of a live one at a restaurant the caller holds; null when there is none. */
function resolve_certification_kind(PDO $pdo, string|int $kind): ?array
{
    if (is_int($kind) || (is_string($kind) && ctype_digit($kind))) {
        $st = $pdo->prepare('SELECT kind_id, name FROM mcp_certification_kinds WHERE kind_id = :k AND archived_at IS NULL');
        $st->execute(['k' => (int) $kind]);
    } else {
        $st = $pdo->prepare('SELECT kind_id, name FROM mcp_certification_kinds WHERE lower(name) = lower(:n) AND archived_at IS NULL ORDER BY kind_id LIMIT 1');
        $st->execute(['n' => trim((string) $kind)]);
    }
    $r = $st->fetch();
    return $r === false ? null : ['kind_id' => (int) $r['kind_id'], 'name' => $r['name']];
}

/**
 * Enter a card: ONE row for each restaurant the person works at, that the caller holds and may act at (their own card, or schedule.build there), where a live kind of that name exists — so a food
 * handler card is not typed twice. Verified at once when the enterer holds schedule.build at that restaurant (D15). Answers a list of ['id', 'site_id', 'kind_id', 'verified'].
 */
function add_certification(PDO $pdo, int $memberId, string|int $kind, ?string $issued, ?string $expires, ?string $reference, int $by): array
{
    $k = resolve_certification_kind($pdo, $kind) ?? throw new DomainException('Choose one of this restaurant\'s certifications.');
    check_certification_dates($issued, $expires);
    $st = $pdo->prepare('SELECT k.id AS kind_id, k.scope_id, k.track_expiry, ts_has_right(\'schedule.build\', k.scope_id) AS builds
                           FROM certification_kinds k JOIN member_site_roles r ON r.scope_id = k.scope_id AND r.member_id = :m
                          WHERE lower(k.name) = lower(:n) AND k.archived_at IS NULL AND k.scope_id IN (SELECT ts_held_scope_ids())
                          ORDER BY k.scope_id');
    $st->execute(['m' => $memberId, 'n' => $k['name']]);
    $targets = array_values(array_filter($st->fetchAll(), static fn (array $t): bool => $memberId === $by || $t['builds']));
    if ($targets === []) {
        throw new DomainException($memberId === $by ? 'That certification is not one of your restaurants\'.' : 'They do not work at a restaurant that has that certification.');
    }
    foreach ($targets as $t) {
        if ($t['track_expiry'] && $expires === null) {
            throw new DomainException($k['name'] . ' expires: give the expiry date.');
        }
    }
    $ins = $pdo->prepare('INSERT INTO certifications (member_id, kind_id, issued_on, expires_on, reference, recorded_by, verified_by, verified_at)
                          VALUES (:m, :k, CAST(:i AS date), CAST(:e AS date), :r, :by, :vb, CASE WHEN CAST(:vb2 AS bigint) IS NULL THEN NULL ELSE now() END) RETURNING id');
    $out = [];
    foreach ($targets as $t) {
        $vb = $t['builds'] ? $by : null;
        $ins->execute(['m' => $memberId, 'k' => (int) $t['kind_id'], 'i' => $issued, 'e' => $expires, 'r' => $reference, 'by' => $by, 'vb' => $vb, 'vb2' => $vb]);
        $out[] = ['id' => (int) $ins->fetchColumn(), 'site_id' => (int) $t['scope_id'], 'kind_id' => (int) $t['kind_id'], 'verified' => $vb !== null];
    }
    return $out;
}

/**
 * Change a card's issue date, expiry or reference ($f holds only the keys that change). A person editing clears a verification; a manager (schedule.build at its restaurant) editing verifies
 * it. Answers ['before' => state, 'after' => state, 'card' => snapshot after].
 */
function update_certification(PDO $pdo, int $id, array $f, int $by): array
{
    $c = certification_snapshot($pdo, $id, true) ?? throw new DomainException('Not found.');
    $issued = array_key_exists('issued_on', $f) ? $f['issued_on'] : $c['issued_on'];
    $expires = array_key_exists('expires_on', $f) ? $f['expires_on'] : $c['expires_on'];
    $reference = array_key_exists('reference', $f) ? $f['reference'] : $c['reference'];
    check_certification_dates($issued, $expires);
    if ($c['track_expiry'] && $expires === null) {
        throw new DomainException($c['kind_name'] . ' expires: give the expiry date.');
    }
    $builds = db_bool($pdo, "SELECT ts_has_right('schedule.build', :s)", ['s' => $c['site_id']]);
    $st = $pdo->prepare('UPDATE certifications SET issued_on = CAST(:i AS date), expires_on = CAST(:e AS date), reference = :r,
                                verified_by = CAST(:vb AS bigint), verified_at = CASE WHEN CAST(:vb2 AS bigint) IS NULL THEN NULL ELSE now() END WHERE id = :id');
    $vb = $builds ? $by : null;
    $st->execute(['i' => $issued, 'e' => $expires, 'r' => $reference, 'vb' => $vb, 'vb2' => $vb, 'id' => $id]);
    $after = certification_snapshot($pdo, $id);
    return ['before' => certification_log_state($c), 'after' => certification_log_state($after), 'card' => $after];
}

/** Take a card off the person's list (kept, marked removed). Answers its snapshot before. */
function remove_certification(PDO $pdo, int $id, int $by): array
{
    $c = certification_snapshot($pdo, $id, true) ?? throw new DomainException('Not found.');
    $pdo->prepare('UPDATE certifications SET removed_at = now() WHERE id = :id')->execute(['id' => $id]);
    return $c;
}

/** A manager marks a card verified (or not). Answers the snapshot after. */
function verify_certification(PDO $pdo, int $id, bool $verified, int $by): array
{
    certification_snapshot($pdo, $id, true) ?? throw new DomainException('Not found.');
    $st = $pdo->prepare('UPDATE certifications SET verified_by = CAST(:vb AS bigint), verified_at = CASE WHEN CAST(:vb2 AS bigint) IS NULL THEN NULL ELSE now() END WHERE id = :id');
    $vb = $verified ? $by : null;
    $st->execute(['vb' => $vb, 'vb2' => $vb, 'id' => $id]);
    return certification_snapshot($pdo, $id);
}
