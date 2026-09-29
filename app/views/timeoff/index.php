<?php
/** Time off (screen `time-off`). Data: rows, view (mine|all|person|day), who, status, from, to, on, page, more, query, isApprover, me, zone, notice, canAdmin, site */
$states = ['' => 'All', 'pending' => 'Waiting', 'approved' => 'Approved', 'declined' => 'Declined', 'cancelled' => 'Cancelled'];
$q = static fn (array $over): string => '/time-off' . (($p = http_build_query(array_filter($over + $query, static fn ($v) => $v !== null && $v !== ''))) !== '' ? '?' . $p : '');
$here = $q(['page' => $page > 1 ? $page : null]);
$mine = $view === 'mine';
$title = $view === 'day' ? 'Who is off' : ($mine ? 'My time off' : 'Time off');
?>
<?= view('shared/header.php', ['id' => 'time-off', 'title' => $title, 'crumbs' => [['Home', '/'], ['Time off', $mine ? null : '/time-off'], ...($mine ? [] : [[$view === 'day' ? 'Who is off' : ($view === 'all' ? 'Everyone' : 'One person'), null]])]]) ?>
<div class="main-content" id="time-off-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="row g-2 mb-3" id="time-off-actions">
        <div class="col-12 col-sm-6 col-lg-3"><?= hx_link('/time-off/new', '<i class="feather-plus me-1"></i>Ask for time off', 'btn btn-primary btn-touch w-100', 'id="time-off-new-btn"') ?></div>
        <div class="col-6 col-sm-3 col-lg-2"><?= hx_link('/time-off/balances', 'Balances', 'btn btn-light btn-touch w-100', 'id="time-off-balances-link"') ?></div>
        <?php if ($canAdmin): ?><div class="col-6 col-sm-3 col-lg-3"><?= hx_link('/site/time-off?site=' . $site, 'Types and blackout dates', 'btn btn-light btn-touch w-100', 'id="time-off-types-link"') ?></div><?php endif; ?>
    </div>
    <?php if ($isApprover): ?>
        <div class="btn-group w-100 mb-3" role="group" aria-label="Whose" id="time-off-scope">
            <?= hx_link('/time-off', 'Mine', 'btn btn-touch ' . ($mine ? 'btn-primary' : 'btn-light'), 'id="time-off-scope-mine"') ?>
            <?= hx_link('/time-off?member=all&site=' . $site, 'Everyone', 'btn btn-touch ' . ($view === 'all' ? 'btn-primary' : 'btn-light'), 'id="time-off-scope-all"') ?>
        </div>
        <form method="get" action="/time-off" class="card mb-3" id="time-off-on-form"><div class="card-body p-3">
            <label class="form-label fs-12 text-muted" for="time-off-on-field-on">Who is off on a date</label>
            <input type="hidden" name="site" value="<?= (int) $site ?>">
            <div class="d-flex gap-2"><input type="date" name="on" id="time-off-on-field-on" class="form-control btn-touch" value="<?= e($on ?? '') ?>" required><button type="submit" class="btn btn-light btn-touch" id="time-off-on-btn">Show</button></div>
        </div></form>
    <?php endif; ?>
    <?php if ($view !== 'day'): ?>
        <div class="d-flex flex-wrap gap-1 mb-3" id="time-off-status-chips">
            <?php foreach ($states as $k => $l): ?><?= hx_link($q(['status' => $k === '' ? null : $k, 'page' => null]), e($l), 'btn btn-touch ' . (($status ?? '') === $k ? 'btn-primary' : 'btn-light'), 'id="time-off-status-' . ($k === '' ? 'all' : e($k)) . '"') ?><?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="fs-12 text-muted mb-2" id="time-off-day-heading">Approved time off on <?= e(format_date($on)) ?>:</div>
    <?php endif; ?>
    <?php if ($rows === []): ?>
        <div class="card" id="time-off-empty"><div class="card-body text-center py-4">
            <div class="avatar-text avatar-xl rounded mx-auto mb-3"><i class="feather-sun"></i></div>
            <div class="fw-semibold"><?= $view === 'day' ? 'Nobody is off that day.' : 'No time off here yet.' ?></div>
            <?php if ($mine): ?><div class="text-muted fs-12 mt-1">Ask for time off and a manager will decide.</div><?php endif; ?>
        </div></div>
    <?php endif; ?>
    <?php foreach ($rows as $r) { echo view('timeoff/partials/request-card.php', ['r' => $r, 'mode' => 'summary', 'back' => $here, 'zone' => $zone, 'showMember' => !$mine || $view === 'day']); } ?>
    <div class="d-flex justify-content-between gap-2 mb-3" id="time-off-pager">
        <?php if ($page > 1): ?><?= hx_link($q(['page' => $page > 2 ? $page - 1 : null]), '<i class="feather-chevron-left me-1"></i>Newer', 'btn btn-light btn-touch', 'id="time-off-newer"') ?><?php else: ?><span></span><?php endif; ?>
        <?php if ($more): ?><?= hx_link($q(['page' => $page + 1]), 'Older<i class="feather-chevron-right ms-1"></i>', 'btn btn-light btn-touch', 'id="time-off-older"') ?><?php endif; ?>
    </div>
</div>
