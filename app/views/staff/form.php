<?php
/** Change a person's profile (screen `staff-edit`). Data: cur, manage, works, names, byPos, back, today. Pay is not on this form. */
$id = (int) $cur['member_id'];
$has = [];
$primary = null;
foreach ($cur['positions'] as $p) { $has[$p['position_id']] = true; if ($p['is_primary']) { $primary = $p['position_id']; } }
$mainOptions = $manage;
if ($cur['main_site_id'] !== null && !in_array($cur['main_site_id'], $mainOptions, true) && in_array($cur['main_site_id'], $works, true)) { $mainOptions[] = $cur['main_site_id']; }
?>
<?= view('shared/header.php', ['id' => 'staff-edit', 'title' => 'Change ' . $cur['display_name'], 'back' => $back, 'crumbs' => [['Home', '/'], ['Staff', '/staff/'], [$cur['display_name'], '/staff/' . $id], ['Change', null]]]) ?>
<div class="main-content" id="staff-edit-content">
    <form method="post" action="/staff/save.php" hx-post="/staff/save.php" hx-target="#flash" id="staff-edit-form">
        <?= csrf_field() ?><input type="hidden" name="member" value="<?= $id ?>"><input type="hidden" name="return_to" value="/staff/<?= $id ?>">
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Restaurant and hours</h5></div><div class="card-body">
            <label class="form-label fs-12 text-muted" for="staff-edit-form-field-main">Main restaurant</label>
            <select name="main_site" id="staff-edit-form-field-main" class="form-select btn-touch">
                <?php foreach ($mainOptions as $s): ?><option value="<?= (int) $s ?>" <?= $cur['main_site_id'] === $s ? 'selected' : '' ?>><?= e($names[$s] ?? 'Restaurant') ?></option><?php endforeach; ?>
            </select>
            <div class="fs-12 text-muted mb-3">They can pick up shifts only here.</div>
            <label class="form-label fs-12 text-muted" for="staff-edit-form-field-hours">Most hours a week</label>
            <input type="text" inputmode="decimal" name="max_hours_week" id="staff-edit-form-field-hours" class="form-control btn-touch mb-1" maxlength="6" value="<?= $cur['max_hours_week'] !== null ? e((string) $cur['max_hours_week']) : '' ?>" placeholder="Empty: no limit">
            <div class="fs-12 text-muted mb-3">The builder warns when a week goes past it.</div>
            <input type="hidden" name="active" value="no">
            <label class="d-flex align-items-center gap-2 border rounded px-3 mb-2 btn-touch" for="staff-edit-form-field-active"><input type="checkbox" class="form-check-input mt-0" name="active" value="yes" id="staff-edit-form-field-active" <?= $cur['active'] ? 'checked' : '' ?>>On the schedule</label>
            <div class="fs-12 text-muted">Off means they leave the schedule but stay in the directory.</div>
        </div></div>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Minor</h5></div><div class="card-body">
            <input type="hidden" name="is_minor" value="no">
            <label class="d-flex align-items-center gap-2 border rounded px-3 mb-2 btn-touch" for="staff-edit-form-field-minor"><input type="checkbox" class="form-check-input mt-0" name="is_minor" value="yes" id="staff-edit-form-field-minor" <?= $cur['is_minor'] ? 'checked' : '' ?>>Is a minor</label>
            <label class="form-label fs-12 text-muted" for="staff-edit-form-field-until">The minor rules stop applying on</label>
            <input type="date" name="minor_until" id="staff-edit-form-field-until" class="form-control btn-touch" min="<?= e($today) ?>" value="<?= e($cur['minor_until'] ?? '') ?>">
            <div class="fs-12 text-muted mt-1">No date of birth is kept — only this end date.</div>
        </div></div>
        <div class="card mb-3" id="staff-edit-positions"><div class="card-header"><h5 class="card-title mb-0">Positions they work</h5></div><div class="card-body">
            <input type="hidden" name="positions[]" value="">
            <?php foreach ($manage as $s): ?>
                <?php if (count($manage) > 1): ?><div class="fs-12 text-muted text-uppercase mb-1"><?= e($names[$s] ?? '') ?></div><?php endif; ?>
                <?php if (($byPos[$s] ?? []) === []): ?><div class="text-muted mb-2">This restaurant has no positions yet.</div><?php endif; ?>
                <?php foreach ($byPos[$s] ?? [] as $p): $pid = (int) $p['position_id']; ?>
                    <div class="d-flex align-items-center gap-2 border rounded px-3 mb-2 btn-touch">
                        <input type="checkbox" class="form-check-input mt-0" name="positions[]" value="<?= $pid ?>" id="staff-edit-form-field-position-<?= $pid ?>" <?= isset($has[$pid]) ? 'checked' : '' ?>>
                        <label class="flex-grow-1 mb-0" for="staff-edit-form-field-position-<?= $pid ?>"><?= pos_swatch($p['color'], 'dot') ?> <?= e($p['name']) ?></label>
                        <label class="fs-12 text-muted mb-0" for="staff-edit-form-field-primary-<?= $pid ?>">Main <input type="radio" class="form-check-input ms-1" name="primary" value="<?= $pid ?>" id="staff-edit-form-field-primary-<?= $pid ?>" <?= $primary === $pid ? 'checked' : '' ?>></label>
                    </div>
                <?php endforeach; ?>
            <?php endforeach; ?>
            <div class="fs-12 text-muted">Their pay is on their page, on each position.</div>
        </div></div>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Notes for managers</h5></div><div class="card-body">
            <label class="form-label fs-12 text-muted" for="staff-edit-form-field-notes">Notes</label>
            <textarea name="notes" id="staff-edit-form-field-notes" class="form-control mb-1" rows="3" maxlength="2000"><?= e($cur['notes'] ?? '') ?></textarea>
            <div class="fs-12 text-muted">Managers only — never shown to other staff.</div>
        </div></div>
        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="staff-edit-form-save-btn">Save</button>
        <?= hx_link('/staff/' . $id, 'Cancel', 'btn btn-light btn-touch w-100', 'id="staff-edit-form-cancel-link"') ?>
    </form>
</div>
