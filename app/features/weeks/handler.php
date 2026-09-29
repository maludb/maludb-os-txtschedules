<?php
declare(strict_types=1);

/**
 * The prelude of every week-builder handler (slice 2): the bootstrap and the query files, the gates a write starts with, the site
 * derived from the RECORD (a week, a shift, a template — never the session) and checked with require_right('schedule.build', $site),
 * the database's refusals in their own words (a 422), and the standard tail: report, then land.
 */
require_once dirname(__DIR__) . '/exchanges/handler.php';       // bootstrap, shifts read/present, exchanges (notify()), require_record_site()
require_once dirname(__DIR__) . '/shifts/write.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/present.php';
require_once __DIR__ . '/publish.php';
require_once dirname(__DIR__) . '/templates/queries.php';
require_once dirname(__DIR__) . '/autofill/autofill.php';

/** POST + login + CSRF (an action token stands in), in the order that makes an anonymous POST a 401 and not a bare 403. */
function build_handler_begin(): void
{
    require_post();
    require_login();
    verify_csrf();
}

/** Run a database step: a DomainException (our own sentence) or the database's RAISE (P0001) is a 422; an overlap the checks missed is a 422; anything else is a 500. */
function build_guard(PDO $pdo, callable $step): mixed
{
    try {
        return $step();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof DomainException) {
            $m = $e->getMessage();
            refuse($m === 'Shift not found.' || $m === 'Week not found.' ? 404 : 422, $m);
        }
        if ($e instanceof PDOException && (string) $e->getCode() === '23P01') {
            refuse(422, 'That would give someone two shifts at once.');
        }
        if ($e instanceof PDOException && (string) $e->getCode() === '23514') {
            refuse(422, 'A shift must end after it starts and can be at most 16 hours.');
        }
        if ($e instanceof PDOException && (string) $e->getCode() === 'P0001') {
            refuse(422, db_message($e, 'That could not be done.'));
        }
        throw $e;
    }
}

/** The request's week id (`week` or `week_id`) when it is one; null when absent. A non-number is a 422. */
function request_week_id(): ?int
{
    $v = req_val('week', 'week_id');
    if ($v === null || $v === '') {
        return null;
    }
    if (filter_var($v, FILTER_VALIDATE_INT) === false || (int) $v < 1) {
        refuse(422, 'Say which week.');
    }
    return (int) $v;
}

/**
 * The week an action is about, the caller allowed to build at its site. By id (`week`) — the site comes from the week — or by `site` + `week_start` (any date in
 * the week), in which case the week is made as an empty DRAFT if it does not exist yet (inside the handler's transaction). A site the person does not hold is
 * "Week not found." — the same sentence as a missing week.
 */
function resolve_week(PDO $pdo, int $by, bool $create = true): array
{
    $id = request_week_id();
    if ($id !== null) {
        $site = require_record_site(week_site_id($pdo, $id), 'Week not found.');
        require_right('schedule.build', $site);
        return week_row($pdo, $id) ?? refuse(404, 'Week not found.');
    }
    $siteId = request_integer('site') ?? refuse(422, 'Say which week (week) or which restaurant and day (site, week_start).');
    if (!in_array($siteId, array_column(held_sites(), 'scope_id'), true)) {
        refuse(404, 'Week not found.');
    }
    require_right('schedule.build', $siteId);
    $date = request_date('week_start');
    if ($date === null || $date === false) {
        refuse(422, 'Give week_start as a date like 2026-10-05.');
    }
    $site = find_site_row($pdo, $siteId) ?? refuse(404, 'Week not found.');
    $ws = week_start_of($date, (int) $site['week_start']);
    if (!$create) {
        $w = find_week($pdo, $siteId, $ws);
        return $w === null ? refuse(404, 'Week not found.') : (week_row($pdo, $w['week_id']) ?? refuse(404, 'Week not found.'));
    }
    return ensure_week_logged($pdo, $siteId, $ws, $by, 'week');
}

/** The site of the shift the request names (`shift` or `shift_id`), the caller having to hold it; 404 "Shift not found." otherwise. */
function request_shift_site(PDO $pdo, int &$shiftId): int
{
    $shiftId = request_shift_id();
    return require_record_site(shift_site_id($pdo, $shiftId), 'Shift not found.');
}

/** The restaurant a create names (`site`), held and buildable — else the current one. */
function request_build_site(): int
{
    $id = request_integer('site') ?? (int) current_site_id();
    if ($id < 1 || !in_array($id, array_column(held_sites(), 'scope_id'), true)) {
        refuse(404, 'Not found.');
    }
    require_right('schedule.build', $id);
    return $id;
}

/** The standard tail: report through emit_action_status(), then land (HTMX goes there; JSON learns the location). */
function build_done(string $did, ?int $recordId, string $path, array $data = []): never
{
    emit_action_status(true, ['did' => $did] + ($recordId === null ? [] : ['record_id' => $recordId]) + ['refresh' => 'shiftChanged'] + $data);
    saved_go($path, 'shiftChanged');
}

/** The log row of a shift change: entity shift, the site, before/after. */
function log_shift(PDO $pdo, string $action, int $shiftId, int $siteId, array $after, ?array $before = null): void
{
    log_activity($pdo, $action, 'shift', $shiftId, ['scope_id' => $siteId, 'after' => $after] + ($before === null ? [] : ['before' => $before]));
}

/** "Server shift Fri Oct 9 · 5:00–11:00 pm" for a `did`. */
function shift_did(array $s): string
{
    return ($s['position_name'] ?? 'shift') . ' shift ' . shift_when((string) $s['starts_at'], (string) $s['ends_at'], (string) $s['timezone']);
}
