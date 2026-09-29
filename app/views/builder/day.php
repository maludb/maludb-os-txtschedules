<?php
/**
 * One day by the hour (screen `day-view`). Data: site, date, prev, next, groups (by position), hours [[minute, label, on]], lo, hi (axis minutes), mins (closure), week
 * Each shift is a bar on a shared axis drawn as SVG (no style attribute); the row under the axis says how many people are on each hour.
 */
$siteId = (int) $site['site_id'];
$span = $hi - $lo;
$here = '/builder/day?' . http_build_query(['site' => $siteId, 'date' => $date]);
$q = static fn (string $d): string => '/builder/day?' . http_build_query(['site' => $siteId, 'date' => $d]);
?>
<?= view('shared/header.php', ['id' => 'day-view', 'title' => 'Day view', 'crumbs' => [['Home', '/'], ['Builder', builder_url($siteId, $week)], [local_dt($date . ' 12:00:00', 'UTC')->format('D M j'), null]]]) ?>
<div class="main-content" id="day-view-content">
    <div class="d-flex align-items-center gap-2 mb-3">
        <?= hx_link($q($prev), '<i class="feather-chevron-left"></i>', 'btn btn-light btn-touch px-3', 'id="day-view-prev" aria-label="Previous day"') ?>
        <div class="fw-bold flex-grow-1 text-center" id="day-view-title"><?= e(local_dt($date . ' 12:00:00', 'UTC')->format('l, M j')) ?></div>
        <?= hx_link($q($next), '<i class="feather-chevron-right"></i>', 'btn btn-light btn-touch px-3', 'id="day-view-next" aria-label="Next day"') ?>
    </div>
    <div class="card mb-3" id="day-view-axis-card"><div class="card-body p-3">
        <div class="day-axis" id="day-view-axis"><?php foreach ($hours as $i => $h): if ($i % 2 === 0 || count($hours) <= 10): ?><span><?= e($h['label']) ?></span><?php endif; endforeach; ?></div>
        <div class="day-axis mt-1" id="day-view-counts" aria-label="People on, by hour"><?php foreach ($hours as $i => $h): if ($i % 2 === 0 || count($hours) <= 10): ?><span class="<?= $h['on'] === 0 ? '' : 'fw-semibold text-body' ?>"><?= (int) $h['on'] ?></span><?php endif; endforeach; ?></div>
        <div class="fs-11 text-muted mt-1">People on, at the start of each hour shown.</div>
    </div></div>
    <?php if ($groups === []): ?><div class="card" id="day-view-empty"><div class="card-body text-center text-muted py-4">No shifts on this day.</div></div><?php endif; ?>
    <?php foreach ($groups as $g): ?>
        <div class="card mb-3" id="day-group-<?= (int) $g['position_id'] ?>">
            <div class="card-header d-flex align-items-center gap-2"><?= pos_swatch($g['color'], 'dot') ?><h6 class="card-title mb-0 me-auto"><?= e($g['name']) ?></h6></div>
            <div class="card-body p-3">
                <?php foreach ($g['rows'] as $r): $id = (int) $r['shift_id'];
                    $x = max(0, $mins((string) $r['starts_at']) - $lo); $w = max(1, min($hi, $mins((string) $r['ends_at'])) - max($lo, $mins((string) $r['starts_at'])));
                    $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $r['position_color']) ? $r['position_color'] : '#6c757d'; ?>
                    <div class="day-row" id="day-shift-<?= $id ?>">
                        <div class="d-flex justify-content-between gap-2 fs-12">
                            <?= hx_link(with_back('/shifts/' . $id, $here), '<span class="fw-semibold">' . e($r['is_open'] ? 'Open' : $r['assignee_name']) . '</span>', 'text-dark', 'id="day-shift-' . $id . '-link"') ?>
                            <span class="text-muted"><?= e(shift_time_range((string) $r['starts_at'], (string) $r['ends_at'], (string) $site['timezone'])) ?></span>
                        </div>
                        <svg class="day-bar" viewBox="0 0 <?= (int) $span ?> 10" preserveAspectRatio="none" role="img" aria-label="<?= e(shift_time_range((string) $r['starts_at'], (string) $r['ends_at'], (string) $site['timezone'])) ?>">
                            <rect class="bar-track" width="<?= (int) $span ?>" height="10"/>
                            <?php for ($m = 60; $m < $span; $m += 60): ?><line class="day-grid" x1="<?= $m ?>" x2="<?= $m ?>" y1="0" y2="10" vector-effect="non-scaling-stroke"/><?php endfor; ?>
                            <rect class="day-shift" x="<?= (int) $x ?>" width="<?= (int) $w ?>" height="10" fill="<?= e($color) ?>" fill-opacity="<?= $r['is_open'] ? '0.35' : '0.9' ?>"/>
                        </svg>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>
