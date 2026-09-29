<?php
declare(strict_types=1);
/**
 * GET /shifts/check.php — the live check of the shift form (a read, Pattern A fragment): the same rules engine the save asks (ts_check_assignment), answered as the sentences under the
 * assignee field. A hard rule disables Save; a soft one asks for the reason. Writes nothing; schedule.build at the shift's or the named site.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/shifts/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/shifts/present.php';
require_once dirname(__DIR__, 2) . '/app/features/weeks/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/shifts/write.php';
require_login();
require_human();
$pdo = db();
$shiftId = request_integer('shift');
$snap = null;
if ($shiftId !== null) {
    $siteId = shift_site_id($pdo, $shiftId) ?? refuse(404, 'Shift not found.');
    $snap = shift_snapshot($pdo, $shiftId);
} else {
    $siteId = request_integer('site') ?? (int) current_site_id();
}
require_site($siteId);
require_right('schedule.build', $siteId);
$site = find_site_row($pdo, $siteId) ?? refuse(404, 'Not found.');
$check = shift_form_findings($pdo, $siteId, (string) $site['timezone'], $snap);
header('Vary: HX-Request');
echo view('shifts/partials/check.php', ['check' => $check, 'oob' => true, 'reason' => request_string('override_reason'), 'saveLabel' => request_string('save_label', 'Save the shift')]);
