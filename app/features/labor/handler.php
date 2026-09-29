<?php
declare(strict_types=1);

/**
 * The prelude of every labor and forecast handler (slice 5): slice 4's handler prelude (POST + login + CSRF, the guard that turns a DomainException or the database's RAISE into a 422, the
 * `done` tail that reports through emit_action_status() and lands, a restaurant named and held) plus this slice's queries. The restaurant is the one the request names — held, else "Not found." —
 * and every right is asked AT it.
 */
require_once dirname(__DIR__) . '/staff/handler.php';
require_once dirname(__DIR__) . '/weeks/present.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/present.php';
require_once __DIR__ . '/reservations.php';

/** A whole number of covers from a request text: 0..100000; refuses anything else in words. */
function request_covers_value(?string $v): int
{
    if ($v === null || !preg_match('/^\d{1,6}$/', trim($v)) || (int) $v > 100000) {
        refuse(422, 'Covers are a whole number from 0 to 100000.');
    }
    return (int) $v;
}

/** An optional decimal (empty → null) within [$min, $max]; refuses otherwise. */
function request_decimal_value(?string $v, float $min, float $max, string $words): ?float
{
    if ($v === null || trim($v) === '') {
        return null;
    }
    $t = str_replace(',', '', ltrim(trim($v), '$'));
    if (!is_numeric($t) || (float) $t < $min || (float) $t > $max) {
        refuse(422, $words);
    }
    return round((float) $t, 2);
}

/** The Monday-or-so a request's week date starts, in the restaurant's own week start; refuses a malformed date. */
function request_week_start(string $name, int $weekStartDay, bool $required = true): ?string
{
    $d = request_date($name);
    if ($d === null) {
        return $required ? refuse(422, 'Give ' . $name . ' as a date like 2026-10-05.') : null;
    }
    if ($d === false) {
        refuse(422, 'Give ' . $name . ' as a date like 2026-10-05.');
    }
    return week_start_of($d, $weekStartDay);
}
