<?php
declare(strict_types=1);

/**
 * The directory mirror (sign-on-and-directory.md §3–4). Rows are written ONLY from a hand-off
 * token's claims, from the change feed (bin/directory_sync.php), or from the kernel's answer to a
 * write txtSchedules made as the acting person — never from a form. Scoped by location: the sites and
 * each member's roles per site come from the claims' scopes[] and the feed's scopes[] / access[].
 */

class DirectoryRefused extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}

/**
 * A directory write as the acting person. Returns ['status', 'body', 'request_id']; throws
 * DirectoryRefused with the kernel's own sentence on a 4xx, and a 503 one when it is unreachable.
 */
function directory_call(string $method, string $path, array $body, int $actingMemberId): array
{
    $answer = kernel_call($method, '/api/v1/directory/' . ltrim($path, '/'), $body, ['X-Acting-Member: ' . $actingMemberId]);
    if ($answer === null) {
        throw new DirectoryRefused(503, 'The directory is not reachable right now; nothing was changed.');
    }
    if ($answer['status'] >= 400) {
        $message = (string) ($answer['body']['error']['message'] ?? 'The directory refused the change.');
        throw new DirectoryRefused($answer['status'], $message);
    }
    return $answer;
}

/** A directory read with the application token (the mirror's refresh). Null when unreachable. */
function directory_read(string $path): ?array
{
    $answer = kernel_call('GET', '/api/v1/directory/' . ltrim($path, '/'));
    return $answer !== null && $answer['status'] === 200 ? $answer['body'] : null;
}

// ---- applying rows to the mirror ------------------------------------------------------------
/** A member row as the feed or the claims carry it: id, display_name, email, member_kind, business_role, is_external, status, job_title, phone, timezone, updated_at, departments[]. */
function mirror_apply_member(PDO $pdo, array $m, ?string $capability = null, bool $capabilityKnown = false): void
{
    $id = (int) ($m['id'] ?? $m['member_id'] ?? 0);
    if ($id < 1) {
        return;
    }
    $status = (string) ($m['status'] ?? 'active');
    $status = $status === 'active' ? 'active' : 'inactive';        // suspended / offboarded → inactive here
    $args = [
        'id' => $id,
        'kind' => in_array($m['member_kind'] ?? 'human', ['human', 'agent'], true) ? $m['member_kind'] : 'human',
        'name' => (string) ($m['display_name'] ?? ('Member #' . $id)),
        'email' => ($m['email'] ?? '') !== '' ? (string) $m['email'] : null,
        'role' => in_array($m['business_role'] ?? 'user', ['super_admin', 'dept_admin', 'user'], true) ? $m['business_role'] : 'user',
        'ext' => !empty($m['is_external']) ? 't' : 'f',
        'status' => $status,
        'title' => ($m['job_title'] ?? '') !== '' ? (string) $m['job_title'] : null,
        'phone' => ($m['phone'] ?? '') !== '' ? (string) $m['phone'] : null,
        'tz' => ($m['timezone'] ?? '') !== '' ? (string) $m['timezone'] : 'UTC',
        'dupd' => $m['updated_at'] ?? null,
    ];
    if ($capabilityKnown) {
        $args['cap'] = $capability;
        $sql = <<<'SQL'
            INSERT INTO members (id, member_kind, display_name, email, business_role, is_external, status, capability, job_title, phone, timezone, directory_updated_at, synced_at)
            VALUES (:id, :kind, :name, :email, :role, :ext, :status, :cap, :title, :phone, :tz, :dupd, now())
            ON CONFLICT (id) DO UPDATE SET member_kind = EXCLUDED.member_kind, display_name = EXCLUDED.display_name, email = EXCLUDED.email,
                business_role = EXCLUDED.business_role, is_external = EXCLUDED.is_external, status = EXCLUDED.status, capability = EXCLUDED.capability,
                job_title = EXCLUDED.job_title, phone = EXCLUDED.phone, timezone = EXCLUDED.timezone, directory_updated_at = EXCLUDED.directory_updated_at, synced_at = now()
        SQL;
    } else {
        // The feed says nothing about this application's grant: keep what the last hand-off said.
        $sql = <<<'SQL'
            INSERT INTO members (id, member_kind, display_name, email, business_role, is_external, status, capability, job_title, phone, timezone, directory_updated_at, synced_at)
            VALUES (:id, :kind, :name, :email, :role, :ext, :status, NULL, :title, :phone, :tz, :dupd, now())
            ON CONFLICT (id) DO UPDATE SET member_kind = EXCLUDED.member_kind, display_name = EXCLUDED.display_name, email = EXCLUDED.email,
                business_role = EXCLUDED.business_role, is_external = EXCLUDED.is_external, status = EXCLUDED.status,
                job_title = EXCLUDED.job_title, phone = EXCLUDED.phone, timezone = EXCLUDED.timezone, directory_updated_at = EXCLUDED.directory_updated_at, synced_at = now()
        SQL;
    }
    $pdo->prepare($sql)->execute($args);
    if ($status !== 'active') {
        end_member_sessions($pdo, $id, 'directory');
    }
    if (isset($m['departments']) && is_array($m['departments'])) {
        mirror_apply_member_departments($pdo, $id, $m['departments']);
    }
}

/**
 * The txtSchedules roles the kernel says a member holds, as the UNION over sites (members.roles). Only roles
 * txtSchedules offers are kept; the rights they give are its own (ts_role_rights, db/004). Per site: below.
 */
function mirror_apply_roles(PDO $pdo, int $memberId, array $roles): void
{
    $known = $pdo->query('SELECT role_key FROM ts_roles')->fetchAll(PDO::FETCH_COLUMN);
    $keep = array_values(array_intersect(array_unique(array_map('strval', $roles)), $known));
    $pdo->prepare('UPDATE members SET roles = CAST(:roles AS text[]), synced_at = now() WHERE id = :id')
        ->execute(['roles' => '{' . implode(',', $keep) . '}', 'id' => $memberId]);
}

/**
 * One scopes[] row of the feed (or scopes.php): the site, created with its defaults the first time
 * (ts_site_materialise, db/013); its name, address and time zone follow the kernel's; removed = closed, kept.
 */
function mirror_apply_scope(PDO $pdo, array $s): void
{
    if (($s['kind'] ?? 'location') !== 'location' || empty($s['scope_id']) || empty($s['location_id'] ?? $s['id'] ?? null)) {
        return;
    }
    $pdo->prepare('SELECT ts_site_materialise(:id, :loc, :name, :addr, :tz, :removed)')->execute([
        'id' => (int) $s['scope_id'], 'loc' => (int) ($s['location_id'] ?? $s['id']),
        'name' => (string) ($s['name'] ?? ('Site ' . $s['scope_id'])), 'addr' => ($s['address'] ?? '') !== '' ? (string) $s['address'] : null,
        'tz' => ($s['timezone'] ?? '') !== '' ? (string) $s['timezone'] : null, 'removed' => $s['removed_at'] ?? null,
    ]);
}

/**
 * A member's whole holding, per site ([{scope_id, role, roles, capability, name?, id?}] — the claims' scopes[] or an
 * access[] row's scopes[]): replaces member_site_roles. A site the mirror does not know yet is created from a claims
 * entry (it carries the name); the feed's scopes[] fills address and time zone within a minute. The first site a
 * person holds becomes their main restaurant (D4) — a manager may change it.
 */
function mirror_apply_holding(PDO $pdo, int $memberId, array $scopes, bool $createSites = false): void
{
    $pdo->prepare('DELETE FROM member_site_roles WHERE member_id = :m')->execute(['m' => $memberId]);
    $known = $pdo->query('SELECT role_key FROM ts_roles')->fetchAll(PDO::FETCH_COLUMN);
    $first = null;
    foreach ($scopes as $sc) {
        $scopeId = (int) ($sc['scope_id'] ?? 0);
        $capability = in_array($sc['capability'] ?? null, ['read', 'write', 'admin'], true) ? $sc['capability'] : null;
        if ($scopeId < 1 || $capability === null) {
            continue;
        }
        if ($createSites && !db_bool($pdo, 'SELECT EXISTS (SELECT 1 FROM sites WHERE scope_id = :s)', ['s' => $scopeId])) {
            mirror_apply_scope($pdo, ['scope_id' => $scopeId, 'kind' => 'location', 'location_id' => $sc['id'] ?? $sc['location_id'] ?? null,
                                      'name' => $sc['name'] ?? null]);
        }
        $roles = is_array($sc['roles'] ?? null) ? $sc['roles'] : (($sc['role'] ?? null) !== null ? [$sc['role']] : []);
        $roles = array_values(array_intersect(array_unique(array_map('strval', $roles)), $known));
        $pdo->prepare(<<<'SQL'
            INSERT INTO member_site_roles (member_id, scope_id, role_key, roles, capability)
            SELECT :m, :s, :r, CAST(:rs AS text[]), :c WHERE EXISTS (SELECT 1 FROM sites WHERE scope_id = :s AND removed_at IS NULL)
        SQL)->execute(['m' => $memberId, 's' => $scopeId, 'r' => $sc['role'] ?? ($roles[0] ?? null),
                       'rs' => '{' . implode(',', $roles) . '}', 'c' => $capability]);
        $first ??= $scopeId;
    }
    if ($first !== null && (find_member_by_id($pdo, $memberId)['member_kind'] ?? 'human') === 'human') {
        $pdo->prepare('SELECT ts_ensure_staff_profile(:m, :s)')->execute(['m' => $memberId, 's' => $first]);
    }
}

/**
 * One access[] row of the change feed (kernel db/141, db/145): the member's whole holding here. It replaces their
 * capability, their roles and their sites; a member left holding nothing loses txtSchedules and every session.
 * A member the mirror does not know is ignored — the directory rows introduce people.
 */
function mirror_apply_access(PDO $pdo, array $a): bool
{
    $id = (int) ($a['member_id'] ?? 0);
    if ($id < 1 || find_member_by_id($pdo, $id) === null) {
        return false;
    }
    $capability = in_array($a['capability'] ?? null, ['read', 'write', 'admin'], true) ? $a['capability'] : null;
    $pdo->prepare('UPDATE members SET capability = :cap, synced_at = now() WHERE id = :id')->execute(['cap' => $capability, 'id' => $id]);
    mirror_apply_roles($pdo, $id, $capability === null ? [] : (is_array($a['roles'] ?? null) ? $a['roles'] : []));
    mirror_apply_holding($pdo, $id, $capability === null ? [] : (is_array($a['scopes'] ?? null) ? $a['scopes'] : []));
    if ($capability === null) {
        end_member_sessions($pdo, $id, 'directory');
    }
    return true;
}

/** The member's live departments, as a hand-off's claims or a member row list them: the whole set. */
function mirror_apply_member_departments(PDO $pdo, int $memberId, array $departments): void
{
    $keep = [];
    foreach ($departments as $d) {
        $did = (int) ($d['id'] ?? 0);
        if ($did < 1) {
            continue;
        }
        $keep[] = $did;
        $pdo->prepare('INSERT INTO departments (id, name) VALUES (:id, :name) ON CONFLICT (id) DO NOTHING')
            ->execute(['id' => $did, 'name' => (string) ($d['name'] ?? ('Department #' . $did))]);
        $pdo->prepare(<<<'SQL'
            INSERT INTO department_members (member_id, department_id, is_admin, is_primary, left_at, synced_at)
            VALUES (:m, :d, :a, :p, NULL, now())
            ON CONFLICT (member_id, department_id) DO UPDATE SET is_admin = EXCLUDED.is_admin, is_primary = EXCLUDED.is_primary, left_at = NULL, synced_at = now()
        SQL)->execute(['m' => $memberId, 'd' => $did, 'a' => !empty($d['is_admin']) ? 't' : 'f', 'p' => !empty($d['is_primary']) ? 't' : 'f']);
    }
    $in = $keep === [] ? 'NULL' : implode(',', array_map('intval', $keep));
    $pdo->prepare("UPDATE department_members SET left_at = now(), synced_at = now() WHERE member_id = :m AND left_at IS NULL AND department_id NOT IN ({$in})")
        ->execute(['m' => $memberId]);
}

function mirror_apply_department(PDO $pdo, array $d): void
{
    $id = (int) ($d['id'] ?? 0);
    if ($id < 1) {
        return;
    }
    $parent = isset($d['parent_id']) && $d['parent_id'] !== null ? (int) $d['parent_id'] : null;
    if ($parent !== null) {
        $pdo->prepare('INSERT INTO departments (id, name) VALUES (:id, :name) ON CONFLICT (id) DO NOTHING')->execute(['id' => $parent, 'name' => 'Department #' . $parent]);
    }
    $manager = isset($d['manager_member_id']) && $d['manager_member_id'] !== null ? (int) $d['manager_member_id'] : null;
    if ($manager !== null && find_member_by_id($pdo, $manager) === null) {
        $manager = null;                                   // the member row arrives in the same feed; the next pass names them
    }
    $pdo->prepare(<<<'SQL'
        INSERT INTO departments (id, name, description, parent_id, manager_member_id, is_system, system_key, archived_at, directory_updated_at, synced_at)
        VALUES (:id, :name, :desc, :parent, :manager, :sys, :key, :arch, :dupd, now())
        ON CONFLICT (id) DO UPDATE SET name = EXCLUDED.name, description = EXCLUDED.description, parent_id = EXCLUDED.parent_id,
            manager_member_id = EXCLUDED.manager_member_id, is_system = EXCLUDED.is_system, system_key = EXCLUDED.system_key,
            archived_at = EXCLUDED.archived_at, directory_updated_at = EXCLUDED.directory_updated_at, synced_at = now()
    SQL)->execute([
        'id' => $id, 'name' => (string) ($d['name'] ?? ('Department #' . $id)),
        'desc' => ($d['description'] ?? '') !== '' ? (string) $d['description'] : null,
        'parent' => $parent === $id ? null : $parent, 'manager' => $manager,
        'sys' => !empty($d['is_system']) ? 't' : 'f', 'key' => $d['system_key'] ?? null,
        'arch' => $d['archived_at'] ?? null, 'dupd' => $d['updated_at'] ?? null,
    ]);
}

function mirror_apply_membership(PDO $pdo, array $dm): void
{
    $m = (int) ($dm['member_id'] ?? 0);
    $d = (int) ($dm['department_id'] ?? 0);
    if ($m < 1 || $d < 1 || find_member_by_id($pdo, $m) === null) {
        return;
    }
    $pdo->prepare('INSERT INTO departments (id, name) VALUES (:id, :name) ON CONFLICT (id) DO NOTHING')->execute(['id' => $d, 'name' => 'Department #' . $d]);
    $pdo->prepare(<<<'SQL'
        INSERT INTO department_members (member_id, department_id, is_admin, is_primary, joined_at, left_at, synced_at)
        VALUES (:m, :d, :a, :p, :j, :l, now())
        ON CONFLICT (member_id, department_id) DO UPDATE SET is_admin = EXCLUDED.is_admin, is_primary = EXCLUDED.is_primary,
            joined_at = COALESCE(EXCLUDED.joined_at, department_members.joined_at), left_at = EXCLUDED.left_at, synced_at = now()
    SQL)->execute(['m' => $m, 'd' => $d, 'a' => !empty($dm['is_admin']) ? 't' : 'f', 'p' => !empty($dm['is_primary']) ? 't' : 'f',
                   'j' => $dm['joined_at'] ?? null, 'l' => $dm['left_at'] ?? null]);
}

/**
 * Apply one feed document (os.directory-changes/1 or the plain lists). Members first (departments
 * name managers, memberships name members), then departments twice (parents and managers may
 * arrive in any order), then memberships. Returns the counts.
 */
function mirror_apply_feed(PDO $pdo, array $doc): array
{
    $counts = ['members' => 0, 'departments' => 0, 'memberships' => 0];
    foreach ($doc['members'] ?? [] as $m) {
        $known = find_member_by_id($pdo, (int) ($m['id'] ?? 0));
        $capability = $known['capability'] ?? null;
        // A brand-new member has no grant until a hand-off says so (NULL); a known one keeps theirs.
        mirror_apply_member($pdo, array_diff_key($m, ['departments' => true]), $capability, $known === null);
        $counts['members']++;
    }
    for ($pass = 0; $pass < 2; $pass++) {
        foreach ($doc['departments'] ?? [] as $d) {
            mirror_apply_department($pdo, $d);
        }
    }
    $counts['departments'] = count($doc['departments'] ?? []);
    if (!empty($doc['full'])) {
        // A full document lists every LIVE membership: what it does not list has left.
        $live = [];
        foreach ($doc['memberships'] ?? [] as $dm) {
            $live[] = (int) $dm['member_id'] . ':' . (int) $dm['department_id'];
        }
        $st = $pdo->query('SELECT member_id, department_id FROM department_members WHERE left_at IS NULL');
        foreach ($st->fetchAll() as $row) {
            if (!in_array($row['member_id'] . ':' . $row['department_id'], $live, true)) {
                $pdo->prepare('UPDATE department_members SET left_at = now(), synced_at = now() WHERE member_id = :m AND department_id = :d')
                    ->execute(['m' => $row['member_id'], 'd' => $row['department_id']]);
            }
        }
    }
    foreach ($doc['memberships'] ?? [] as $dm) {
        mirror_apply_membership($pdo, $dm);
        $counts['memberships']++;
    }
    // The sites first (a holding names them), then what each affected member now holds — capability, roles, sites.
    $counts['scopes'] = 0;
    foreach ($doc['scopes'] ?? [] as $sc) {
        mirror_apply_scope($pdo, $sc);
        $counts['scopes']++;
    }
    $counts['access'] = 0;
    foreach ($doc['access'] ?? [] as $a) {
        $counts['access'] += mirror_apply_access($pdo, $a) ? 1 : 0;
    }
    return $counts;
}
