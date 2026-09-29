<?php
declare(strict_types=1);
/**
 * /certifications/mine — the signed-in person's own cards, on the phone (screen `my-certifications`): expired in danger, due soon in warning, "Verified by a manager" or "Waiting for a manager to check";
 * add one (once for every restaurant of theirs that has the kind), correct one, remove one. Nobody else's cards are here.
 */
require_once dirname(__DIR__, 2) . '/app/features/staff/handler.php';
require_login();
require_human();
$pdo = db();
$me = (int) current_member_id();
require_right('schedule.view_own');
$name = (string) (current_member()['display_name'] ?? '');
$cards = find_certifications($pdo, $me);
$held = array_column(held_sites(), 'scope_id');
$kinds = [];
$seen = [];
foreach ($held as $s) {
    foreach (find_certification_kinds($pdo, $s) as $k) {
        if (!isset($seen[strtolower($k['name'])])) {
            $seen[strtolower($k['name'])] = true;
            $kinds[] = $k;
        }
    }
}
log_screen_view($pdo, 'my-certifications');
if (wants_json()) {
    respond_screen(['certifications' => array_map('present_certification', $cards), 'kinds' => array_map(static fn (array $k): array => ['kind_id' => $k['kind_id'], 'name' => $k['name'], 'track_expiry' => $k['track_expiry']], $kinds)]);
}
render_screen('My certifications', view('certifications/mine.php', ['me' => $me, 'name' => $name, 'cards' => $cards, 'kinds' => $kinds, 'editCert' => request_integer('cert'), 'addKind' => request_integer('kind'),
    'multi' => count($held) > 1, 'notice' => staff_notice($_GET['notice'] ?? null)]),
    ['activeNav' => 'my-certifications', 'screen' => 'my-certifications', 'entity' => 'certification']);
