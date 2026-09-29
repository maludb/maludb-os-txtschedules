<?php
/** One position as a card. Data: r, may, here, site. The default rate line and the reach only with may['labor']; the rate form only with may['pay']. */
$id = (int) $r['position_id'];
?>
<div class="card h-100" id="position-<?= $id ?>"><div class="card-body p-3">
    <div class="d-flex justify-content-between align-items-start gap-2">
        <div class="fw-bold" id="position-<?= $id ?>-name"><?= pos_swatch($r['color'], 'dot') ?> <?= e($r['name']) ?></div>
        <span class="badge bg-soft-secondary text-secondary" id="position-<?= $id ?>-area"><?= e(area_label($r['area'])) ?></span>
    </div>
    <div class="fs-12 text-muted mt-1" id="position-<?= $id ?>-people"><?= (int) $r['people'] ?> <?= (int) $r['people'] === 1 ? 'person works' : 'people work' ?> it</div>
    <div class="mt-1" id="position-<?= $id ?>-certifications">
        <?php if ($r['certifications'] === []): ?><span class="fs-12 text-muted">Needs no certification</span><?php endif; ?>
        <?php foreach ($r['certifications'] as $c): ?><span class="badge bg-soft-primary text-primary me-1"><i class="feather-award me-1"></i><?= e($c['name']) ?></span><?php endforeach; ?>
    </div>
    <?php if ($may['labor']): ?>
        <div class="mt-2 border-top pt-2" id="position-<?= $id ?>-rate">
            <div><span class="fw-semibold"><?= $r['default_wage_rate'] === null ? 'No default rate' : e(rate_label($r['default_wage_rate'], (string) ($site['currency'] ?? 'USD'))) ?></span> <span class="text-muted fs-12">default</span></div>
            <div class="fs-12 text-muted" id="position-<?= $id ?>-reach"><?= e(rate_reach_words($r['reach'])) ?></div>
        </div>
    <?php endif; ?>
    <?php if ($may['pay']): ?>
        <form method="post" action="/positions/rate.php" hx-post="/positions/rate.php" hx-target="#flash" hx-confirm="Change the default rate for <?= e($r['name']) ?>? It changes the cost of everyone's shifts here who has no rate of their own, from now on. An empty rate clears it." class="mt-2" id="position-<?= $id ?>-rate-form">
            <?= csrf_field() ?><input type="hidden" name="position" value="<?= $id ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
            <label class="form-label fs-12 text-muted" for="position-<?= $id ?>-rate-form-field-rate">Default hourly rate</label>
            <div class="input-group">
                <input type="text" inputmode="decimal" name="rate" id="position-<?= $id ?>-rate-form-field-rate" class="form-control btn-touch" maxlength="9" autocomplete="off"
                       value="<?= $may['labor'] && $r['default_wage_rate'] !== null ? e(number_format($r['default_wage_rate'], 2, '.', '')) : '' ?>" placeholder="Empty clears it">
                <button type="submit" class="btn btn-primary btn-touch" id="position-<?= $id ?>-rate-form-save-btn">Set</button>
            </div>
        </form>
    <?php endif; ?>
    <?php if ($may['manage']): ?>
        <div class="row g-2 mt-2">
            <div class="col-6"><?= hx_link('/positions/' . $id . '/edit', 'Change', 'btn btn-light btn-touch w-100', 'id="position-' . $id . '-edit-btn"') ?></div>
            <div class="col-6"><form method="post" action="/positions/archive.php" hx-post="/positions/archive.php" hx-target="#flash" hx-confirm="Archive <?= e($r['name']) ?>? It stays on old shifts and leaves the pickers.">
                <?= csrf_field() ?><input type="hidden" name="position" value="<?= $id ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                <button type="submit" class="btn btn-light btn-touch w-100" id="position-<?= $id ?>-archive-btn">Archive</button></form></div>
        </div>
    <?php endif; ?>
</div></div>
