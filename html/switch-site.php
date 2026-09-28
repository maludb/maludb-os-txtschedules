<?php
declare(strict_types=1);

/**
 * The restaurant switcher's POST (scoped-applications.md §4.2). The session's current site becomes one the person
 * HOLDS; any other — a closed site, a site of someone else's — is refused 403 and nothing changes. The bootstrap
 * re-checks the choice on every request, so a later revocation still moves the person on.
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

require_post();
verify_csrf();
require_login();
require_human();
$pdo = db();
$to = request_integer('site');
$held = array_column(held_sites(), 'scope_id');
if ($to === null || !in_array($to, $held, true)) {
    log_activity($pdo, 'site.switch.refused', 'site', $to, ['after' => ['reason' => 'not held']]);
    refuse(403, 'You do not hold that restaurant.');
}
$from = current_site_id();
$_SESSION['scope_id'] = $to;
$pdo->prepare('UPDATE member_sessions SET scope_id = :s WHERE session_hash = :h')->execute(['s' => $to, 'h' => session_hash(session_id())]);
log_activity($pdo, 'site.switch', 'site', $to, ['scope_id' => $to, 'before' => ['scope_id' => $from], 'after' => ['scope_id' => $to]]);
emit_action_status(true, ['did' => 'Switched restaurant', 'record_id' => $to]);
if (wants_json()) {
    respond_saved(['site_id' => $to, 'location' => '/']);
}
redirect('/');
