<?php
declare(strict_types=1);

/** The prelude of every exchange handler: the bootstrap, the slice's query files, and the two gates a write starts with. */
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__) . '/shifts/queries.php';
require_once dirname(__DIR__) . '/shifts/present.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/present.php';
require_once __DIR__ . '/respond.php';

/** POST + CSRF (an action token stands in) + a signed-in person or an agent under a token. */
function exchange_handler_begin(): void
{
    require_post();
    require_login();
    verify_csrf();
}

/** A record's site the caller does not hold does not exist to them: the same sentence as a missing record. */
function require_record_site(?int $siteId, string $notFound): int
{
    if ($siteId === null || !in_array($siteId, array_column(held_sites(), 'scope_id'), true)) {
        refuse(404, $notFound);
    }
    return $siteId;
}

/** One of several rights at a site (a shift lead fills gaps, a builder can too); the sentence names the first. */
function require_any_right(array $rights, int $siteId): void
{
    require_site($siteId);
    foreach ($rights as $r) {
        if (has_right($r, $siteId)) {
            return;
        }
    }
    require_right($rights[0], $siteId);
}

/** "Priya's Server shift Fri Oct 9 · 5:00–11:00 pm" for a `did`. */
function exchange_did_shift(array $st): string
{
    return ($st['position_name'] ?? 'shift') . ' shift ' . shift_when($st['shift_starts_at'], $st['shift_ends_at'], $st['timezone']);
}
