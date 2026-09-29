<?php
declare(strict_types=1);
/**
 * Action `week_copy` (log `week.copy`): fill a DRAFT week from another (last week by default in the screen): each shift lands on the same weekday; one whose person would
 * break a hard rule, has time off or already has a shift is left OPEN and listed. Never onto a published week; never over a draft shift already there.
 */
require_once dirname(__DIR__, 2) . '/app/features/weeks/handler.php';
build_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$from = request_integer('from_week') ?? request_integer('from') ?? refuse(422, 'Say which week to copy (from_week).');
$out = build_guard($pdo, static function () use ($pdo, $me, $from): array {
    $pdo->beginTransaction();
    $week = resolve_week($pdo, $me);
    $r = copy_week($pdo, $from, $week['week_id'], $me);
    log_activity($pdo, 'week.copy', 'week', $week['week_id'], ['scope_id' => $week['site_id'],
        'after' => ['week_id' => $week['week_id'], 'from_week' => $from, 'placed' => $r['placed'], 'left_open' => count($r['left_open'])]]);
    $pdo->commit();
    return ['week' => $week, 'r' => $r];
});
$w = $out['week'];
$r = $out['r'];
remember_result(['kind' => 'copy', 'site_id' => $w['site_id'], 'week_start' => $w['week_start']] + $r);
build_done('Copied ' . $r['placed'] . ' shift' . ($r['placed'] === 1 ? '' : 's') . ' to the week of ' . week_label($w['week_start']) . (count($r['left_open']) > 0 ? ' — ' . count($r['left_open']) . ' left open' : ''),
    $w['week_id'], builder_url($w['site_id'], $w['week_start']), ['week_id' => $w['week_id'], 'placed' => $r['placed'], 'left_open' => $r['left_open'], 'existing' => $r['existing']]);
