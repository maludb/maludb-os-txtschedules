<?php
declare(strict_types=1);

/**
 * The prelude of every settings, day-part and rule handler (slice 7): slice 5's handler prelude (POST + login + CSRF, the guard that turns a DomainException or the database's RAISE into a 422,
 * the `done` tail and the landing), this slice's queries — and the gate they all share: settings.manage AT the restaurant the request names (held, else "Not found.").
 */
require_once dirname(__DIR__) . '/labor/handler.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/present.php';
require_once dirname(__DIR__) . '/rules/queries.php';
require_once dirname(__DIR__) . '/rules/present.php';

/** The restaurant a write names, held, with settings.manage there. */
function settings_gate_site(): int
{
    $site = people_named_site();
    require_right('settings.manage', $site);
    return $site;
}
