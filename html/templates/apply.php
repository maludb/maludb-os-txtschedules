<?php
declare(strict_types=1);
/** Action `template_apply` (log `template.apply`): start a DRAFT week from a template — each shift on its weekday; one whose person would break a hard rule or has time off is left open and listed. A published week is 422. */
require_once dirname(__DIR__, 2) . '/app/features/weeks/handler.php';
build_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$tplId = request_integer('template') ?? request_integer('template_id') ?? refuse(422, 'Say which template.');
$tplSite = require_record_site(template_site_id($pdo, $tplId), 'That template is not here any more.');
require_right('schedule.build', $tplSite);
$out = build_guard($pdo, static function () use ($pdo, $me, $tplId): array {
    $pdo->beginTransaction();
    $week = resolve_week($pdo, $me);
    $r = apply_template($pdo, $tplId, $week['week_id'], $me);
    log_activity($pdo, 'template.apply', 'template', $tplId, ['scope_id' => $week['site_id'], 'after' => ['template_id' => $tplId, 'week_id' => $week['week_id'], 'placed' => $r['placed'], 'left_open' => count($r['left_open'])]]);
    $pdo->commit();
    return ['week' => $week, 'r' => $r];
});
$w = $out['week'];
$r = $out['r'];
remember_result(['kind' => 'template', 'site_id' => $w['site_id'], 'week_start' => $w['week_start']] + $r);
build_done('Placed ' . $r['placed'] . ' shift' . ($r['placed'] === 1 ? '' : 's') . ' in the week of ' . week_label($w['week_start']) . (count($r['left_open']) > 0 ? ' — ' . count($r['left_open']) . ' left open' : ''),
    $w['week_id'], builder_url($w['site_id'], $w['week_start']), ['week_id' => $w['week_id'], 'placed' => $r['placed'], 'left_open' => $r['left_open'], 'existing' => $r['existing']]);
