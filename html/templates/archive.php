<?php
declare(strict_types=1);
/** Action `template_archive` (log `template.archive`): a template leaves the list (kept, never deleted). */
require_once dirname(__DIR__, 2) . '/app/features/weeks/handler.php';
build_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$tplId = request_integer('template') ?? request_integer('template_id') ?? refuse(422, 'Say which template.');
$siteId = require_record_site(template_site_id($pdo, $tplId), 'That template is not here any more.');
require_right('schedule.build', $siteId);
build_guard($pdo, static function () use ($pdo, $tplId, $siteId): void {
    $pdo->beginTransaction();
    archive_template($pdo, $tplId, $siteId);
    log_activity($pdo, 'template.archive', 'template', $tplId, ['scope_id' => $siteId, 'after' => ['template_id' => $tplId]]);
    $pdo->commit();
});
build_done('Archived the template', $tplId, '/templates/?site=' . $siteId, ['template_id' => $tplId]);
