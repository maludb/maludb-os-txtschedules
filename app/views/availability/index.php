<?php
/**
 * Availability (screen `availability`). Data: member, me, name, effective, pending, may, add, today, siteFilter, siteChoices, people, notice, multi, needsApproval
 * Phone: the week as seven day cards, one column; from 992 px two. A block is a row in its day's card; "+ Add" opens the form at the top.
 */
$own = $member === $me;
$here = '/availability?member=' . $member . ($siteFilter !== null ? '&site=' . $siteFilter : '');
$byDay = array_fill(0, 7, []);
foreach ($effective as $b) {
    $byDay[$b['weekday']][] = $b;
}
$addUrl = static fn (int $d): string => $here . '&add=' . $d;
?>
<?= view('shared/header.php', ['id' => 'availability', 'title' => $own ? 'Availability' : 'Availability — ' . $name, 'crumbs' => $own ? [['Home', '/'], ['Availability', null]] : [['Home', '/'], ['Availability', '/availability'], [$name, null]]]) ?>
<div class="main-content" id="availability-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if (!$own): ?>
        <div class="alert alert-info fs-12" id="availability-other-note"><i class="feather-user me-1"></i>You are looking at <?= e($name) ?>'s availability. <?= hx_link('/availability', 'Back to mine', 'alert-link', 'id="availability-mine-link"') ?></div>
    <?php endif; ?>
    <?php if ($people !== []): ?>
        <form method="get" action="/availability" class="card mb-3" id="availability-person-form"><div class="card-body p-3">
            <label class="form-label fs-12 text-muted" for="availability-person-field-member">Whose availability</label>
            <div class="d-flex gap-2">
                <select name="member" id="availability-person-field-member" class="form-select btn-touch">
                    <option value="<?= (int) $me ?>">Mine</option>
                    <?php foreach ($people as $p): if ($p['member_id'] === $me) { continue; } ?><option value="<?= (int) $p['member_id'] ?>" <?= $p['member_id'] === $member ? 'selected' : '' ?>><?= e($p['display_name']) ?></option><?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-light btn-touch" id="availability-person-btn">Show</button>
            </div>
        </div></form>
    <?php endif; ?>

    <?php if ($add !== null): ?>
        <form method="post" action="/availability/save.php" hx-post="/availability/save.php" hx-target="#flash" class="card mb-3" id="availability-form">
            <?= csrf_field() ?><input type="hidden" name="member" value="<?= $member ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
            <div class="card-header"><h5 class="card-title mb-0">Add a block<?= $own ? '' : ' for ' . e($name) ?></h5></div>
            <div class="card-body">
                <label class="form-label fs-12 text-muted" for="availability-form-field-weekday">Day</label>
                <select name="weekday" id="availability-form-field-weekday" class="form-select btn-touch mb-3">
                    <?php foreach (WEEKDAY_NAMES as $i => $dn): ?><option value="<?= $i ?>" <?= $i === $add ? 'selected' : '' ?>><?= e($dn) ?></option><?php endforeach; ?>
                </select>
                <label class="form-label fs-12 text-muted" for="availability-form-field-kind">This is a time I am</label>
                <select name="kind" id="availability-form-field-kind" class="form-select btn-touch mb-3">
                    <?php foreach (AVAIL_KINDS as $k => $l): ?><option value="<?= e($k) ?>" <?= $k === 'unavailable' ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
                </select>
                <div class="row g-2 mb-1">
                    <div class="col-6"><label class="form-label fs-12 text-muted" for="availability-form-field-starts">From</label>
                        <input type="time" name="starts_at" id="availability-form-field-starts" class="form-control btn-touch" step="900"></div>
                    <div class="col-6"><label class="form-label fs-12 text-muted" for="availability-form-field-ends">To</label>
                        <input type="time" name="ends_at" id="availability-form-field-ends" class="form-control btn-touch" step="900"></div>
                </div>
                <div class="fs-12 text-muted mb-3">Leave both empty for the whole day. An end before the start runs past midnight.</div>
                <div class="row g-2 mb-3">
                    <div class="col-6"><label class="form-label fs-12 text-muted" for="availability-form-field-from">Starting</label>
                        <input type="date" name="effective_from" id="availability-form-field-from" class="form-control btn-touch" value="<?= e($today) ?>"></div>
                    <div class="col-6"><label class="form-label fs-12 text-muted" for="availability-form-field-to">Until (optional)</label>
                        <input type="date" name="effective_to" id="availability-form-field-to" class="form-control btn-touch"></div>
                </div>
                <?php if (count($siteChoices) > 1): ?>
                    <label class="form-label fs-12 text-muted" for="availability-form-field-site">Restaurant</label>
                    <select name="site" id="availability-form-field-site" class="form-select btn-touch mb-3">
                        <option value="">Every restaurant <?= $own ? 'I work at' : 'they work at' ?></option>
                        <?php foreach ($siteChoices as $s): ?><option value="<?= (int) $s['scope_id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?>
                    </select>
                <?php endif; ?>
                <div class="fs-12 text-muted mb-3" id="availability-form-approval-note"><?= $needsApproval && $own ? 'A manager looks at a change before it counts.' : 'It counts as soon as you save it.' ?></div>
                <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="availability-form-save-btn">Save the block</button>
                <?= hx_link($here, 'Cancel', 'btn btn-light btn-touch w-100', 'id="availability-form-cancel-link"') ?>
            </div>
        </form>
    <?php endif; ?>

    <?php if ($pending !== []): ?>
        <div class="card mb-3" id="availability-pending">
            <div class="card-header"><h5 class="card-title mb-0">Waiting for a manager</h5></div>
            <div class="card-body pt-0">
                <?php foreach ($pending as $b): ?>
                    <div class="fs-11 text-uppercase text-muted mt-2"><?= e(WEEKDAY_NAMES[$b['weekday']]) ?></div>
                    <?= view('availability/partials/block.php', ['b' => $b, 'mode' => 'pending', 'may' => $may[$b['availability_id']] ?? [], 'back' => $here, 'multi' => $multi]) ?>
                    <?php if (!$own && !empty($may[$b['availability_id']]['decide'])): ?>
                        <div class="fs-12"><?= hx_link('/approvals?kind=availability', 'Decide it in Approvals', 'fw-semibold', 'id="availability-block-' . (int) $b['availability_id'] . '-decide-link"') ?></div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="row g-3" id="availability-week">
        <?php foreach (WEEKDAY_NAMES as $i => $dn): ?>
            <div class="col-12 col-lg-6">
                <div class="card h-100" id="availability-day-<?= $i ?>">
                    <div class="card-header d-flex justify-content-between align-items-center gap-2">
                        <h5 class="card-title mb-0"><?= e($dn) ?></h5>
                        <?= hx_link($addUrl($i), '<i class="feather-plus me-1"></i>Add', 'btn btn-light btn-touch', 'id="availability-day-' . $i . '-add-btn"') ?>
                    </div>
                    <div class="card-body pt-0">
                        <?php if ($byDay[$i] === []): ?><div class="text-muted fs-12 pt-3" id="availability-day-<?= $i ?>-empty">Nothing set — you are open to be scheduled.</div><?php endif; ?>
                        <?php foreach ($byDay[$i] as $b) { echo view('availability/partials/block.php', ['b' => $b, 'mode' => 'day', 'may' => $may[$b['availability_id']] ?? [], 'back' => $here, 'multi' => $multi]); } ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="fs-12 text-muted mt-3" id="availability-note">Unavailable and preferred times guide the schedule: the builder warns before it puts you on an unavailable time. Nothing here stops a manager from scheduling you.</div>
</div>
