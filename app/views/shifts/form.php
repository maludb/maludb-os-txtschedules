<?php
/**
 * Add / change a shift (screens `shift-add`, `shift-edit`). Data: site, snap (the shift when editing), v (values: position_id, date, starts, ends, break, assignee, note), positions, people, live, check, builder, edit
 * A draft week posts shift_create / shift_update; a published one shift_add / shift_change ("This is live: staff will be told.").
 */
$siteId = (int) $site['site_id'];
$action = $live ? ($edit ? '/shifts/change.php' : '/shifts/add.php') : '/shifts/save.php';
$title = $edit ? 'Change a shift' : 'Add a shift';
$hard = !isset($check['error']) && $check['findings']['hard'] !== [];
$saveLabel = $live ? ($edit ? 'Change it and tell staff' : 'Add it and tell staff') : 'Save the shift';
?>
<?= view('shared/header.php', ['id' => $edit ? 'shift-edit' : 'shift-add', 'title' => $title, 'crumbs' => [['Home', '/'], ['Builder', $builder], [$title, null]]]) ?>
<div class="main-content" id="<?= $edit ? 'shift-edit' : 'shift-add' ?>-content">
    <?php if ($live): ?><div class="alert alert-warning fs-12" id="shift-form-live-note" role="note"><i class="feather-radio me-1"></i>This is live: staff will be told.</div>
    <?php else: ?><div class="fs-12 text-muted mb-2" id="shift-form-draft-note">This week is a draft — staff see nothing until you publish it.</div><?php endif; ?>
    <form method="post" action="<?= e($action) ?>" hx-post="<?= e($action) ?>" hx-target="#flash" id="shift-form"<?= $live ? ' hx-confirm="This is live: staff will be told. Go ahead?"' : '' ?>>
        <?= csrf_field() ?>
        <input type="hidden" name="site" id="shift-form-field-site" value="<?= $siteId ?>">
        <input type="hidden" name="save_label" value="<?= e($saveLabel) ?>">
        <?php if ($edit): ?><input type="hidden" name="shift" value="<?= (int) $snap['shift_id'] ?>"><?php endif; ?>
        <div class="card mb-3"><div class="card-body">
            <label class="form-label fs-12 text-muted" for="shift-form-field-position">Position</label>
            <select name="position" id="shift-form-field-position" class="form-select btn-touch mb-3" required>
                <?php foreach ($positions as $p): ?><option value="<?= (int) $p['position_id'] ?>" <?= (int) $p['position_id'] === (int) $v['position_id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
            </select>
            <label class="form-label fs-12 text-muted" for="shift-form-field-date">Day (<?= e($site['timezone']) ?>)</label>
            <input type="date" name="date" id="shift-form-field-date" class="form-control btn-touch mb-3" value="<?= e($v['date']) ?>" required>
            <div class="row g-2 mb-3">
                <div class="col-6"><label class="form-label fs-12 text-muted" for="shift-form-field-starts">Starts</label>
                    <input type="time" name="starts" id="shift-form-field-starts" class="form-control btn-touch" step="900" value="<?= e($v['starts']) ?>" required></div>
                <div class="col-6"><label class="form-label fs-12 text-muted" for="shift-form-field-ends">Ends</label>
                    <input type="time" name="ends" id="shift-form-field-ends" class="form-control btn-touch" step="900" value="<?= e($v['ends']) ?>" required></div>
                <div class="col-12 fs-12 text-muted">An end at or before the start means the next day (up to 16 hours).</div>
            </div>
            <label class="form-label fs-12 text-muted" for="shift-form-field-break">Unpaid break</label>
            <select name="break" id="shift-form-field-break" class="form-select btn-touch mb-3">
                <?php foreach ([0, 15, 30, 45, 60] as $b): ?><option value="<?= $b ?>" <?= $b === (int) $v['break'] ? 'selected' : '' ?>><?= $b === 0 ? 'No break' : $b . ' minutes' ?></option><?php endforeach; ?>
            </select>
            <label class="form-label fs-12 text-muted" for="shift-form-field-assignee">Who</label>
            <select name="assignee" id="shift-form-field-assignee" class="form-select btn-touch mb-2">
                <option value="">Leave open</option>
                <?php foreach ($people as $p): ?><option value="<?= (int) $p['member_id'] ?>" <?= (int) $p['member_id'] === (int) $v['assignee'] ? 'selected' : '' ?>><?= e($p['display_name']) ?></option><?php endforeach; ?>
            </select>
            <div id="shift-form-check" hx-get="/shifts/check.php" hx-trigger="change from:#shift-form delay:150ms" hx-include="#shift-form" hx-target="this" hx-swap="innerHTML" hx-sync="this:replace"><?= view('shifts/partials/check.php', ['check' => $check, 'oob' => false, 'reason' => '']) ?></div>
            <label class="form-label fs-12 text-muted mt-2" for="shift-form-field-note">Note (optional)</label>
            <input type="text" name="note" id="shift-form-field-note" class="form-control btn-touch" maxlength="200" value="<?= e($v['note']) ?>">
        </div></div>
        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="shift-form-save-btn"<?= $hard ? ' disabled' : '' ?>><?= e($saveLabel) ?></button>
        <?= hx_link($builder, 'Cancel', 'btn btn-light btn-touch w-100 mb-3', 'id="shift-form-cancel-link"') ?>
    </form>
    <?php if ($edit && !$live): ?>
        <form method="post" action="/shifts/delete.php" hx-post="/shifts/delete.php" hx-target="#flash" hx-confirm="Delete this shift?" id="shift-delete-form">
            <?= csrf_field() ?><input type="hidden" name="shift" value="<?= (int) $snap['shift_id'] ?>">
            <button type="submit" class="btn btn-light btn-touch w-100 text-danger" id="shift-delete-btn"><i class="feather-trash-2 me-1"></i>Delete this draft shift</button>
        </form>
    <?php endif; ?>
</div>
