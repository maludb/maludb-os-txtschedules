<?php
/**
 * Team schedule (screen `team-schedule`): the published week for one restaurant by day (tabs), grouped by position.
 * Data: site (row), sites (held), week, prev, next, days [[date, dow, dom, count, isToday]], day, groups [position => [rows]], positions, positionId, me, zone
 * Published shifts only; never cost, phone or email. An open shift is a dashed row that leads to the marketplace.
 */
$url = static fn (array $over): string => '/team-schedule?' . http_build_query(array_filter(array_merge(['site' => $site['site_id'], 'week' => $week, 'day' => $day, 'position' => $positionId], $over), static fn ($v) => $v !== null && $v !== ''));
$here = here_url();
?>
<?= view('shared/header.php', ['id' => 'team-schedule', 'title' => 'Team schedule', 'crumbs' => [['Home', '/'], [$site['name'], null], ['Team schedule', null]]]) ?>
<div class="main-content" id="team-schedule-content">
    <div class="card mb-3" id="team-schedule-nav">
        <div class="card-body p-2">
            <div class="d-flex align-items-center gap-1 mb-2">
                <?= hx_link($url(['week' => $prev, 'day' => null]), '<i class="feather-chevron-left"></i>', 'btn btn-light btn-touch px-3', 'id="team-schedule-prev" aria-label="Previous week"') ?>
                <div class="d-flex flex-nowrap overflow-auto flex-grow-1 gap-1 week-chips" id="team-schedule-days">
                    <?php foreach ($days as [$date, $dow, $dom, $count, $isToday]): ?>
                        <?= hx_link($url(['day' => $date]), '<span class="fs-11 text-uppercase">' . e($dow) . '</span><span class="fw-bold d-block">' . e($dom) . '</span>' . ($count > 0 ? '<i class="chip-dot"></i>' : ''),
                            'week-chip text-center rounded' . ($isToday ? ' is-today' : '') . ($count > 0 ? ' works' : '') . ($date === $day ? ' selected' : ''), 'id="team-day-' . e($date) . '"') ?>
                    <?php endforeach; ?>
                </div>
                <?= hx_link($url(['week' => $next, 'day' => null]), '<i class="feather-chevron-right"></i>', 'btn btn-light btn-touch px-3', 'id="team-schedule-next" aria-label="Next week"') ?>
            </div>
            <form method="get" action="/team-schedule" class="d-flex gap-2 flex-wrap" id="team-schedule-filters" hx-get="/team-schedule" hx-target="#page-content" hx-trigger="change" hx-push-url="true">
                <input type="hidden" name="week" value="<?= e($week) ?>"><input type="hidden" name="day" value="<?= e($day) ?>">
                <?php if (count($sites) > 1): ?>
                    <select name="site" id="team-schedule-filter-site" class="form-select btn-touch w-auto" aria-label="Restaurant">
                        <?php foreach ($sites as $s): ?><option value="<?= (int) $s['scope_id'] ?>" <?= $s['scope_id'] === (int) $site['site_id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
                    </select>
                <?php else: ?><input type="hidden" name="site" value="<?= (int) $site['site_id'] ?>"><?php endif; ?>
                <select name="position" id="team-schedule-filter-position" class="form-select btn-touch w-auto" aria-label="Position">
                    <option value="">All positions</option>
                    <?php foreach ($positions as $p): ?><option value="<?= (int) $p['position_id'] ?>" <?= $positionId === (int) $p['position_id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
                </select>
                <noscript><button class="btn btn-light btn-touch" type="submit">Show</button></noscript>
            </form>
        </div>
    </div>
    <div class="fw-semibold mb-2" id="team-schedule-day-title"><?= e(local_dt($day . ' 12:00:00', 'UTC')->format('l, M j')) ?><?= $zone ? ' · ' . e(local_dt($day . ' 12:00:00', $site['timezone'])->format('T')) : '' ?></div>
    <?php if ($groups === []): ?>
        <div class="card" id="team-schedule-empty"><div class="card-body text-center text-muted py-4">Nobody is scheduled on this day yet.</div></div>
    <?php endif; ?>
    <?php foreach ($groups as $g): ?>
        <div class="card mb-3 team-group" id="team-group-<?= (int) $g['position_id'] ?>">
            <div class="card-header d-flex align-items-center gap-2"><?= pos_swatch($g['color'], 'dot') ?><h6 class="card-title mb-0"><?= e($g['name']) ?></h6></div>
            <ul class="list-group list-group-flush">
                <?php foreach ($g['rows'] as $r): $id = (int) $r['shift_id']; $mine = $r['assignee_member_id'] !== null && (int) $r['assignee_member_id'] === $me; ?>
                    <?php if ($r['is_open']): ?>
                        <li class="list-group-item open-row p-0" id="team-shift-<?= $id ?>">
                            <?= hx_link('/marketplace', 'Open · ' . e(shift_time_range($r['starts_at'], $r['ends_at'], $r['timezone'])) . ' — take it', 'd-block px-3 py-3 text-primary fw-semibold', 'id="team-shift-' . $id . '-open"') ?>
                        </li>
                    <?php else: ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center gap-2<?= $mine ? ' bg-soft-primary' : '' ?>" id="team-shift-<?= $id ?>">
                            <span class="fw-semibold"><?= hx_link(with_back('/shifts/' . $id, $here), e($r['assignee_name']), $mine ? 'text-primary' : 'text-dark') ?><?= $mine ? ' <span class="badge bg-primary ms-1">You</span>' : '' ?></span>
                            <span class="text-muted text-nowrap"><?= e(shift_time_range($r['starts_at'], $r['ends_at'], $r['timezone'])) ?></span>
                        </li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endforeach; ?>
</div>
