<?php
/**
 * The reports (screen `reports`). Data: site (name, timezone, currency), siteId, avail (key => [label, right, about]), key (chosen or ''), table (columns, rows of THIS page), from, to, page, total, sites
 * A report is a table inside its card (it scrolls there, never the page), 50 rows a page, and a CSV of the whole table.
 */
$q = static fn (array $extra = []): string => reports_url($siteId, $key, ['from' => $from, 'to' => $to] + $extra);
?>
<?php $crumbs = [['Home', '/'], ['Reports', $key === '' ? null : reports_url($siteId)]];
if ($key !== '') { $crumbs[] = [REPORTS[$key][0], null]; } ?>
<?= view('shared/header.php', ['id' => 'reports', 'title' => 'Reports', 'crumbs' => $crumbs]) ?>
<div class="main-content" id="reports-content">
    <?php if (count($sites) > 1): ?>
        <div class="d-flex flex-wrap gap-1 mb-3" id="reports-sites">
            <?php foreach ($sites as $x): ?><?= hx_link(reports_url((int) $x['scope_id'], $key, ['from' => $from, 'to' => $to]), e($x['name']), 'btn btn-touch ' . ((int) $x['scope_id'] === $siteId ? 'btn-primary' : 'btn-light'), 'id="reports-site-' . (int) $x['scope_id'] . '"') ?><?php endforeach; ?>
        </div>
    <?php endif; ?>
    <div class="row g-2 mb-3" id="reports-list">
        <?php foreach ($avail as $k => [$label, $right, $about]): ?>
            <div class="col-12 col-md-6 col-xl-4"><a href="<?= e(reports_url($siteId, $k, ['from' => $from, 'to' => $to])) ?>" class="card h-100 text-reset text-decoration-none border <?= $k === $key ? 'border-primary' : '' ?>" id="report-<?= e($k) ?>"
                hx-get="<?= e(reports_url($siteId, $k, ['from' => $from, 'to' => $to])) ?>" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="<?= e(reports_url($siteId, $k, ['from' => $from, 'to' => $to])) ?>">
                <div class="card-body p-3"><div class="fw-semibold" id="report-<?= e($k) ?>-name"><?= e($label) ?></div><div class="fs-12 text-muted"><?= e($about) ?></div></div></a></div>
        <?php endforeach; ?>
    </div>
    <?php if ($key === ''): ?>
        <div class="alert alert-secondary fs-13" id="reports-pick">Pick a report. Each is a table for <?= e($site['name']) ?> and a CSV of the same table.</div>
    <?php else: ?>
        <form method="get" action="/reports/" class="card mb-3" id="reports-range">
            <input type="hidden" name="report" value="<?= e($key) ?>"><input type="hidden" name="site" value="<?= $siteId ?>">
            <div class="card-body p-3"><div class="row g-2 align-items-end">
                <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="reports-range-field-from">From</label><input type="date" name="from" id="reports-range-field-from" class="form-control btn-touch" value="<?= e($from) ?>" required></div>
                <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="reports-range-field-to">Through</label><input type="date" name="to" id="reports-range-field-to" class="form-control btn-touch" value="<?= e($to) ?>" required></div>
                <div class="col-12 col-md-6 d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-touch flex-fill" id="reports-range-show-btn">Show</button>
                    <a href="<?= e($q(['format' => 'csv'])) ?>" class="btn btn-light btn-touch flex-fill" id="reports-csv-btn" download><i class="feather-download me-1"></i>Download CSV</a>
                </div>
            </div></div>
        </form>
        <div class="card mb-3" id="report-table-card">
            <div class="card-header"><h6 class="card-title mb-0" id="report-title"><?= e(REPORTS[$key][0]) ?> <span class="fs-12 text-muted fw-normal">— <?= e($site['name']) ?>, <?= e(format_date($from)) ?> to <?= e(format_date($to)) ?></span></h6></div>
            <div class="table-responsive">
                <table class="table table-hover mb-0" id="report-table">
                    <thead><tr><?php foreach ($table['columns'] as $c): ?><th class="<?= $c['num'] ? 'text-end' : '' ?>" id="report-col-<?= e($c['key']) ?>"><?= e($c['label']) ?></th><?php endforeach; ?></tr></thead>
                    <tbody>
                    <?php if ($table['rows'] === []): ?><tr><td colspan="<?= count($table['columns']) ?>" class="text-muted" id="report-empty">Nothing in this range.</td></tr><?php endif; ?>
                    <?php foreach ($table['rows'] as $i => $r): ?>
                        <tr id="report-row-<?= ($page - 1) * REPORT_PAGE + $i + 1 ?>"><?php foreach ($table['columns'] as $c): ?><td class="<?= $c['num'] ? 'text-end' : '' ?>"><?= e((string) ($r[$c['key']] ?? '')) ?></td><?php endforeach; ?></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php $pages = max(1, (int) ceil($total / REPORT_PAGE)); ?>
        <div class="d-flex justify-content-between align-items-center mb-3" id="report-pager">
            <span class="fs-12 text-muted" id="report-count"><?= (int) $total ?> row<?= $total === 1 ? '' : 's' ?><?= $pages > 1 ? ' — page ' . (int) $page . ' of ' . (int) $pages : '' ?></span>
            <?php if ($pages > 1): ?><div class="d-flex gap-1">
                <?php if ($page > 1): ?><?= hx_link($q(['page' => $page - 1]), '<i class="feather-chevron-left"></i>', 'btn btn-light btn-touch px-3', 'id="report-prev" aria-label="Previous page"') ?><?php endif; ?>
                <?php if ($page < $pages): ?><?= hx_link($q(['page' => $page + 1]), '<i class="feather-chevron-right"></i>', 'btn btn-light btn-touch px-3', 'id="report-next" aria-label="Next page"') ?><?php endif; ?>
            </div><?php endif; ?>
        </div>
        <div class="fs-12 text-muted mb-3" id="report-note">The CSV is exactly this table, every row. <?= $key === 'labor' || $key === 'overtime' ? 'Cost is paid hours times each person\'s effective rate; overtime multipliers are not applied to scheduled cost. ' : '' ?>Times are <?= e($site['name']) ?>'s own.</div>
    <?php endif; ?>
</div>
