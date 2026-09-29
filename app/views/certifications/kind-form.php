<?php
/** Add or change a certification kind (screens `certification-kind-add`, `certification-kind-edit`). Data: site, cur, positions */
$siteId = (int) $site['site_id'];
$id = $cur['kind_id'] ?? null;
$need = array_flip($cur['position_ids'] ?? []);
$screen = $cur === null ? 'certification-kind-add' : 'certification-kind-edit';
?>
<?= view('shared/header.php', ['id' => $screen, 'title' => $cur === null ? 'Add a certification' : 'Change ' . $cur['name'], 'crumbs' => [['Home', '/'], ['Certifications', '/certifications/?site=' . $siteId], [$cur === null ? 'Add' : 'Change', null]]]) ?>
<div class="main-content" id="<?= e($screen) ?>-content">
    <form method="post" action="/certifications/kinds/save.php" hx-post="/certifications/kinds/save.php" hx-target="#flash" id="kind-form">
        <?= csrf_field() ?><input type="hidden" name="site" value="<?= $siteId ?>"><input type="hidden" name="return_to" value="/certifications/?site=<?= $siteId ?>">
        <?php if ($id !== null): ?><input type="hidden" name="kind" value="<?= (int) $id ?>"><?php endif; ?>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0"><?= e($site['name']) ?></h5></div><div class="card-body">
            <label class="form-label fs-12 text-muted" for="kind-form-field-name">Name</label>
            <input type="text" name="name" id="kind-form-field-name" class="form-control btn-touch mb-3" maxlength="60" required value="<?= e($cur['name'] ?? '') ?>" placeholder="Food handler">
            <input type="hidden" name="track_expiry" value="no">
            <label class="d-flex align-items-center gap-2 border rounded px-3 mb-2 btn-touch" for="kind-form-field-track"><input type="checkbox" class="form-check-input mt-0" name="track_expiry" value="yes" id="kind-form-field-track" <?= ($cur['track_expiry'] ?? true) ? 'checked' : '' ?>>It expires — keep its expiry date</label>
            <label class="form-label fs-12 text-muted mt-2" for="kind-form-field-warn">Days of warning before it expires</label>
            <input type="number" name="warn_days" id="kind-form-field-warn" class="form-control btn-touch mb-1" min="0" max="365" value="<?= (int) ($cur['warn_days'] ?? 30) ?>">
            <div class="fs-12 text-muted">A manager is told when a card comes inside these days. 0 to 365.</div>
        </div></div>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Positions that need it</h5></div><div class="card-body" id="kind-form-positions">
            <input type="hidden" name="positions[]" value="">
            <?php if ($positions === []): ?><div class="text-muted">This restaurant has no positions yet.</div><?php endif; ?>
            <?php foreach ($positions as $p): $pid = (int) $p['position_id']; ?>
                <label class="d-flex align-items-center gap-2 border rounded px-3 mb-2 btn-touch" for="kind-form-field-position-<?= $pid ?>"><input type="checkbox" class="form-check-input mt-0" name="positions[]" value="<?= $pid ?>" id="kind-form-field-position-<?= $pid ?>" <?= isset($need[$pid]) ? 'checked' : '' ?>><?= pos_swatch($p['color'], 'dot') ?> <?= e($p['name']) ?></label>
            <?php endforeach; ?>
            <div class="fs-12 text-muted">Only this restaurant's own positions.</div>
        </div></div>
        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="kind-form-save-btn">Save</button>
        <?= hx_link('/certifications/?site=' . $siteId, 'Cancel', 'btn btn-light btn-touch w-100', 'id="kind-form-cancel-link"') ?>
    </form>
</div>
