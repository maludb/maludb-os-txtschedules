<?php
declare(strict_types=1);

/**
 * The prelude of every people, positions and certification handler (slice 4): the bootstrap, the slice's query files, and the gates a write starts with. The site comes from the RECORD — the
 * position, the kind, the card, the person's profile — never the session; a site the caller does not hold is "Not found." A rate is never echoed: not in `did`, not in the JSON, not in the log.
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__) . '/shifts/queries.php';
require_once dirname(__DIR__) . '/shifts/present.php';
require_once dirname(__DIR__) . '/exchanges/queries.php';
require_once dirname(__DIR__) . '/exchanges/present.php';
require_once dirname(__DIR__) . '/exchanges/respond.php';
require_once dirname(__DIR__) . '/availability/queries.php';
require_once dirname(__DIR__) . '/availability/present.php';
require_once dirname(__DIR__) . '/timeoff/present.php';
require_once dirname(__DIR__) . '/weeks/queries.php';
require_once dirname(__DIR__) . '/shifts/write.php';
require_once dirname(__DIR__) . '/positions/queries.php';
require_once dirname(__DIR__) . '/certifications/queries.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/present.php';

/** POST + login + CSRF (an action token stands in): an anonymous POST is a 401, never a bare 403. */
function people_handler_begin(): void
{
    require_post();
    require_login();
    verify_csrf();
}

/** A record's site the caller does not hold does not exist to them. */
function people_record_site(?int $siteId, string $notFound): int
{
    if ($siteId === null || !in_array($siteId, array_column(held_sites(), 'scope_id'), true)) {
        refuse(404, $notFound);
    }
    return $siteId;
}

/** Run a step: our own sentence (DomainException) or the database's RAISE is a 422 ('Not found.' a 404); a second live row of the same name a 422. Anything else is a 500. */
function people_guard(PDO $pdo, callable $step): mixed
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
            refuse(422, db_message($e, 'That could not be done.'));
        }
        if ($e instanceof PDOException && (string) $e->getCode() === '23505') {
            refuse(422, 'That is already there.');
        }
        throw $e;
    }
}

/** The person the request names (`member`, `member_id`) — a number, or the caller when omitted and $default says so. */
function request_member_id(bool $default = false): int
{
    $id = request_integer('member') ?? request_integer('member_id');
    if ($id === null) {
        if ($default) {
            return (int) current_member_id();
        }
        refuse(422, 'Say which person.');
    }
    return $id;
}

/**
 * The restaurant a write about a PERSON is gated at: among the restaurants they work at that the caller holds, the one where the caller holds $right — the person's main restaurant first.
 * A person the caller shares no restaurant with does not exist to them (404); one they share but lack the right at is a 403 in the right's words.
 */
function person_gate_site(PDO $pdo, int $memberId, string $right): int
{
    $held = array_column(held_sites(), 'scope_id');
    $theirs = array_values(array_intersect(member_site_ids($pdo, $memberId), $held));
    if ($theirs === []) {
        refuse(404, 'That person is not here.');
    }
    $main = $pdo->prepare('SELECT main_scope_id FROM staff_profiles WHERE member_id = :m');
    $main->execute(['m' => $memberId]);
    $mainId = $main->fetchColumn();
    $order = $mainId !== false && in_array((int) $mainId, $theirs, true) ? array_merge([(int) $mainId], array_diff($theirs, [(int) $mainId])) : $theirs;
    foreach ($order as $s) {
        if (has_right($right, $s)) {
            return $s;
        }
    }
    require_right($right, $order[0]);
    return $order[0];
}

/** An optional hourly rate from the request (`rate`): null when empty (remove or clear), else a number from 0 to 10000. Accepts "$14.5". */
function request_rate(): ?float
{
    $r = req_val('rate');
    if ($r === null || trim($r) === '') {
        return null;
    }
    $r = ltrim(trim($r), '$');
    if (!is_numeric($r) || (float) $r < 0 || (float) $r > 10000) {
        refuse(422, 'The rate is an hourly amount from 0 to 10000.');
    }
    return round((float) $r, 2);
}

/** A yes/no request field: 1, true, on, yes = yes; else $keep when the field was left out. */
function people_yes(string $name, ?bool $keep = null): bool
{
    if (!req_has($name)) {
        return $keep ?? false;
    }
    return in_array(strtolower((string) req_val($name)), ['1', 'true', 'on', 'yes'], true);
}

/** The values of a repeated field (`positions[]`), or a comma list, as trimmed non-empty strings; null when the field was left out. */
function request_list(string $name): ?array
{
    if (!array_key_exists($name, $_POST) && !array_key_exists($name, $_GET) && !array_key_exists($name . '[]', $_POST)) {
        return null;
    }
    $v = $_POST[$name] ?? $_GET[$name] ?? $_POST[$name . '[]'] ?? [];
    $v = is_array($v) ? $v : explode(',', (string) $v);
    return array_values(array_filter(array_map(static fn ($x): string => trim((string) $x), $v), static fn (string $x): bool => $x !== ''));
}

/** Positions named by id or by name → ids at the given restaurants (live); an unknown one is a 422. */
function resolve_position_ids(PDO $pdo, array $names, array $siteIds): array
{
    $out = [];
    foreach ($names as $n) {
        if (ctype_digit($n)) {
            $out[] = (int) $n;
            continue;
        }
        $st = $pdo->prepare('SELECT id FROM positions WHERE lower(name) = lower(:n) AND archived_at IS NULL AND scope_id = ANY (CAST(:s AS bigint[])) ORDER BY scope_id LIMIT 1');
        $st->execute(['n' => $n, 's' => '{' . implode(',', $siteIds) . '}']);
        $id = $st->fetchColumn();
        if ($id === false) {
            refuse(422, 'There is no position called ' . $n . ' here.');
        }
        $out[] = (int) $id;
    }
    return $out;
}

/** Certification kinds named by id or name → ids of live kinds at the restaurant; unknown → 422. */
function resolve_kind_ids(PDO $pdo, array $names, int $siteId): array
{
    $out = [];
    foreach ($names as $n) {
        if (ctype_digit($n)) {
            $out[] = (int) $n;
            continue;
        }
        $st = $pdo->prepare('SELECT id FROM certification_kinds WHERE lower(name) = lower(:n) AND archived_at IS NULL AND scope_id = :s');
        $st->execute(['n' => $n, 's' => $siteId]);
        $id = $st->fetchColumn();
        if ($id === false) {
            refuse(422, 'There is no certification called ' . $n . ' here.');
        }
        $out[] = (int) $id;
    }
    return $out;
}

/** The standard tail: report through emit_action_status(), then land. */
function people_done(string $did, ?int $recordId, string $path, string $event, array $data = []): never
{
    emit_action_status(true, ['did' => $did] + ($recordId === null ? [] : ['record_id' => $recordId]) + ['refresh' => $event] + $data);
    saved_go($path, $event);
}

/** A path with the banner key and an optional anchor — a create's location ends in the record id ( … #position-12 ). */
function people_land(string $path, string $notice, string $anchor = ''): string
{
    return land_with_notice(explode('#', $path, 2)[0], $notice) . ($anchor !== '' ? '#' . $anchor : '');
}

/** The restaurant a create names (`site`), held, else the current one. */
function people_named_site(): int
{
    $id = request_integer('site') ?? (int) current_site_id();
    if ($id < 1 || !in_array($id, array_column(held_sites(), 'scope_id'), true)) {
        refuse(404, 'Not found.');
    }
    return $id;
}
