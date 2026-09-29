<?php
declare(strict_types=1);

/** The prelude of every announcement, settings and calendar handler (slice 6): slice 4's (POST + login + CSRF, the guard, `done`, a restaurant named and held) plus this slice's queries. */
require_once dirname(__DIR__) . '/staff/handler.php';
require_once dirname(__DIR__) . '/notify/queue.php';
require_once dirname(__DIR__) . '/notify/prefs.php';
require_once dirname(__DIR__) . '/calendar/ics.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/present.php';
