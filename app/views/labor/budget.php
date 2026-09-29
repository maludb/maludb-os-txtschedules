<?php
/**
 * The labor budget (screen `budget`; labor.view). Data: site, sites, ws, prev, next, days, areas (area => hours/cost/budget), byDay, may (set), currency, notice.
 * Bars turn danger when scheduled goes over the budget. The form for an area shows only to a holder of settings.manage.
 */
$siteId = (int) $site['site_id'];
$here = budget_url($siteId, $ws);
$money = static fn ($n): string => money_label($n, $currency);
$pct = static fn (float $v, ?float $of): ?float => $of !== null && $of > 0 ? $v / $of * 100 : null;
$kindOf = static fn (?float $p): string => $p === null ? 'primary' : ($p > 100 ? 'danger' : ($p >= 90 ? 'warning' : 'primary'));
$fmt = static fn (?float $v): string => $v === null ? '' : rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
$shown = [];
foreach (BUDGET_AREAS as $k => $label) {
    if ($may['set'] || isset($areas[$k])) { $shown[$k] = $label; }
}
?>
<?= view('shared/header.php', ['id' => 'budget', 'title' => 'Budget', 'crumbs' => [['Home', '/'], ['Builder', builder_url($siteId, $ws)], ['Budget', null]]]) ?>
<div class="main-content" id="budget-content" data-week-start="<?= e($ws) ?>">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if (count($sites) > 1): ?>
        <div class="d-flex flex-wrap gap-1 mb-3" id="budget-sites">
            <?php foreach ($sites as $s): ?><?= hx_link(budget_url((int) $s['scope_id'], $ws), e($s['name']), 'btn btn-touch ' . ((int) $s['scope_id'] === $siteId ? 'btn-primary' : 'btn-light'), 'id="budget-site-' . (int) $s['scope_id'] . '"') ?><?php endforeach; ?>
        </div>
    <?php endif; ?>
    <div class="card mb-3" id="budget-head"><div class="card-body p-3">
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <?= hx_link(budget_url($siteId, $prev), '<i class="feather-chevron-left"></i>', 'btn btn-light btn-touch px-3', 'id="budget-prev" aria-label="Previous week"') ?>
            <div class="fw-bold text-nowrap" id="budget-range"><?= e(week_label($ws)) ?></div>
            <?= hx_link(budget_url($siteId, $next), '<i class="feather-chevron-right"></i>', 'btn btn-light btn-touch px-3', 'id="budget-next" aria-label="Next week"') ?>
            <span class="ms-auto fs-12 text-muted" id="budget-site-name"><?= e($site['name']) ?></span>
        </div>
        <div class="d-flex gap-2 flex-wrap mt-2">
            <?= hx_link(builder_url($siteId, $ws), '<i class="feather-grid me-1"></i>Builder', 'btn btn-light btn-touch flex-fill', 'id="budget-to-builder"') ?>
            <?= hx_link(forecast_url($siteId, $ws), '<i class="feather-trending-up me-1"></i>Forecast', 'btn btn-light btn-touch flex-fill', 'id="budget-to-forecast"') ?>
        </div>
    </div></div>

    <div class="alert alert-secondary fs-12" id="budget-note">Cost is paid hours times the effective hourly rate — the person's own, else the position's default. An open shift adds hours and no cost until someone holds it. Overtime multipliers are not applied. Drafts count.</div>

    <div class="row g-3 mb-3" id="budget-areas">
        <?php foreach ($shown as $k => $label): $a = $areas[$k] ?? ['area' => $k, 'scheduled_hours' => 0.0, 'scheduled_cost' => 0.0, 'budget_hours' => null, 'budget_amount' => null, 'budget_id' => null];
            $ph = $pct($a['scheduled_hours'], $a['budget_hours']); $pc = $pct($a['scheduled_cost'], $a['budget_amount']); ?>
            <div class="col-12 col-xl-6" id="budget-area-<?= e($k) ?>"><div class="card h-100"><div class="card-body p-3">
                <div class="d-flex justify-content-between gap-2 flex-wrap"><div class="fw-bold" id="budget-area-<?= e($k) ?>-name"><?= $a['budget_id'] !== null ? '<span id="budget-' . (int) $a['budget_id'] . '"></span>' : '' ?><?= e($k === 'all' ? 'Total' : $label) ?></div>
                    <?php if ($ph !== null && $ph > 100 || $pc !== null && $pc > 100): ?><span class="badge bg-soft-danger text-danger" id="budget-area-<?= e($k) ?>-over">Over budget</span><?php endif; ?></div>
                <div class="mt-3">
                    <div class="d-flex justify-content-between fs-13"><span>Hours</span><span id="budget-area-<?= e($k) ?>-hours"><?= e(days_label($a['scheduled_hours'])) ?> h<?= $a['budget_hours'] !== null ? ' of ' . e(days_label($a['budget_hours'])) . ' h' : ' <span class="text-muted">(no hours budget)</span>' ?></span></div>
                    <?php if ($ph !== null): ?><?= svg_bar($ph, $kindOf($ph), 8) ?><?php endif; ?>
                </div>
                <div class="mt-3">
                    <div class="d-flex justify-content-between fs-13"><span>Cost</span><span id="budget-area-<?= e($k) ?>-cost"><?= e($money($a['scheduled_cost'])) ?><?= $a['budget_amount'] !== null ? ' of ' . e($money($a['budget_amount'])) : ' <span class="text-muted">(no amount budget)</span>' ?></span></div>
                    <?php if ($pc !== null): ?><?= svg_bar($pc, $kindOf($pc), 8) ?><?php endif; ?>
                </div>
                <?php if ($may['set']): ?>
                    <form method="post" action="/labor/budget.php" hx-post="/labor/budget.php" hx-target="#flash" class="mt-3" id="budget-form-<?= e($k) ?>">
                        <?= csrf_field() ?><input type="hidden" name="site" value="<?= $siteId ?>"><input type="hidden" name="week_start" value="<?= e($ws) ?>"><input type="hidden" name="area" value="<?= e($k) ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                        <div class="row g-2">
                            <div class="col-6"><label class="form-label fs-12 text-muted" for="budget-form-<?= e($k) ?>-field-hours">Budget hours</label>
                                <input type="number" inputmode="decimal" step="0.25" min="0" max="99999" name="budget_hours" id="budget-form-<?= e($k) ?>-field-hours" class="form-control btn-touch" value="<?= e($fmt($a['budget_hours'])) ?>" placeholder="none"></div>
                            <div class="col-6"><label class="form-label fs-12 text-muted" for="budget-form-<?= e($k) ?>-field-amount">Budget amount</label>
                                <input type="number" inputmode="decimal" step="0.01" min="0" max="9999999" name="budget_amount" id="budget-form-<?= e($k) ?>-field-amount" class="form-control btn-touch" value="<?= e($fmt($a['budget_amount'])) ?>" placeholder="none"></div>
                        </div>
                        <button type="submit" class="btn btn-light btn-touch w-100 mt-2" id="budget-form-<?= e($k) ?>-save-btn">Save <?= e($k === 'all' ? 'the total' : strtolower($label)) ?> budget</button>
                    </form>
                <?php endif; ?>
            </div></div></div>
        <?php endforeach; ?>
    </div>

    <div class="card mb-3" id="budget-by-day">
        <div class="card-header"><h6 class="card-title mb-0">By day</h6></div>
        <div class="table-responsive">
            <table class="table mb-0" id="budget-by-day-table">
                <thead><tr><th>Day</th><th class="text-end">Shifts</th><th class="text-end">Hours</th><th class="text-end">Cost</th></tr></thead>
                <tbody>
                <?php foreach ($days as [$date, $dow, $dom]): $r = $byDay[$date] ?? null; ?>
                    <tr id="budget-day-<?= e($date) ?>"><td><?= hx_link(builder_url($siteId, $ws, ['day' => $date]), e($dow . ' ' . $dom)) ?></td>
                        <td class="text-end" id="budget-day-<?= e($date) ?>-shifts"><?= $r === null ? '—' : (int) $r['shifts'] . ($r['open'] > 0 ? ' <span class="text-primary fs-12">(' . (int) $r['open'] . ' open)</span>' : '') ?></td>
                        <td class="text-end" id="budget-day-<?= e($date) ?>-hours"><?= $r === null ? '—' : e(days_label($r['hours'])) . ' h' ?></td>
                        <td class="text-end" id="budget-day-<?= e($date) ?>-cost"><?= $r === null || $r['cost'] === null ? '—' : e($money($r['cost'])) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
