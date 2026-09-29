<?php
/** Time-off types and blackout dates (screen `time-off-types`). Data: site, types, blackouts, editing, adding, notice, sites */
$siteId = (int) $site['site_id'];
$here = '/site/time-off?site=' . $siteId;
$flag = static fn (bool $on, string $word): string => $on ? '<span class="badge bg-soft-primary text-primary me-1">' . e($word) . '</span>' : '';
?>
<?= view('shared/header.php', ['id' => 'time-off-types', 'title' => 'Time-off types', 'crumbs' => [['Home', '/'], ['Time off', '/time-off'], ['Types and blackout dates', null]]]) ?>
<div class="main-content" id="time-off-types-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if (count($sites) > 1): ?>
        <div class="d-flex flex-wrap gap-1 mb-3" id="time-off-types-sites">
            <?php foreach ($sites as $s): ?><?= hx_link('/site/time-off?site=' . (int) $s['scope_id'], e($s['name']), 'btn btn-touch ' . ((int) $s['scope_id'] === $siteId ? 'btn-primary' : 'btn-light'), 'id="time-off-types-site-' . (int) $s['scope_id'] . '"') ?><?php endforeach; ?>
        </div>
    <?php endif; ?>
    <div class="fs-12 text-muted mb-3" id="time-off-types-day-hours"><?= e($site['name']) ?> counts a day off as <?= e(hours_label($site['time_off_day_hours'])) ?> when a request gives no hours.</div>

    <div class="d-flex justify-content-between align-items-center mb-2"><h6 class="text-muted text-uppercase fs-11 mb-0">Kinds of time off</h6>
        <?= hx_link($here . '&add=type', '<i class="feather-plus me-1"></i>Add a kind', 'btn btn-light btn-touch', 'id="time-off-types-add-btn"') ?></div>
    <?php if ($adding || $editing !== null): $t = $editing; ?>
        <form method="post" action="/time-off/types/save.php" hx-post="/time-off/types/save.php" hx-target="#flash" class="card mb-3" id="time-off-type-form">
            <?= csrf_field() ?><input type="hidden" name="site" value="<?= $siteId ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
            <?php if ($t !== null): ?><input type="hidden" name="time_off_type" value="<?= (int) $t['type_id'] ?>"><?php endif; ?>
            <div class="card-header"><h5 class="card-title mb-0"><?= $t !== null ? 'Change ' . e($t['name']) : 'Add a kind of time off' ?></h5></div>
            <div class="card-body">
                <label class="form-label fs-12 text-muted" for="time-off-type-form-field-name">Name</label>
                <input type="text" name="name" id="time-off-type-form-field-name" class="form-control btn-touch mb-3" maxlength="60" required value="<?= e($t['name'] ?? '') ?>">
                <?php foreach (['paid' => ['Paid', 'paid_yes'], 'tracks_balance' => ['Keeps a balance in hours', 'tracks_yes'], 'allow_negative' => ['May go below zero', 'negative_yes']] as $field => [$label, $idp]): ?>
                    <input type="hidden" name="<?= e($field) ?>" value="no">
                    <label class="d-flex align-items-center gap-2 border rounded px-3 mb-2 btn-touch" for="time-off-type-form-field-<?= e($field) ?>"><input type="checkbox" class="form-check-input mt-0" name="<?= e($field) ?>" value="yes" id="time-off-type-form-field-<?= e($field) ?>" <?= !empty($t[$field]) ? 'checked' : '' ?>><?= e($label) ?></label>
                <?php endforeach; ?>
                <label class="form-label fs-12 text-muted mt-2" for="time-off-type-form-field-order">Order in the list</label>
                <input type="number" name="sort_order" id="time-off-type-form-field-order" class="form-control btn-touch mb-3" min="0" max="1000" value="<?= (int) ($t['sort_order'] ?? count($types) + 1) ?>">
                <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="time-off-type-form-save-btn">Save</button>
                <?= hx_link($here, 'Cancel', 'btn btn-light btn-touch w-100', 'id="time-off-type-form-cancel-link"') ?>
            </div>
        </form>
    <?php endif; ?>
    <div class="row g-3 mb-3" id="time-off-types-list">
        <?php foreach ($types as $t): $tid = (int) $t['type_id']; ?>
            <div class="col-12 col-md-6 col-xl-4">
                <div class="card h-100" id="type-<?= $tid ?>"><div class="card-body p-3">
                    <div class="fw-semibold" id="type-<?= $tid ?>-name"><?= e($t['name']) ?><?= $t['archived_at'] !== null ? ' <span class="badge bg-soft-secondary text-secondary">Archived</span>' : '' ?></div>
                    <div class="mt-1" id="type-<?= $tid ?>-flags"><?= $flag($t['paid'], 'Paid') ?><?= $flag(!$t['paid'], 'Unpaid') ?><?= $flag($t['tracks_balance'], 'Keeps a balance') ?><?= $flag($t['allow_negative'], 'May go below zero') ?></div>
                    <?php if ($t['archived_at'] === null): ?>
                        <div class="row g-2 mt-2">
                            <div class="col-6"><?= hx_link($here . '&edit=' . $tid, 'Change', 'btn btn-light btn-touch w-100', 'id="type-' . $tid . '-edit-btn"') ?></div>
                            <div class="col-6"><form method="post" action="/time-off/types/archive.php" hx-post="/time-off/types/archive.php" hx-target="#flash" hx-confirm="Archive <?= e($t['name']) ?>? It stays on old requests and leaves the picker.">
                                <?= csrf_field() ?><input type="hidden" name="time_off_type" value="<?= $tid ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                                <button type="submit" class="btn btn-light btn-touch w-100" id="type-<?= $tid ?>-archive-btn">Archive</button></form></div>
                        </div>
                    <?php endif; ?>
                </div></div>
            </div>
        <?php endforeach; ?>
    </div>

    <h6 class="text-muted text-uppercase fs-11">Blackout dates</h6>
    <div class="card mb-3" id="blackouts">
        <ul class="list-group list-group-flush">
            <?php if ($blackouts === []): ?><li class="list-group-item text-muted" id="blackouts-empty">No dates are blacked out.</li><?php endif; ?>
            <?php foreach ($blackouts as $b): $bid = (int) $b['blackout_id']; ?>
                <li class="list-group-item d-flex justify-content-between align-items-center gap-2" id="blackout-<?= $bid ?>">
                    <div><div class="fw-semibold"><?= e(format_date($b['on_date'])) ?></div><div class="fs-12 text-muted"><?= e($b['reason']) ?></div></div>
                    <form method="post" action="/time-off/blackout/remove.php" hx-post="/time-off/blackout/remove.php" hx-target="#flash" hx-confirm="Open <?= e(format_date($b['on_date'])) ?> again?">
                        <?= csrf_field() ?><input type="hidden" name="blackout" value="<?= $bid ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                        <button type="submit" class="btn btn-light btn-touch px-3" id="blackout-<?= $bid ?>-remove-btn" aria-label="Remove <?= e(format_date($b['on_date'])) ?>"><i class="feather-trash-2"></i></button></form>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <form method="post" action="/time-off/blackout/save.php" hx-post="/time-off/blackout/save.php" hx-target="#flash" class="card mb-3" id="blackout-form">
        <?= csrf_field() ?><input type="hidden" name="site" value="<?= $siteId ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
        <div class="card-header"><h5 class="card-title mb-0">Black out a date</h5></div>
        <div class="card-body">
            <label class="form-label fs-12 text-muted" for="blackout-form-field-date">Date</label>
            <input type="date" name="on_date" id="blackout-form-field-date" class="form-control btn-touch mb-3" required>
            <label class="form-label fs-12 text-muted" for="blackout-form-field-reason">Reason</label>
            <input type="text" name="reason" id="blackout-form-field-reason" class="form-control btn-touch mb-3" maxlength="200" required placeholder="Valentine's Day">
            <button type="submit" class="btn btn-primary btn-touch w-100" id="blackout-form-save-btn">Black it out</button>
            <div class="fs-12 text-muted mt-2">Time off already asked for stays as it is; new requests touching this date are refused.</div>
        </div>
    </form>
</div>
