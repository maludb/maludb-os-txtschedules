<?php
/**
 * The headcount the covers call for, beside the scheduled one (screen `forecast`). Data: needRows (day_part:position => [day_part, position, cells => date => [expected, recommended, scheduled, open, gap]]), days, day.
 * A gap is in danger, a surplus muted; open shifts are counted apart. A phone shows the selected day as a list, a desktop the week as a table.
 */
?>
<div class="card mb-3" id="forecast-needs">
    <div class="card-header"><h6 class="card-title mb-0">Staffing: scheduled of recommended</h6></div>
    <?php if ($needRows === []): ?>
        <div class="card-body text-muted" id="forecast-needs-empty">Nothing to compare yet — set a ratio for a position below and type the covers.</div>
    <?php else: ?>
        <div class="d-lg-none card-body p-3" id="forecast-needs-phone">
            <div class="fs-12 text-muted mb-2"><?= e((new DateTimeImmutable($day))->format('l, M j')) ?></div>
            <?php $any = false; foreach ($needRows as $k => $n): if (!isset($n['cells'][$day])) { continue; } $any = true; [$t, $cls] = need_cell_words($n['cells'][$day]); ?>
                <div class="d-flex justify-content-between gap-2 py-2 border-bottom" id="needs-phone-<?= e(str_replace(':', '-', $k)) ?>"><span><?= e($n['day_part'] . ' · ' . $n['position']) ?></span><span class="<?= e($cls) ?> text-end"><?= e($t) ?></span></div>
            <?php endforeach; if (!$any): ?><div class="text-muted">No recommendation this day.</div><?php endif; ?>
        </div>
        <div class="table-responsive d-none d-lg-block" id="forecast-needs-scroll">
            <table class="table table-bordered mb-0 forecast-needs" id="forecast-needs-table">
                <thead><tr><th class="grid-name">Day-part · position</th><?php foreach ($days as [$date, $dow, $dom]): ?><th class="grid-day" id="needs-head-<?= e($date) ?>"><?= e($dow . ' ' . $dom) ?></th><?php endforeach; ?></tr></thead>
                <tbody>
                <?php foreach ($needRows as $k => $n): ?>
                    <tr id="needs-row-<?= e(str_replace(':', '-', $k)) ?>"><th class="grid-name" scope="row"><?= e($n['day_part']) ?> · <?= e($n['position']) ?></th>
                        <?php foreach ($days as [$date]): $c = $n['cells'][$date] ?? null; ?>
                            <td class="fs-12" id="needs-cell-<?= e(str_replace(':', '-', $k)) ?>-<?= e($date) ?>"><?php if ($c === null): ?>—<?php else: [$t, $cls] = need_cell_words($c); ?><span class="<?= e($cls) ?>"><?= e($t) ?></span><?php endif; ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
