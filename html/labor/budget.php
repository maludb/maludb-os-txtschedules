<?php
declare(strict_types=1);
/**
 * Action `budget_save` (log `budget.update`): settings.manage at the restaurant — who also holds labor.view, because an amount is a cost figure — sets a week's labor budget for an area
 * (all, front, kitchen, bar, management, other): hours and/or an amount; both empty removes it. before/after carry the plan (area, hours, amount) — a budget is a plan, not a wage.
 */
require_once dirname(__DIR__, 2) . '/app/features/labor/handler.php';
people_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$site = people_named_site();
require_right('settings.manage', $site);
require_right('labor.view', $site);
$ws = request_week_start('week_start', find_site_week_start($pdo, $site));
$area = req_val('area') ?? 'all';
if (!isset(BUDGET_AREAS[$area])) {
    refuse(422, 'The area is all, front, kitchen, bar, management or other.');
}
$hours = request_decimal_value(req_val('budget_hours'), 0, 99999, 'Budget hours are from 0 to 99999.');
$amount = request_decimal_value(req_val('budget_amount'), 0, 9999999, 'A budget amount is from 0 to 9999999.');
$r = people_guard($pdo, static function () use ($pdo, $me, $site, $ws, $area, $hours, $amount): array {
    $pdo->beginTransaction();
    $r = save_budget($pdo, $site, $ws, $area, $hours, $amount, $me);
    $tag = ['area' => $area, 'week_start' => $ws];
    log_activity($pdo, 'budget.update', 'labor_budget', $r['id'], ['scope_id' => $site, 'after' => $tag + ($r['after'] ?? ['removed' => true])] + ($r['before'] === null ? [] : ['before' => $tag + $r['before']]));
    $pdo->commit();
    return $r;
});
$cleared = $r['after'] === null;
people_done(($cleared ? 'Removed the ' : 'Saved the ') . strtolower(BUDGET_AREAS[$area]) . ' budget for the week of ' . (new DateTimeImmutable($ws))->format('M j'), $r['id'],
    people_land(return_path(budget_url($site, $ws)), $cleared ? 'bd_cleared' : 'bd_saved', $r['id'] === null ? '' : 'budget-' . $r['id']), 'budgetChanged', ['area' => $area]);
