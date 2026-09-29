<?php
declare(strict_types=1);

/**
 * Positions per restaurant (slice 4: docs/build-specs/people-positions.md) — the reads go through mcp_positions (its default rate is NULL without labor.view at the site), the writes
 * through the base table. A wage is never returned to a caller who does not hold labor.view at the position's restaurant: the SCREEN decides whether to print a rate at all (a NULL
 * from the view would otherwise tell "no rate set" to someone who may not know), and no function here logs or echoes one.
 */

const POSITION_AREAS = ['front' => 'Front of house', 'kitchen' => 'Kitchen', 'bar' => 'Bar', 'management' => 'Management', 'other' => 'Other'];

/** "{1,2,3}" from a bigint[] column → [1, 2, 3]. */
function pg_int_array(?string $text): array
{
    $text = trim((string) $text, '{}');
    return $text === '' ? [] : array_map('intval', explode(',', $text));
}

/** A restaurant's positions, live (or archived), with the people who work each, the certifications each needs and — when the view gives it — the default hourly rate. */
function find_positions(PDO $pdo, int $siteId, bool $archived = false): array
{
    $st = $pdo->prepare('SELECT p.position_id, p.site_id, p.name, p.color, p.area, p.sort_order, p.archived_at, p.default_wage_rate,
                                (SELECT count(*) FROM mcp_staff_positions sp WHERE sp.position_id = p.position_id) AS people
                           FROM mcp_positions p WHERE p.site_id = :s AND (p.archived_at IS NULL) = :live ORDER BY p.sort_order, lower(p.name), p.position_id');
    $st->bindValue('s', $siteId, PDO::PARAM_INT);
    $st->bindValue('live', $archived ? 'f' : 't');
    $st->execute();
    $rows = $st->fetchAll();
    $kinds = [];
    foreach (find_certification_kinds($pdo, $siteId) as $k) {
        foreach ($k['required_position_ids'] as $pid) {
            $kinds[$pid][] = ['kind_id' => $k['kind_id'], 'name' => $k['name']];
        }
    }
    foreach ($rows as &$r) {
        $r['position_id'] = (int) $r['position_id'];
        $r['site_id'] = (int) $r['site_id'];
        $r['people'] = (int) $r['people'];
        $r['default_wage_rate'] = $r['default_wage_rate'] === null ? null : (float) $r['default_wage_rate'];
        $r['certifications'] = $kinds[$r['position_id']] ?? [];
    }
    unset($r);
    return $rows;
}

/** One position as its restaurant's manager edits it: the base row (no rate) and the ids of the kinds it needs. Null when there is none. */
function find_position_for_edit(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT id AS position_id, scope_id AS site_id, name, color, area, sort_order, archived_at FROM positions WHERE id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $r['position_id'] = (int) $r['position_id'];
    $r['site_id'] = (int) $r['site_id'];
    $r['sort_order'] = (int) $r['sort_order'];
    $k = $pdo->prepare('SELECT kind_id FROM position_certifications WHERE position_id = :id ORDER BY kind_id');
    $k->execute(['id' => $id]);
    $r['kind_ids'] = array_map('intval', $k->fetchAll(PDO::FETCH_COLUMN));
    return $r;
}

/** The position's restaurant — the one base-table read a write uses to find its site. Null when there is none. */
function position_site_id(PDO $pdo, int $id): ?int
{
    $st = $pdo->prepare('SELECT scope_id FROM positions WHERE id = :id');
    $st->execute(['id' => $id]);
    $v = $st->fetchColumn();
    return $v === false ? null : (int) $v;
}

/** What a position's log row carries: never a rate. */
function position_state(PDO $pdo, int $id): array
{
    $p = find_position_for_edit($pdo, $id) ?? throw new DomainException('Not found.');
    return ['name' => $p['name'], 'area' => $p['area'], 'color' => $p['color'], 'sort_order' => $p['sort_order'], 'certification_kind_ids' => $p['kind_ids']];
}

/**
 * Add ($id null) or change a position. $f: name, area, color, sort_order, kind_ids (the restaurant's live kinds it needs, replaced whole). A live name is unique in the restaurant.
 * Answers ['id' => int, 'before' => ?array, 'after' => array] — states without a rate.
 */
function save_position(PDO $pdo, int $siteId, ?int $id, array $f, int $by): array
{
    $dupe = $pdo->prepare('SELECT 1 FROM positions WHERE scope_id = :s AND lower(name) = lower(:n) AND archived_at IS NULL AND id IS DISTINCT FROM :id');
    $dupe->execute(['s' => $siteId, 'n' => $f['name'], 'id' => $id]);
    if ($dupe->fetchColumn() !== false) {
        throw new DomainException($f['name'] . ' is already a position here.');
    }
    $kindIds = array_values(array_unique(array_map('intval', $f['kind_ids'])));
    if ($kindIds !== []) {
        $live = $pdo->prepare('SELECT id FROM certification_kinds WHERE scope_id = :s AND archived_at IS NULL AND id = ANY (CAST(:k AS bigint[]))');
        $live->execute(['s' => $siteId, 'k' => '{' . implode(',', $kindIds) . '}']);
        if (count($live->fetchAll()) !== count($kindIds)) {
            throw new DomainException('A position can only need this restaurant\'s own certifications.');
        }
    }
    $before = null;
    if ($id === null) {
        $st = $pdo->prepare('INSERT INTO positions (scope_id, name, color, area, sort_order) VALUES (:s, :n, :c, :a, :o) RETURNING id');
        $st->execute(['s' => $siteId, 'n' => $f['name'], 'c' => $f['color'], 'a' => $f['area'], 'o' => $f['sort_order']]);
        $id = (int) $st->fetchColumn();
    } else {
        $before = position_state($pdo, $id);
        $pdo->prepare('UPDATE positions SET name = :n, color = :c, area = :a, sort_order = :o WHERE id = :id')
            ->execute(['n' => $f['name'], 'c' => $f['color'], 'a' => $f['area'], 'o' => $f['sort_order'], 'id' => $id]);
    }
    $pdo->prepare('DELETE FROM position_certifications WHERE position_id = :id')->execute(['id' => $id]);
    $ins = $pdo->prepare('INSERT INTO position_certifications (position_id, kind_id) VALUES (:p, :k)');
    foreach ($kindIds as $k) {
        $ins->execute(['p' => $id, 'k' => $k]);
    }
    return ['id' => $id, 'before' => $before, 'after' => position_state($pdo, $id)];
}

/** The default hourly rate of a position for everyone at it without a rate of their own; null clears it. The caller holds pay.edit at the position's restaurant. The rate is never returned. */
function set_position_rate(PDO $pdo, int $positionId, ?float $rate, int $by): void
{
    $st = $pdo->prepare('UPDATE positions SET default_wage_rate = :r WHERE id = :id');
    $st->bindValue('r', $rate === null ? null : number_format($rate, 2, '.', ''));
    $st->bindValue('id', $positionId, PDO::PARAM_INT);
    $st->execute();
    if ($st->rowCount() !== 1) {
        throw new DomainException('Not found.');
    }
}

/** How many people a position's default rate reaches: [without => no rate of their own, own => have one]. Counts only — read behind labor.view. */
function people_at_position(PDO $pdo, int $positionId): array
{
    $st = $pdo->prepare('SELECT count(*) FILTER (WHERE sp.wage_override IS NULL) AS without_own, count(*) FILTER (WHERE sp.wage_override IS NOT NULL) AS own
                           FROM staff_positions sp LEFT JOIN staff_profiles pr ON pr.member_id = sp.member_id
                          WHERE sp.position_id = :p AND COALESCE(pr.active, true)');
    $st->execute(['p' => $positionId]);
    $r = $st->fetch();
    return ['without' => (int) $r['without_own'], 'own' => (int) $r['own']];
}

/** The scheduled shifts still to come that use a position (draft weeks included) — an archived position may have none. */
function position_upcoming_shifts(PDO $pdo, int $positionId): int
{
    $st = $pdo->prepare("SELECT count(*) FROM shifts WHERE position_id = :p AND status = 'scheduled' AND ends_at > now()");
    $st->execute(['p' => $positionId]);
    return (int) $st->fetchColumn();
}

/** Archive a position: it stays on old shifts and leaves the pickers; refused while an upcoming scheduled shift uses it. Answers its name. */
function archive_position(PDO $pdo, int $id, int $by): string
{
    $p = find_position_for_edit($pdo, $id) ?? throw new DomainException('Not found.');
    if ($p['archived_at'] !== null) {
        throw new DomainException($p['name'] . ' is already archived.');
    }
    $n = position_upcoming_shifts($pdo, $id);
    if ($n > 0) {
        throw new DomainException($p['name'] . ' is on ' . $n . ' upcoming shift' . ($n === 1 ? '' : 's') . '. Move or cancel ' . ($n === 1 ? 'it' : 'them') . ' first.');
    }
    $pdo->prepare('UPDATE positions SET archived_at = now() WHERE id = :id')->execute(['id' => $id]);
    return $p['name'];
}
