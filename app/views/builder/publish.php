<?php
/** The publish summary (screen `week-publish`). Data: site, week, ws, summary, warn, shifts, canLabor, cost, currency */
$siteId = (int) $site['site_id'];
$soft = array_values(array_filter($warn, static fn (array $w): bool => $w['severity'] === 'soft'));
$hard = array_values(array_filter($warn, static fn (array $w): bool => $w['severity'] === 'hard'));
$shiftById = [];
foreach ($shifts as $s) { $shiftById[$s['shift_id']] = $s; }
$money = static fn ($n): string => ($currency === 'USD' ? '$' : $currency . ' ') . number_format((float) $n, 2);
$builder = builder_url($siteId, $ws);
$published = $week['status'] === 'published';
?>
<?= view('shared/header.php', ['id' => 'week-publish', 'title' => 'Publish the week', 'crumbs' => [['Home', '/'], ['Builder', $builder], ['Publish', null]]]) ?>
<div class="main-content" id="week-publish-content">
    <div class="card mb-3" id="publish-summary"><div class="card-body">
        <div class="fw-bold fs-5" id="publish-summary-week"><?= e(week_label($ws)) ?> · <?= e($site['name']) ?></div>
        <div class="row g-2 mt-2 text-center">
            <div class="col-6"><div class="fw-bold fs-4" id="publish-summary-shifts"><?= (int) $summary['shifts'] ?></div><div class="fs-12 text-muted">shifts</div></div>
            <div class="col-6"><div class="fw-bold fs-4" id="publish-summary-people"><?= (int) $summary['people_told'] ?></div><div class="fs-12 text-muted">people will be told</div></div>
            <div class="col-6"><div class="fw-bold fs-4 <?= $summary['open_left'] > 0 ? 'text-primary' : '' ?>" id="publish-summary-open"><?= (int) $summary['open_left'] ?></div><div class="fs-12 text-muted">open shifts left</div></div>
            <div class="col-6"><div class="fw-bold fs-4" id="publish-summary-hours"><?= e(days_label($summary['hours'])) ?></div><div class="fs-12 text-muted">hours<?= $canLabor ? ' · ' . e($money($cost)) : '' ?></div></div>
        </div>
    </div></div>

    <?php if ($published): ?>
        <div class="alert alert-success" id="publish-already" role="status">This week is already published. <?= hx_link($builder, 'Back to the builder', 'alert-link') ?></div>
    <?php else: ?>
        <?php if ($hard !== []): ?>
            <div class="card mb-3 border-danger" id="publish-hard"><div class="card-header"><h6 class="card-title mb-0 text-danger">Fix these first</h6></div>
                <ul class="list-group list-group-flush">
                    <?php foreach ($hard as $i => $w): ?><li class="list-group-item fs-12" id="publish-hard-<?= (int) $i ?>"><i class="feather-slash text-danger me-1"></i>
                        <?= hx_link(with_back('/shifts/' . (int) $w['shift_id'] . '/edit', $builder), '<span class="fw-semibold">' . e($w['display_name']) . '</span>', 'text-dark') ?>: <?= e($w['message']) ?></li><?php endforeach; ?>
                </ul></div>
        <?php endif; ?>
        <?php if ($soft !== []): ?>
            <div class="card mb-3" id="publish-warnings"><div class="card-header"><h6 class="card-title mb-0">Warnings <span class="badge bg-soft-warning text-warning ms-1"><?= count($soft) ?></span></h6></div>
                <ul class="list-group list-group-flush">
                    <?php foreach ($soft as $i => $w): $s = $shiftById[$w['shift_id']] ?? null; ?>
                        <li class="list-group-item fs-12" id="publish-warning-<?= (int) $i ?>"><i class="feather-alert-triangle text-warning me-1"></i>
                            <?= hx_link(with_back('/shifts/' . (int) $w['shift_id'], $builder), '<span class="fw-semibold">' . e($w['display_name']) . '</span>', 'text-dark') ?>: <?= e($w['message']) ?>
                            <?php if ($s !== null): ?><span class="text-muted d-block"><?= e($s['position_name'] . ' · ' . shift_when((string) $s['starts_at'], (string) $s['ends_at'], (string) $s['timezone'])) ?></span><?php endif; ?></li>
                    <?php endforeach; ?>
                </ul></div>
        <?php endif; ?>
        <?php if ($summary['shifts'] === 0): ?>
            <div class="alert alert-secondary" id="publish-empty">There are no shifts to publish yet.</div>
        <?php endif; ?>
        <form method="post" action="/weeks/publish.php" hx-post="/weeks/publish.php" hx-target="#flash" hx-confirm="Publish this week and tell staff?" id="publish-form">
            <?= csrf_field() ?><input type="hidden" name="week" value="<?= (int) $week['week_id'] ?>">
            <?php if ($soft !== []): ?>
                <label class="form-label fs-12 text-muted" for="publish-form-field-override-reason">Why publish with these warnings? (recorded with each one)</label>
                <textarea name="override_reason" id="publish-form-field-override-reason" class="form-control mb-3" rows="3" maxlength="500" required></textarea>
            <?php endif; ?>
            <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="publish-form-save-btn"<?= $hard !== [] || $summary['shifts'] === 0 ? ' disabled' : '' ?>><i class="feather-send me-1"></i>Publish and tell staff</button>
            <?= hx_link($builder, 'Back to the builder', 'btn btn-light btn-touch w-100', 'id="publish-form-cancel-link"') ?>
        </form>
    <?php endif; ?>
</div>
