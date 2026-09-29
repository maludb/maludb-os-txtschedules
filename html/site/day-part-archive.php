<?php
declare(strict_types=1);
/** Action `day_part_archive` (log `day_part.archive`): settings.manage at the day-part's restaurant archives it — it leaves the forecast and its pickers, and the covers already forecast for it are kept. */
require_once dirname(__DIR__, 2) . '/app/features/site/handler.php';
people_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('day_part') ?? request_integer('day_part_id') ?? refuse(422, 'Say which day-part.');
$row = day_part_row($pdo, $id);
$site = people_record_site($row === null ? null : (int) $row['scope_id'], 'That day-part is not here.');
require_right('settings.manage', $site);
$before = people_guard($pdo, static function () use ($pdo, $me, $site, $id): array {
    $pdo->beginTransaction();
    $b = archive_day_part($pdo, $id, $me);
    log_activity($pdo, 'day_part.archive', 'day_part', $id, ['scope_id' => $site, 'before' => ['name' => $b['name'], 'starts_at' => $b['starts_at'], 'ends_at' => $b['ends_at'], 'service_name' => $b['service_name']], 'after' => ['archived' => true]]);
    $pdo->commit();
    return $b;
});
people_done('Archived ' . $before['name'], $id, people_land(return_path(day_parts_url($site)), 'dp_archived'), 'dayPartChanged', ['day_part_id' => $id]);
