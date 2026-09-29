<?php
declare(strict_types=1);

/**
 * The prelude of every availability and time-off handler (slice 3): the bootstrap, the slice's query files, the shift writer (approving may open shifts), and the gates a write starts with.
 * The site comes from the RECORD — the request, the block, the type, the blackout — never the session; a site the caller does not hold is "Not found." in the record's own words.
 */
require_once dirname(__DIR__) . '/exchanges/handler.php';       // bootstrap, shifts read/present, exchanges (notify(), require_record_site(), return_path(), request_note())
require_once dirname(__DIR__) . '/shifts/write.php';
require_once dirname(__DIR__) . '/availability/queries.php';
require_once dirname(__DIR__) . '/availability/present.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/present.php';
require_once __DIR__ . '/respond.php';

/** POST + login + CSRF (an action token stands in), in the order that makes an anonymous POST a 401 and not a bare 403. */
function timeoff_handler_begin(): void
{
    require_post();
    require_login();
    verify_csrf();
}

/** The restaurant a create names (`site`) — held — else the current one. */
function request_named_site(): int
{
    $id = request_integer('site') ?? (int) current_site_id();
    if ($id < 1 || !in_array($id, array_column(held_sites(), 'scope_id'), true)) {
        refuse(404, 'Not found.');
    }
    return $id;
}
