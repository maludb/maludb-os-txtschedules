<?php
declare(strict_types=1);

/**
 * People (slice 4: docs/build-specs/people-positions.md) — a restaurant's staff, one person's profile, and the writes a manager makes to a profile (the main restaurant, the hours limit, the minor
 * flag, the positions worked) and, for the holder of pay.edit alone, a person's OWN hourly rate. Reads go through the mcp_* views: mcp_staff_positions gives a rate only to its owner or to
 * labor.view at the position's restaurant, so a list query here never selects a wage column at all; the ONE query that does (find_staff_member) takes it as the view gives it and the presenter
 * prints it only to someone who may see it. Nothing here logs, returns or echoes a rate after a write. The person themself — name, email, phone — is the kernel's.
 */

function decode_staff_ids(array $r, array $keys): array
{
    foreach ($keys as $k) {
        $r[$k] = $r[$k] === null ? null : (int) $r[$k];
    }
    return $r;
}

/**
 * A restaurant's staff, humans who work there ($f: site_id, q, position_id, on_schedule = yes|no). Never a wage. Answers ['rows' => …, 'more' => bool, 'page' => int]; each row carries the
 * positions worked at THIS restaurant and how many expired certifications the person has here.
 */
function find_staff(PDO $pdo, array $f, int $page = 1, int $per = 24): array
{
    $site = (int) $f['site_id'];
    $where = ["m.member_kind = 'human'", ':s = ANY (m.site_ids)'];
    $args = ['s' => $site];
    if (!empty($f['q'])) {
        $where[] = 'm.display_name ILIKE :q';
        $args['q'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $f['q']) . '%';
    }
    if (!empty($f['position_id'])) {
        $where[] = 'EXISTS (SELECT 1 FROM mcp_staff_positions sp WHERE sp.member_id = m.member_id AND sp.position_id = :p)';
        $args['p'] = (int) $f['position_id'];
    }
    if (($f['on_schedule'] ?? '') === 'yes') {
        $where[] = 'COALESCE(m.on_schedule, true)';
    } elseif (($f['on_schedule'] ?? '') === 'no') {
        $where[] = 'NOT COALESCE(m.on_schedule, true)';
    }
    $page = max(1, $page);
    $st = $pdo->prepare('SELECT m.member_id, m.display_name, m.main_site_id, ms.name AS main_site_name, COALESCE(m.on_schedule, true) AS on_schedule, s.max_hours_week, s.is_minor, s.minor_until::text AS minor_until
                           FROM mcp_members m LEFT JOIN mcp_staff s ON s.member_id = m.member_id LEFT JOIN mcp_sites ms ON ms.site_id = m.main_site_id
                          WHERE ' . implode(' AND ', $where) . ' ORDER BY lower(m.display_name), m.member_id LIMIT ' . ($per + 1) . ' OFFSET ' . (($page - 1) * $per));
    $st->execute($args);
    $rows = $st->fetchAll();
    $more = count($rows) > $per;
    $rows = array_slice($rows, 0, $per);
    $ids = array_map(static fn (array $r): int => (int) $r['member_id'], $rows);
    $pos = [];
    if ($ids !== []) {
        $ps = $pdo->prepare('SELECT sp.member_id, sp.position_id, sp.position_name, sp.is_primary, p.color, p.area FROM mcp_staff_positions sp JOIN mcp_positions p ON p.position_id = sp.position_id
                              WHERE sp.site_id = :s AND sp.member_id = ANY (CAST(:m AS bigint[])) ORDER BY sp.is_primary DESC, lower(sp.position_name)');
        $ps->execute(['s' => $site, 'm' => '{' . implode(',', $ids) . '}']);
        foreach ($ps->fetchAll() as $r) {
            $pos[(int) $r['member_id']][] = ['position_id' => (int) $r['position_id'], 'name' => $r['position_name'], 'is_primary' => (bool) $r['is_primary'], 'color' => $r['color'], 'area' => $r['area']];
        }
    }
    $expired = $ids === [] ? [] : expired_counts($pdo, $site);
    foreach ($rows as &$r) {
        $r = decode_staff_ids($r, ['member_id', 'main_site_id']);
        $r['on_schedule'] = (bool) $r['on_schedule'];
        $r['is_minor'] = (bool) $r['is_minor'];
        $r['max_hours_week'] = $r['max_hours_week'] === null ? null : (float) $r['max_hours_week'];
        $r['positions'] = $pos[$r['member_id']] ?? [];
        $r['expired_certifications'] = $expired[$r['member_id']] ?? 0;
    }
    unset($r);
    return ['rows' => $rows, 'more' => $more, 'page' => $page];
}

/**
 * One person as the caller may see them: the profile (notes only where the view gives them), positions with a rate AS THE VIEW GIVES IT (the person's own, or labor.view at the position's restaurant —
 * otherwise NULL, which the presenter never prints), the cards, the restaurants held. Null when the caller sees nothing of them.
 */
function find_staff_member(PDO $pdo, int $memberId): ?array
{
    $st = $pdo->prepare("SELECT m.member_id, m.display_name, m.member_kind, m.main_site_id, COALESCE(m.on_schedule, true) AS on_schedule, s.max_hours_week, s.is_minor, s.minor_until::text AS minor_until, s.notes,
                                (s.member_id IS NOT NULL) AS has_profile, ms.name AS main_site_name, ms.currency AS main_currency
                           FROM mcp_members m LEFT JOIN mcp_staff s ON s.member_id = m.member_id LEFT JOIN mcp_sites ms ON ms.site_id = m.main_site_id WHERE m.member_id = :m");
    $st->execute(['m' => $memberId]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $r = decode_staff_ids($r, ['member_id', 'main_site_id']);
    foreach (['on_schedule', 'is_minor', 'has_profile'] as $k) {
        $r[$k] = (bool) $r[$k];
    }
    $r['max_hours_week'] = $r['max_hours_week'] === null ? null : (float) $r['max_hours_week'];
    $ps = $pdo->prepare('SELECT sp.position_id, sp.site_id, st.name AS site_name, st.currency, sp.position_name, sp.is_primary, p.color, p.area, sp.wage_rate, sp.wage_override, sp.wage_source
                           FROM mcp_staff_positions sp JOIN mcp_positions p ON p.position_id = sp.position_id JOIN mcp_sites st ON st.site_id = sp.site_id
                          WHERE sp.member_id = :m ORDER BY st.name, sp.is_primary DESC, lower(sp.position_name)');
    $ps->execute(['m' => $memberId]);
    $r['positions'] = array_map(static function (array $p): array {
        $p['position_id'] = (int) $p['position_id'];
        $p['site_id'] = (int) $p['site_id'];
        $p['is_primary'] = (bool) $p['is_primary'];
        foreach (['wage_rate', 'wage_override'] as $k) {
            $p[$k] = $p[$k] === null ? null : (float) $p[$k];
        }
        return $p;
    }, $ps->fetchAll());
    $rs = $pdo->prepare('SELECT r.site_id, st.name AS site_name, r.role_key, COALESCE(ro.name, r.role_key) AS role_name FROM mcp_member_site_roles r JOIN mcp_sites st ON st.site_id = r.site_id
                           LEFT JOIN ts_roles ro ON ro.role_key = r.role_key WHERE r.member_id = :m ORDER BY st.name');
    $rs->execute(['m' => $memberId]);
    $r['restaurants'] = array_map(static fn (array $x): array => ['site_id' => (int) $x['site_id']] + $x, $rs->fetchAll());
    return $r;
}

/** This week's scheduled hours of a person at a restaurant against their limit (mcp_hours_weekly; nothing scheduled = 0). */
function find_member_week_hours(PDO $pdo, int $memberId, int $siteId, string $weekStart): array
{
    $st = $pdo->prepare('SELECT shifts, scheduled_hours, max_hours_week, overtime_weekly_hours, over_own_limit FROM mcp_hours_weekly WHERE site_id = :s AND week_start = :w AND member_id = :m');
    $st->execute(['s' => $siteId, 'w' => $weekStart, 'm' => $memberId]);
    $r = $st->fetch();
    return $r === false ? ['shifts' => 0, 'scheduled_hours' => 0.0, 'max_hours_week' => null, 'overtime_weekly_hours' => null, 'over_own_limit' => false]
        : ['shifts' => (int) $r['shifts'], 'scheduled_hours' => (float) $r['scheduled_hours'], 'max_hours_week' => $r['max_hours_week'] === null ? null : (float) $r['max_hours_week'],
           'overtime_weekly_hours' => $r['overtime_weekly_hours'] === null ? null : (float) $r['overtime_weekly_hours'], 'over_own_limit' => (bool) $r['over_own_limit']];
}

/** The profile as a manager edits it (base tables, behind schedule.build): the fields and the positions worked, NO rate. Null when the person has no profile yet and works nowhere. */
function find_staff_for_edit(PDO $pdo, int $memberId): ?array
{
    $st = $pdo->prepare('SELECT m.id AS member_id, m.display_name, s.main_scope_id AS main_site_id, s.max_hours_week, COALESCE(s.is_minor, false) AS is_minor, s.minor_until::text AS minor_until,
                                COALESCE(s.active, true) AS active, s.notes
                           FROM members m LEFT JOIN staff_profiles s ON s.member_id = m.id WHERE m.id = :m AND m.member_kind = \'human\'');
    $st->execute(['m' => $memberId]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $r = decode_staff_ids($r, ['member_id', 'main_site_id']);
    $r['max_hours_week'] = $r['max_hours_week'] === null ? null : (float) $r['max_hours_week'];
    $r['is_minor'] = (bool) $r['is_minor'];
    $r['active'] = (bool) $r['active'];
    $p = $pdo->prepare('SELECT sp.position_id, sp.is_primary, p.scope_id AS site_id, p.name, p.archived_at FROM staff_positions sp JOIN positions p ON p.id = sp.position_id WHERE sp.member_id = :m ORDER BY p.scope_id, sp.is_primary DESC, p.name');
    $p->execute(['m' => $memberId]);
    $r['positions'] = array_map(static fn (array $x): array => ['position_id' => (int) $x['position_id'], 'is_primary' => (bool) $x['is_primary'], 'site_id' => (int) $x['site_id'], 'name' => $x['name'], 'archived' => $x['archived_at'] !== null], $p->fetchAll());
    return $r;
}

/** What a profile's log rows carry — never a rate, never the notes' text. */
function staff_state(PDO $pdo, int $memberId): array
{
    $st = $pdo->prepare('SELECT main_scope_id, max_hours_week, is_minor, minor_until::text AS minor_until, active FROM staff_profiles WHERE member_id = :m');
    $st->execute(['m' => $memberId]);
    $r = $st->fetch() ?: ['main_scope_id' => null, 'max_hours_week' => null, 'is_minor' => false, 'minor_until' => null, 'active' => true];
    $p = $pdo->prepare('SELECT position_id, is_primary FROM staff_positions WHERE member_id = :m ORDER BY position_id');
    $p->execute(['m' => $memberId]);
    $rows = $p->fetchAll();
    $primary = null;
    foreach ($rows as $x) {
        if ($x['is_primary']) {
            $primary = (int) $x['position_id'];
        }
    }
    return ['main_scope_id' => $r['main_scope_id'] === null ? null : (int) $r['main_scope_id'], 'max_hours_week' => $r['max_hours_week'] === null ? null : (float) $r['max_hours_week'],
            'is_minor' => (bool) $r['is_minor'], 'minor_until' => $r['minor_until'], 'active' => (bool) $r['active'],
            'position_ids' => array_map(static fn (array $x): int => (int) $x['position_id'], $rows), 'primary_position_id' => $primary];
}

/**
 * Save a person's profile. $f: main_site, max_hours_week (?float), is_minor, minor_until (?date), active, notes (?string, or the key absent to keep), position_ids (?array — null keeps them; else the
 * positions at the restaurants in `manage_sites` are REPLACED whole, those elsewhere left alone), primary (?position id: the first position when omitted). A new person's profile is made through the
 * database's ts_ensure_staff_profile(). Answers ['before' => state, 'after' => state, 'notes_changed' => bool].
 */
function save_staff(PDO $pdo, int $memberId, array $f, int $by): array
{
    $exists = $pdo->prepare('SELECT 1 FROM staff_profiles WHERE member_id = :m FOR UPDATE');
    $exists->execute(['m' => $memberId]);
    if ($exists->fetchColumn() === false) {
        $pdo->prepare('SELECT ts_ensure_staff_profile(:m, :s)')->execute(['m' => $memberId, 's' => (int) $f['main_site']]);
    }
    $before = staff_state($pdo, $memberId);
    $notesChanged = false;
    if (array_key_exists('notes', $f)) {
        $cur = $pdo->prepare('SELECT notes FROM staff_profiles WHERE member_id = :m');
        $cur->execute(['m' => $memberId]);
        $notesChanged = (string) $cur->fetchColumn() !== (string) $f['notes'];
    }
    $st = $pdo->prepare('UPDATE staff_profiles SET main_scope_id = :main, max_hours_week = CAST(:h AS numeric), is_minor = CAST(:mi AS boolean), minor_until = CAST(:mu AS date), active = CAST(:a AS boolean)'
        . (array_key_exists('notes', $f) ? ', notes = :notes' : '') . ' WHERE member_id = :m');
    $args = ['main' => (int) $f['main_site'], 'h' => $f['max_hours_week'] === null ? null : number_format((float) $f['max_hours_week'], 2, '.', ''), 'mi' => $f['is_minor'] ? 't' : 'f',
             'mu' => $f['is_minor'] ? $f['minor_until'] : null, 'a' => $f['active'] ? 't' : 'f', 'm' => $memberId];
    if (array_key_exists('notes', $f)) {
        $args['notes'] = $f['notes'];
    }
    $st->execute($args);
    if ($f['position_ids'] !== null) {
        $manage = array_map('intval', $f['manage_sites']);
        $want = array_values(array_unique(array_map('intval', $f['position_ids'])));
        $valid = $pdo->prepare('SELECT id FROM positions WHERE archived_at IS NULL AND scope_id = ANY (CAST(:sites AS bigint[])) AND id = ANY (CAST(:ids AS bigint[]))');
        $valid->execute(['sites' => '{' . implode(',', $manage) . '}', 'ids' => '{' . implode(',', $want) . '}']);
        if (count($valid->fetchAll()) !== count($want)) {
            throw new DomainException('Choose positions of the restaurants they work at.');
        }
        $primary = $f['primary'] ?? ($want[0] ?? null);
        if ($primary !== null && !in_array((int) $primary, $want, true)) {
            throw new DomainException('The main position has to be one they work.');
        }
        $pdo->prepare('UPDATE staff_positions SET is_primary = false WHERE member_id = :m AND is_primary')->execute(['m' => $memberId]);
        $pdo->prepare('DELETE FROM staff_positions WHERE member_id = :m AND position_id IN (SELECT id FROM positions WHERE scope_id = ANY (CAST(:sites AS bigint[])))
                          AND NOT (position_id = ANY (CAST(:ids AS bigint[])))')->execute(['m' => $memberId, 'sites' => '{' . implode(',', $manage) . '}', 'ids' => '{' . implode(',', $want) . '}']);
        $ins = $pdo->prepare('INSERT INTO staff_positions (member_id, position_id) VALUES (:m, :p) ON CONFLICT (member_id, position_id) DO NOTHING');
        foreach ($want as $p) {
            $ins->execute(['m' => $memberId, 'p' => $p]);
        }
        if ($primary !== null) {
            $pdo->prepare('UPDATE staff_positions SET is_primary = true WHERE member_id = :m AND position_id = :p')->execute(['m' => $memberId, 'p' => (int) $primary]);
        }
    }
    return ['before' => $before, 'after' => staff_state($pdo, $memberId), 'notes_changed' => $notesChanged];
}

/**
 * A person's OWN hourly rate at a position (null removes it, so the position's default applies). The caller holds pay.edit at the position's restaurant. Nothing of the number is returned.
 */
function set_wage_override(PDO $pdo, int $memberId, int $positionId, ?float $rate, int $by): void
{
    $st = $pdo->prepare('UPDATE staff_positions SET wage_override = CAST(:r AS numeric) WHERE member_id = :m AND position_id = :p');
    $st->execute(['r' => $rate === null ? null : number_format($rate, 2, '.', ''), 'm' => $memberId, 'p' => $positionId]);
    if ($st->rowCount() !== 1) {
        throw new DomainException('They do not work that position.');
    }
}

/** A position id of a person, and its restaurant (the record's site for a pay change): [position_id, site_id] or null. */
function staff_position_site(PDO $pdo, int $memberId, int $positionId): ?int
{
    $st = $pdo->prepare('SELECT p.scope_id FROM staff_positions sp JOIN positions p ON p.id = sp.position_id WHERE sp.member_id = :m AND sp.position_id = :p');
    $st->execute(['m' => $memberId, 'p' => $positionId]);
    $v = $st->fetchColumn();
    return $v === false ? null : (int) $v;
}
