<?php
declare(strict_types=1);
/** Action `week_clear` (log `week.clear`): remove the shifts of a DRAFT week — the way back from a copy, a template or an auto-fill. A published week is 422. */
require_once dirname(__DIR__, 2) . '/app/features/weeks/handler.php';
build_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$out = build_guard($pdo, static function () use ($pdo, $me): array {
    $pdo->beginTransaction();
    $week = resolve_week($pdo, $me, false);
    $n = clear_week($pdo, $week['week_id'], $me);
    log_activity($pdo, 'week.clear', 'week', $week['week_id'], ['scope_id' => $week['site_id'], 'after' => ['week_id' => $week['week_id'], 'removed' => $n]]);
    $pdo->commit();
    return ['week' => $week, 'n' => $n];
});
$w = $out['week'];
build_done('Cleared ' . $out['n'] . ' shift' . ($out['n'] === 1 ? '' : 's') . ' from the week of ' . week_label($w['week_start']), $w['week_id'], builder_url($w['site_id'], $w['week_start']), ['week_id' => $w['week_id'], 'removed' => $out['n']]);
