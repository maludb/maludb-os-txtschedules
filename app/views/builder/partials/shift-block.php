<?php
/**
 * One shift as a block of the week grid (screen `builder`). Data: s (a find_week_shifts row), warn (its warning sentences), view (people|positions), drag (may it be dragged), here (the builder's URL for ?back=)
 * open → dashed outline; cancelled → struck through; a warning chip lists its sentences; the whole block opens the shift. Never a wage.
 */
$id = (int) $s['shift_id'];
$open = (bool) $s['is_open'];
$cancelled = $s['status'] === 'cancelled';
$drag = ($drag ?? false) && !$cancelled;
$url = with_back('/shifts/' . $id, $here);
$label = $view === 'positions' ? ($open ? 'Open' : (string) $s['assignee_name']) : (string) $s['position_name'];
$class = 'shift-block' . ($open ? ' shift-open' : '') . ($cancelled ? ' shift-cancelled' : '') . (!$cancelled && $s['changed_after_publish_at'] !== null ? ' shift-changed' : '');
?>
<div class="<?= e($class) ?>" id="builder-shift-<?= $id ?>" data-shift="<?= $id ?>" data-day="<?= e(shift_day($s)) ?>"<?= $drag ? ' data-draggable="1"' : '' ?>>
    <?= pos_swatch($s['position_color']) ?>
    <a href="<?= e($url) ?>" class="shift-block-link" id="builder-shift-<?= $id ?>-link" draggable="false" hx-get="<?= e($url) ?>" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="<?= e($url) ?>">
        <span class="shift-block-time"><?= e(shift_time_range((string) $s['starts_at'], (string) $s['ends_at'], (string) $s['timezone'])) ?></span>
        <span class="shift-block-label"><?= e($label) ?><?= $cancelled ? ' · cancelled' : '' ?></span>
    </a>
    <?php if ($warn !== [] && !$cancelled): ?>
        <span class="shift-warn" id="builder-shift-<?= $id ?>-warn" title="<?= e(implode(' ', $warn)) ?>" aria-label="<?= e('Warning: ' . implode(' ', $warn)) ?>"><i class="feather-alert-triangle"></i></span>
    <?php endif; ?>
</div>
