<?php
declare(strict_types=1);
/**
 * /coverage?shift= — cover a gap (screen `coverage`): the shift at the top, the eligible list (main-restaurant staff, free, no hard rule broken, fewest hours
 * that week first, each with any soft warning as a chip), checkboxes, a note, "Ask these people" (coverage_request). Without ?shift=, the open shifts to choose from.
 * For coverage.fill (a shift lead) and schedule.build; nobody outside the shift's main restaurant is ever listed (D12).
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/shifts/queries.php';
require_once dirname(__DIR__) . '/app/features/shifts/present.php';
require_once dirname(__DIR__) . '/app/features/exchanges/queries.php';
require_once dirname(__DIR__) . '/app/features/exchanges/present.php';
require_login();
require_human();
$pdo = db();
$shiftId = request_integer('shift');
$shift = null;
if ($shiftId !== null) {
    $siteId = shift_site_id($pdo, $shiftId);
    if ($siteId === null || !in_array($siteId, array_column(held_sites(), 'scope_id'), true)) {
        refuse(404, 'Shift not found.');
    }
    if (!has_right('coverage.fill', $siteId) && !has_right('schedule.build', $siteId)) {
        require_right('coverage.fill', $siteId);
    }
    $shift = find_shift($pdo, $shiftId) ?? refuse(404, 'Shift not found.');
} else {
    require_right('coverage.fill');
    $siteId = (int) current_site_id();
}
$candidates = $shift !== null && $shift['status'] === 'scheduled' && $shift['published_at'] !== null ? find_coverage_candidates($pdo, (int) $shift['shift_id']) : [];
$open = $shift === null ? find_open_shifts($pdo, $siteId) : [];
log_screen_view($pdo, 'coverage');
if (wants_json()) {
    respond_screen(['shift' => $shift === null ? null : present_shift($shift), 'open_shifts' => array_map('present_shift', array_map(static fn (array $s): array => $s + ['others' => []], $open)),
        'candidates' => array_map(static fn (array $c): array => ['member_id' => $c['member_id'], 'name' => $c['display_name'], 'hours_this_week' => (float) $c['hours_this_week'],
            'warnings' => array_map(static fn (array $w): string => (string) ($w['message'] ?? ''), $c['warnings'])], $candidates)]);
}
render_screen('Coverage', view('exchanges/coverage.php', ['shift' => $shift, 'candidates' => $candidates, 'open' => $open, 'siteId' => $siteId, 'zone' => show_zone(), 'back' => back_link(),
    'site' => find_site_row($pdo, $siteId), 'notice' => notice_words($_GET['notice'] ?? null)]),
    ['activeNav' => 'coverage', 'screen' => 'coverage', 'entity' => 'shift', 'recordId' => $shift === null ? '' : (string) $shift['shift_id']]);
