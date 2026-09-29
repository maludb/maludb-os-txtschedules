<?php
/** Add or change a position (screens `position-add`, `position-edit`). Data: site, cur, kinds. The default rate is not on this form. */
$siteId = (int) $site['site_id'];
$id = $cur['position_id'] ?? null;
$need = array_flip($cur['kind_ids'] ?? []);
$screen = $cur === null ? 'position-add' : 'position-edit';
?>
<?= view('shared/header.php', ['id' => $screen, 'title' => $cur === null ? 'Add a position' : 'Change ' . $cur['name'], 'crumbs' => [['Home', '/'], ['Positions', '/positions/?site=' . $siteId], [$cur === null ? 'Add' : 'Change', null]]]) ?>
<div class="main-content" id="<?= e($screen) ?>-content">
    <form method="post" action="/positions/save.php" hx-post="/positions/save.php" hx-target="#flash" id="position-form">
        <?= csrf_field() ?><input type="hidden" name="site" value="<?= $siteId ?>"><input type="hidden" name="return_to" value="/positions/?site=<?= $siteId ?>">
        <?php if ($id !== null): ?><input type="hidden" name="position" value="<?= (int) $id ?>"><?php endif; ?>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0"><?= e($site['name']) ?></h5></div><div class="card-body">
            <label class="form-label fs-12 text-muted" for="position-form-field-name">Name</label>
            <input type="text" name="name" id="position-form-field-name" class="form-control btn-touch mb-3" maxlength="60" required value="<?= e($cur['name'] ?? '') ?>" placeholder="Server">
            <label class="form-label fs-12 text-muted" for="position-form-field-area">Area</label>
            <select name="area" id="position-form-field-area" class="form-select btn-touch mb-3">
                <?php foreach (POSITION_AREAS as $k => $label): ?><option value="<?= e($k) ?>" <?= ($cur['area'] ?? 'front') === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
            </select>
            <label class="form-label fs-12 text-muted" for="position-form-field-color">Colour on the schedule</label>
            <input type="color" name="color" id="position-form-field-color" class="form-control form-control-color btn-touch w-100 mb-3" value="<?= e($cur['color'] ?? '#3454d1') ?>">
            <label class="form-label fs-12 text-muted" for="position-form-field-order">Order in the list</label>
            <input type="number" name="sort_order" id="position-form-field-order" class="form-control btn-touch" min="0" max="1000" value="<?= (int) ($cur['sort_order'] ?? 0) ?>">
        </div></div>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Certifications it needs</h5></div><div class="card-body" id="position-form-certifications">
            <input type="hidden" name="certifications[]" value="">
            <?php if ($kinds === []): ?><div class="text-muted">This restaurant has no certifications yet.</div><?php endif; ?>
            <?php foreach ($kinds as $k): $kid = (int) $k['kind_id']; ?>
                <label class="d-flex align-items-center gap-2 border rounded px-3 mb-2 btn-touch" for="position-form-field-kind-<?= $kid ?>"><input type="checkbox" class="form-check-input mt-0" name="certifications[]" value="<?= $kid ?>" id="position-form-field-kind-<?= $kid ?>" <?= isset($need[$kid]) ? 'checked' : '' ?>><?= e($k['name']) ?></label>
            <?php endforeach; ?>
            <div class="fs-12 text-muted">Someone without a needed card gets a warning when they are scheduled.</div>
        </div></div>
        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="position-form-save-btn">Save</button>
        <?= hx_link('/positions/?site=' . $siteId, 'Cancel', 'btn btn-light btn-touch w-100', 'id="position-form-cancel-link"') ?>
    </form>
</div>
