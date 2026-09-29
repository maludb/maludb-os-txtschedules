<?php
declare(strict_types=1);
/**
 * Action `rule_save` (log `rule.update`): settings.manage at the restaurant sets one rule — its severity (hard, soft, off) and its values (`params`: an array or JSON, or the value names sent flat). A value left out
 * stays. Values are checked per kind: a number in its range (whole where the engine needs it), a time as HH:MM. The engine reads the row on its very next question.
 */
require_once dirname(__DIR__, 2) . '/app/features/site/handler.php';
people_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$site = settings_gate_site();
$key = req_val('rule', 'rule_key') ?? '';
if ($key === '') {
    refuse(422, 'Say which rule.');
}
$sev = req_has('severity') ? strtolower((string) req_val('severity')) : null;
$given = [];
$p = $_POST['params'] ?? $_GET['params'] ?? null;
if (is_string($p) && trim($p) !== '') {
    $p = json_decode($p, true);
    if (!is_array($p)) {
        refuse(422, 'The values are a JSON object like {"hours": 10}.');
    }
}
if (is_array($p)) {
    $given = $p;
}
foreach (array_keys(RULE_PARAMS[$key] ?? []) as $name) {                      // the flat form: hours=10, minutes=30, time=22:00
    if (!array_key_exists($name, $given) && req_has($name)) {
        $given[$name] = (string) req_val($name);
    }
}
$r = people_guard($pdo, static function () use ($pdo, $me, $site, $key, $sev, $given): array {
    $pdo->beginTransaction();
    $r = save_rule($pdo, $site, $key, $sev, $given, $me);
    if ($r['changed']) {
        log_activity($pdo, 'rule.update', 'site_rule', null, ['scope_id' => $site, 'before' => ['rule' => $key] + ($r['before'] ?? ['severity' => 'off', 'params' => []]), 'after' => ['rule' => $key] + $r['after']]);
    }
    $pdo->commit();
    return $r;
});
$name = rule_kind_name($pdo, $key) ?? $key;
people_done($r['changed'] ? 'Saved the ' . strtolower($name) . ' rule (' . $r['after']['severity'] . ')' : 'Nothing changed', null,
    people_land(return_path(rules_url($site)), $r['changed'] ? 'rl_saved' : 'rl_unchanged', 'rule-' . $key), 'ruleChanged', ['rule' => $key, 'severity' => $r['after']['severity'], 'params' => $r['after']['params'], 'changed' => $r['changed']]);
