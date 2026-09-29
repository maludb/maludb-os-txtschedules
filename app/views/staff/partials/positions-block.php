<?php
/**
 * A person's positions (staff-view). Data: p, positions (staff_position_rows), self, back.
 * PAY: a row shows a rate only when the presenter gave it `pay` — to labor.view at the position's restaurant (rate + where it comes from + the person's own rate), or to the person themself (their rate alone).
 * The pay form appears only for pay.edit; it is prefilled only for someone who may see the rate.
 */
$id = (int) $p['member_id'];
?>
<h6 class="text-muted text-uppercase fs-11">Positions</h6>
<div class="card mb-3" id="staff-view-positions"><ul class="list-group list-group-flush">
    <?php if ($positions === []): ?><li class="list-group-item text-muted" id="staff-view-positions-empty">No positions yet.</li><?php endif; ?>
    <?php foreach ($positions as $r): $pid = (int) $r['position_id']; ?>
        <li class="list-group-item" id="staff-position-<?= $pid ?>">
            <div class="d-flex justify-content-between align-items-center gap-2">
                <div class="fw-semibold" id="staff-position-<?= $pid ?>-name"><?= pos_swatch($r['color'], 'dot') ?> <?= e($r['name']) ?><?= $r['is_primary'] ? ' <i class="feather-star text-warning ms-1" aria-label="main position"></i>' : '' ?></div>
                <div class="fs-12 text-muted"><?= e($r['site_name']) ?></div>
            </div>
            <?php if ($r['pay'] !== null): ?>
                <div class="mt-1" id="staff-position-<?= $pid ?>-pay"><span class="fw-semibold"><?= e($r['pay']['label']) ?></span>
                    <?php if ($r['pay']['may_see_source'] && $r['pay']['source'] !== null): ?><span class="text-muted"> — <?= $r['pay']['source'] === 'own' ? 'Own rate' : 'Default for the position' ?></span><?php endif; ?></div>
            <?php endif; ?>
            <?php if ($r['may_pay']): ?>
                <form method="post" action="/staff/wage.php" hx-post="/staff/wage.php" hx-target="#flash" hx-confirm="Change <?= e($p['display_name']) ?>'s own rate as <?= e($r['name']) ?>? An empty rate removes it, so the position's default applies." class="mt-2" id="staff-position-<?= $pid ?>-pay-form">
                    <?= csrf_field() ?><input type="hidden" name="member" value="<?= $id ?>"><input type="hidden" name="position" value="<?= $pid ?>"><input type="hidden" name="return_to" value="<?= e($back) ?>">
                    <label class="form-label fs-12 text-muted" for="staff-position-<?= $pid ?>-pay-form-field-rate">Their own hourly rate</label>
                    <div class="input-group mb-1">
                        <input type="text" inputmode="decimal" name="rate" id="staff-position-<?= $pid ?>-pay-form-field-rate" class="form-control btn-touch" maxlength="9" autocomplete="off"
                               value="<?= $r['may_see_pay'] && $r['pay'] !== null && $r['pay']['own_rate'] !== null ? e(number_format((float) $r['pay']['own_rate'], 2, '.', '')) : '' ?>" placeholder="Empty: the position's default">
                        <button type="submit" class="btn btn-primary btn-touch" id="staff-position-<?= $pid ?>-pay-form-save-btn">Set</button>
                    </div>
                    <div class="fs-12 text-muted">Empty removes it, so the position's default applies.</div>
                </form>
            <?php endif; ?>
        </li>
    <?php endforeach; ?>
</ul></div>
