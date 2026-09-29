<?php
/** The staffing needs strip (screen `builder`): recommended against scheduled per day-part and position, a gap in danger. Data: needs (date => rows), days. Shown only when the restaurant has a forecast. */
?>
<div class="card mb-3" id="builder-needs">
    <div class="card-header"><h6 class="card-title mb-0">Staffing needs</h6><span class="fs-12 text-muted">recommended against scheduled</span></div>
    <div class="card-body p-2">
        <div class="needs-strip">
            <?php foreach ($days as [$date, $dow, $dom]): ?>
                <div class="needs-day" id="builder-needs-<?= e($date) ?>">
                    <div class="fs-11 fw-semibold text-uppercase text-muted"><?= e($dow . ' ' . $dom) ?></div>
                    <?php foreach ($needs[$date] ?? [] as $i => $n): $short = $n['gap'] > 0; ?>
                        <div class="fs-12 <?= $short ? 'text-danger fw-semibold' : 'text-muted' ?>" id="builder-needs-<?= e($date) ?>-<?= (int) $i ?>"><?= e($n['day_part'] . ' · ' . $n['position']) ?>: <?= (int) $n['scheduled'] ?>/<?= (int) $n['recommended'] ?><?= $short ? ' — ' . (int) $n['gap'] . ' short' : '' ?></div>
                    <?php endforeach; ?>
                    <?php if (($needs[$date] ?? []) === []): ?><div class="fs-12 text-muted">—</div><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
