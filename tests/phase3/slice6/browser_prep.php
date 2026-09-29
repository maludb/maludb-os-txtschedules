<?php
/** Builds what the browser proof looks at (all SMOKE) and prints it as JSON: announcements of every audience (one pinned, one with a link, some read), a text refusal note for Priya, and the cast's claims. */
require __DIR__ . '/lib.php';
$W = reset6();
$mara = as_member(33); $priya = as_member(26); $ana = as_member(30); $lee = as_member(31); as_member(32); as_member(1, 102); as_member(27, 101); as_planner();
$pin = (new DateTimeImmutable('+4 days'))->format('Y-m-d');
[, $b1] = act($mara, '/announcements/save.php', ['site' => 102, 'title' => 'SMOKE Parking behind the building this Friday', 'audience' => 'site', 'pinned_until' => $pin,
    'body' => "The front lot is being resurfaced Friday and Saturday.\n\nPlease park behind the building and use the kitchen door. Map: https://example.invalid/parking-map-with-a-rather-long-address-that-must-wrap-and-not-scroll-the-page-sideways\n\nThank you all — Mara"]);
[, $b2] = act($mara, '/announcements/save.php', ['site' => 102, 'title' => 'SMOKE Bar inventory Sunday', 'audience' => 'position', 'position' => $W['aBar'], 'body' => 'Bar staff: inventory is Sunday at 9 am, before the doors open.']);
[, $b3] = act($mara, '/announcements/save.php', ['site' => 102, 'title' => 'SMOKE New menu tasting', 'audience' => 'people', 'members' => [26, 31], 'body' => 'You two are tasting the new menu on Tuesday at 3 pm.']);
act($priya, '/announcements/read.php', ['announcement' => $b1['record_id']]);
act($lee, '/announcements/read.php', ['announcement' => $b1['record_id']]);
admin_sql("INSERT INTO notification_outbox (member_id, scope_id, channel, kind, body, status, error) VALUES (26, 102, 'sms', 'reminder', 'x', 'skipped', 'no_verified_phone')");
admin_sql("DELETE FROM notification_outbox WHERE status = 'queued'");
echo json_encode(['ids' => ['a1' => $b1['record_id'], 'a2' => $b2['record_id'], 'a3' => $b3['record_id'], 'bar' => $W['aBar'], 'srv' => $W['aSrv']], 'claims' => cast() + ['35' => planner_claims()]]);
