<?php
declare(strict_types=1);
/**
 * Action `week_autofill` (log `week.autofill`): fill the OPEN shifts of a DRAFT week from the restaurant's own staff — deterministic, never breaking a hard rule, preferring
 * preferred availability, then no soft warning, then the fewest hours, then the name. `template` seeds the week first; an empty week is seeded from the last published week.
 * It writes a draft (`shift.assign` rows, after.via = autofill) and answers a summary.
 */
require_once dirname(__DIR__, 2) . '/app/features/weeks/handler.php';
build_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$template = request_integer('template');
$out = build_guard($pdo, static function () use ($pdo, $me, $template): array {
    $pdo->beginTransaction();
    $week = resolve_week($pdo, $me);
    $r = autofill_week($pdo, $week['week_id'], $template, $me);
    log_activity($pdo, 'week.autofill', 'week', $week['week_id'], ['scope_id' => $week['site_id'],
        'after' => ['week_id' => $week['week_id'], 'filled' => count($r['filled']), 'open' => count($r['still_open']), 'warnings' => $r['warnings'], 'seeded' => $r['seeded']['from'] ?? null]]);
    $pdo->commit();
    return ['week' => $week, 'r' => $r];
});
$w = $out['week'];
$r = $out['r'];
remember_result(['kind' => 'autofill', 'site_id' => $w['site_id'], 'week_start' => $w['week_start']] + $r);
build_done('Filled ' . count($r['filled']) . ' open shift' . (count($r['filled']) === 1 ? '' : 's') . '; ' . count($r['still_open']) . ' still open', $w['week_id'], builder_url($w['site_id'], $w['week_start']),
    ['week_id' => $w['week_id'], 'filled' => $r['filled'], 'still_open' => $r['still_open'], 'warnings' => $r['warnings'], 'hours' => $r['hours'], 'seeded' => $r['seeded']]);
