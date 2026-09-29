<?php
declare(strict_types=1);
/** Action `rule_preset_apply` (log `rule.preset`): settings.manage at the restaurant puts every rule the preset names back to its starting values. The Generic preset has no jurisdiction's law behind it (fair workweek is not offered, D8). */
require_once dirname(__DIR__, 2) . '/app/features/site/handler.php';
people_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$site = settings_gate_site();
$preset = strtolower(req_val('preset') ?? 'generic');
$r = people_guard($pdo, static function () use ($pdo, $me, $site, $preset): array {
    $pdo->beginTransaction();
    $r = apply_preset($pdo, $site, $preset, $me);
    log_activity($pdo, 'rule.preset', 'site_rules', null, ['scope_id' => $site, 'after' => ['preset' => $r['preset'], 'rules_changed' => $r['rules_changed'], 'rules' => $r['keys']]]);
    $pdo->commit();
    return $r;
});
people_done('Applied the ' . $r['name'] . ' preset (' . $r['rules_changed'] . ' rule' . ($r['rules_changed'] === 1 ? '' : 's') . ' changed)', null, people_land(return_path(rules_url($site)), 'rl_preset'), 'ruleChanged',
    ['preset' => $r['preset'], 'rules_changed' => $r['rules_changed']]);
