<?php
/**
 * One shift as a card (the RecordCard pattern): when in the restaurant's zone, position with its colour, the restaurant's name when the person
 * holds more than one, a status badge for a live trade, "Working with …". The whole card opens the shift.
 * Data: s (a shifts row + others), back (the ?back= the link carries), showSite, zone
 */
$badge = shift_exchange_badge($s);
$url = with_back('/shifts/' . (int) $s['shift_id'], $back);
$with = shift_names($s['others'] ?? []);
$id = (int) $s['shift_id'];
?>
<a href="<?= e($url) ?>" class="card shift-card mb-2 text-decoration-none" id="shift-card-<?= $id ?>" hx-get="<?= e($url) ?>" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="<?= e($url) ?>">
    <div class="card-body p-3 d-flex gap-3 align-items-stretch">
        <?= pos_swatch($s['position_color']) ?>
        <div class="flex-grow-1 min-w-0">
            <div class="d-flex justify-content-between align-items-start gap-2">
                <div class="fw-bold text-dark" id="shift-card-<?= $id ?>-when"><?= e(shift_when($s['starts_at'], $s['ends_at'], $s['timezone'], $zone)) ?></div>
                <?php if ($badge !== null): ?><span class="badge bg-soft-<?= e($badge[1]) ?> text-<?= e($badge[1]) ?> text-nowrap" id="shift-card-<?= $id ?>-badge"><?= e($badge[0]) ?></span><?php endif; ?>
            </div>
            <div class="text-muted fs-12"><?= e($s['position_name']) ?><?= $showSite ? ' · ' . e($s['site_name']) : '' ?> · <?= e(days_label($s['paid_hours'])) ?> h</div>
            <?php if ($with !== ''): ?><div class="fs-12 text-muted" id="shift-card-<?= $id ?>-others">Working with <?= e($with) ?></div><?php endif; ?>
        </div>
    </div>
</a>
