<?php
/**
 * My schedule (screen `my-schedule`). Data: view (week|list), shifts, days, week, prev, next, next_shift, back, notice, siteName, zone, showSite
 * Phone: the strip, then one card per shift under its day. From 992 px: the days as seven columns.
 */
$byDate = [];
foreach ($shifts as $s) {
    $byDate[local_dt($s['starts_at'], $s['timezone'])->format('Y-m-d')][] = $s;
}
$here = here_url();
$weekLabel = local_dt($week . ' 12:00:00', 'UTC')->format('M j') . ' – ' . local_dt($week . ' 12:00:00', 'UTC')->modify('+6 days')->format('M j');
?>
<?= view('shared/header.php', ['id' => 'my-schedule', 'title' => 'My schedule', 'crumbs' => [['Home', '/'], ['My schedule', null]]]) ?>
<div class="main-content" id="my-schedule-content">
    <?= view('shared/notice.php', ['notice' => $notice ?? null]) ?>
    <div class="d-flex justify-content-between align-items-center mb-2 gap-2 flex-wrap">
        <div class="d-flex align-items-center gap-1">
            <?php if ($view === 'week'): ?>
                <?= hx_link('/my-schedule?' . http_build_query(['week' => $prev, 'view' => 'week']), '<i class="feather-chevron-left"></i>', 'btn btn-light btn-touch px-3', 'id="my-schedule-prev" aria-label="Previous week"') ?>
                <div class="fw-semibold px-1 text-nowrap" id="my-schedule-range"><?= e($weekLabel) ?></div>
                <?= hx_link('/my-schedule?' . http_build_query(['week' => $next, 'view' => 'week']), '<i class="feather-chevron-right"></i>', 'btn btn-light btn-touch px-3', 'id="my-schedule-next" aria-label="Next week"') ?>
            <?php else: ?><div class="fw-semibold" id="my-schedule-range">Coming up</div><?php endif; ?>
        </div>
        <div class="btn-group" role="group" aria-label="View">
            <?= hx_link('/my-schedule?' . http_build_query(['week' => $week]), 'Week', 'btn btn-touch ' . ($view === 'week' ? 'btn-primary' : 'btn-light'), 'id="my-schedule-view-week"') ?>
            <?= hx_link('/my-schedule?view=list', 'List', 'btn btn-touch ' . ($view === 'list' ? 'btn-primary' : 'btn-light'), 'id="my-schedule-view-list"') ?>
        </div>
    </div>
    <?php if ($view === 'week'): ?>
        <?= view('schedule/partials/week-strip.php', ['days' => $days, 'week' => $week, 'prev' => $prev, 'next' => $next, 'view' => $view, 'base' => '/my-schedule']) ?>
    <?php endif; ?>
    <?php if ($shifts === []): ?>
        <div class="card" id="my-schedule-empty"><div class="card-body text-center py-4">
            <div class="avatar-text avatar-xl rounded mx-auto mb-3"><i class="feather-calendar"></i></div>
            <div class="fw-semibold"><?= $view === 'week' ? 'You have no shifts this week.' : 'You have no shifts coming up.' ?></div>
            <?php if ($next_shift !== null): ?>
                <div class="text-muted fs-12 mt-1" id="my-schedule-next-shift">Your next shift: <?= hx_link(with_back('/shifts/' . (int) $next_shift['shift_id'], $here), e(shift_when($next_shift['starts_at'], $next_shift['ends_at'], $next_shift['timezone'], $zone))) ?></div>
            <?php endif; ?>
        </div></div>
    <?php elseif ($view === 'list'): ?>
        <div id="my-schedule-list">
        <?php foreach ($shifts as $s) { echo view('schedule/partials/shift-card.php', ['s' => $s, 'back' => $here, 'showSite' => $showSite, 'zone' => $zone]); } ?>
        </div>
    <?php else: ?>
        <div class="week-grid" id="my-schedule-week">
            <?php foreach ($days as [$date, $dow, $dom, $works, $isToday]): ?>
                <div class="week-col<?= $works ? '' : ' d-none d-lg-block' ?>" id="my-day-<?= e($date) ?>">
                    <div class="fs-12 fw-semibold text-uppercase mb-1 <?= $isToday ? 'text-primary' : 'text-muted' ?>"><?= e($dow . ' ' . $dom) ?></div>
                    <?php foreach ($byDate[$date] ?? [] as $s) { echo view('schedule/partials/shift-card.php', ['s' => $s, 'back' => $here, 'showSite' => $showSite, 'zone' => $zone]); } ?>
                    <?php if (!$works): ?><div class="fs-12 text-muted d-none d-lg-block">Off</div><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
