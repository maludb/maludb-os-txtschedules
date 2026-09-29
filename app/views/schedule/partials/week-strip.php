<?php
/**
 * The week as seven day chips inside a card that scrolls sideways on a phone; today outlined, a dot on each day worked, tap = scroll to the day.
 * Data: days = [[date, dow, dom, works, isToday]], week (Y-m-d), prev, next (week starts), view, base (path)
 */
$q = static fn (string $w): string => $base . '?' . http_build_query(['week' => $w, 'view' => $view]);
?>
<div class="card mb-3" id="my-schedule-strip">
    <div class="card-body p-2 d-flex align-items-center gap-1">
        <div class="d-flex flex-nowrap overflow-auto flex-grow-1 gap-1 week-chips justify-content-between" id="my-schedule-chips">
            <?php foreach ($days as [$date, $dow, $dom, $works, $isToday]): ?>
                <?php $cls = 'week-chip text-center rounded' . ($isToday ? ' is-today' : '') . ($works ? ' works' : ''); ?>
                <?php if ($works): ?>
                    <a href="#my-day-<?= e($date) ?>" class="<?= e($cls) ?>" id="week-chip-<?= e($date) ?>"><span class="fs-11 text-uppercase"><?= e($dow) ?></span><span class="fw-bold d-block"><?= e($dom) ?></span><i class="chip-dot"></i></a>
                <?php else: ?>
                    <span class="<?= e($cls) ?>" id="week-chip-<?= e($date) ?>"><span class="fs-11 text-uppercase"><?= e($dow) ?></span><span class="fw-bold d-block"><?= e($dom) ?></span></span>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
</div>
