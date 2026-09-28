<?php
declare(strict_types=1);

/** There is no login form: the kernel signs people in. A visitor with no session goes to the launcher, which brings them back. */
require_once dirname(__DIR__) . '/app/bootstrap.php';
header('Cache-Control: no-store');
header('Location: ' . launcher_url('app=' . rawurlencode(app_key())), true, 302);
exit;
