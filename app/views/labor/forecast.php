<?php
/**
 * The forecast (screen `forecast`). Data: site, sites, ws, we, prev, next, days, day, today, forecast (day_parts, cells), ratios, needRows, may (ratios), canFill, canLabor, result (lines), notice.
 * From 992 px the grid — day-parts down, seven days across, each cell a number box with its source under it; below that day tabs and the day's day-parts. Under both: the headcount the covers call
 * for beside the scheduled one, and the ratios. No cost on this page.
 */
$siteId = (int) $site['site_id'];
$parts = $forecast['day_parts'];
$cells = $forecast['cells'];
$here = forecast_url($siteId, $ws);
$phoneHere = forecast_url($siteId, $ws, ['day' => $day]);
$src = static fn (?array $c): string => $c === null ? '' : '<span class="badge bg-soft-' . ($c['source'] === 'reservations' ? 'info' : ($c['source'] === 'copied' ? 'secondary' : 'primary')) . ' text-' . ($c['source'] === 'reservations' ? 'info' : ($c['source'] === 'copied' ? 'secondary' : 'primary')) . '">' . e(COVER_SOURCES[$c['source']] ?? $c['source']) . '</span>';
?>
<?= view('shared/header.php', ['id' => 'forecast', 'title' => 'Forecast', 'crumbs' => [['Home', '/'], ['Builder', builder_url($siteId, $ws)], ['Forecast', null]]]) ?>
<div class="main-content" id="forecast-content" data-week-start="<?= e($ws) ?>">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php foreach ($result as $i => [$kind, $text]): ?><div class="alert alert-<?= e($kind) ?> mb-3" role="status" id="forecast-result-<?= (int) $i ?>"><?= e($text) ?></div><?php endforeach; ?>
    <?php if (count($sites) > 1): ?>
        <div class="d-flex flex-wrap gap-1 mb-3" id="forecast-sites">
            <?php foreach ($sites as $s): ?><?= hx_link(forecast_url((int) $s['scope_id'], $ws), e($s['name']), 'btn btn-touch ' . ((int) $s['scope_id'] === $siteId ? 'btn-primary' : 'btn-light'), 'id="forecast-site-' . (int) $s['scope_id'] . '"') ?><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="card mb-3" id="forecast-head"><div class="card-body p-3">
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <?= hx_link(forecast_url($siteId, $prev), '<i class="feather-chevron-left"></i>', 'btn btn-light btn-touch px-3', 'id="forecast-prev" aria-label="Previous week"') ?>
            <div class="fw-bold text-nowrap" id="forecast-range"><?= e(week_label($ws)) ?></div>
            <?= hx_link(forecast_url($siteId, $next), '<i class="feather-chevron-right"></i>', 'btn btn-light btn-touch px-3', 'id="forecast-next" aria-label="Next week"') ?>
            <span class="ms-auto fs-12 text-muted" id="forecast-site-name"><?= e($site['name']) ?></span>
        </div>
        <div class="d-flex gap-2 flex-wrap mt-2">
            <?= hx_link(builder_url($siteId, $ws), '<i class="feather-grid me-1"></i>Builder', 'btn btn-light btn-touch flex-fill', 'id="forecast-to-builder"') ?>
            <?php if ($canLabor): ?><?= hx_link(budget_url($siteId, $ws), '<i class="feather-dollar-sign me-1"></i>Budget', 'btn btn-light btn-touch flex-fill', 'id="forecast-to-budget"') ?><?php endif; ?>
        </div>
    </div></div>

    <?php if ($parts === []): ?><div class="card mb-3"><div class="card-body text-center text-muted py-4" id="forecast-empty"><?= e($site['name']) ?> has no day-parts yet — a day-part (Lunch, Dinner …) is what the forecast counts covers in.</div></div><?php endif; ?>

    <div class="card mb-3" id="forecast-actions"><div class="card-body p-3">
        <div class="fw-semibold mb-2">Fill the week</div>
        <div class="fs-12 text-muted mb-2" id="forecast-actions-note">Covers are the guests you expect in a day-part. Copy the last week you typed, or bring in what Reservations has booked — booked covers are not walk-ins, so type over one to change it.</div>
        <div class="row g-2">
            <div class="col-12 col-md-6">
                <form method="post" action="/labor/forecast-copy.php" hx-post="/labor/forecast-copy.php" hx-target="#flash" hx-confirm="Copy last week's typed covers into this week? Typed numbers here are replaced." id="forecast-copy-form">
                    <?= csrf_field() ?><input type="hidden" name="site" value="<?= $siteId ?>"><input type="hidden" name="from_week" value="<?= e($prev) ?>"><input type="hidden" name="to_week" value="<?= e($ws) ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                    <button type="submit" class="btn btn-light btn-touch w-100" id="forecast-copy-btn"><i class="feather-copy me-1"></i>Copy last week</button>
                </form>
            </div>
            <?php if ($canFill): ?>
            <div class="col-12 col-md-6">
                <form method="post" action="/labor/forecast-fill.php" hx-post="/labor/forecast-fill.php" hx-target="#flash" id="forecast-fill-form">
                    <?= csrf_field() ?><input type="hidden" name="site" value="<?= $siteId ?>"><input type="hidden" name="week_start" value="<?= e($ws) ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                    <button type="submit" class="btn btn-primary btn-touch w-100" id="forecast-fill-btn"><i class="feather-download-cloud me-1"></i>Fill from Reservations</button>
                    <label class="d-flex align-items-center gap-2 mt-2 fs-12" for="forecast-fill-field-replace"><input type="checkbox" class="form-check-input mt-0" name="replace_manual" value="yes" id="forecast-fill-field-replace">Also replace covers someone typed</label>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </div></div>

    <?php if ($parts !== []): ?>
    <!-- the phone: day tabs and the day's day-parts -->
    <div class="d-lg-none" id="forecast-phone">
        <div class="card mb-3"><div class="card-body p-2"><div class="d-flex flex-nowrap overflow-auto gap-1 week-chips" id="forecast-phone-chips">
            <?php foreach ($days as [$date, $dow, $dom, $isToday]): $has = false; foreach ($parts as $p) { $has = $has || isset($cells[$p['day_part_id']][$date]); } ?>
                <?= hx_link(forecast_url($siteId, $ws, ['day' => $date]), '<span class="fs-11 text-uppercase">' . e($dow) . '</span><span class="fw-bold d-block">' . e($dom) . '</span>' . ($has ? '<i class="chip-dot"></i>' : ''),
                    'week-chip text-center rounded' . ($isToday ? ' is-today' : '') . ($has ? ' works' : '') . ($date === $day ? ' selected' : ''), 'id="forecast-day-' . e($date) . '"') ?>
            <?php endforeach; ?>
        </div></div></div>
        <form method="post" action="/labor/forecast.php" hx-post="/labor/forecast.php" hx-target="#flash" id="forecast-phone-form">
            <?= csrf_field() ?><input type="hidden" name="site" value="<?= $siteId ?>"><input type="hidden" name="week" value="<?= e($ws) ?>"><input type="hidden" name="return_to" value="<?= e($phoneHere) ?>">
            <div class="fw-semibold mb-2" id="forecast-phone-title"><?= e((new DateTimeImmutable($day))->format('l, M j')) ?></div>
            <?php foreach ($parts as $p): $dp = $p['day_part_id']; $c = $cells[$dp][$day] ?? null; ?>
                <div class="card mb-2" id="forecast-phone-part-<?= $dp ?>"><div class="card-body p-3">
                    <label class="form-label fw-semibold mb-0" for="forecast-phone-field-<?= $dp ?>"><?= e($p['name']) ?> <span class="fs-12 text-muted fw-normal"><?= e(day_part_hours($p['starts_at'], $p['ends_at'])) ?></span></label>
                    <div class="d-flex align-items-center gap-2 mt-2">
                        <input type="number" inputmode="numeric" min="0" max="100000" step="1" name="cells[<?= $dp ?>][<?= e($day) ?>]" id="forecast-phone-field-<?= $dp ?>" class="form-control btn-touch" value="<?= $c === null ? '' : (int) $c['covers'] ?>" placeholder="covers">
                        <span id="forecast-phone-source-<?= $dp ?>"><?= $src($c) ?></span>
                    </div>
                    <?php foreach ($needRows as $k => $n): if (!str_starts_with($k, $dp . ':') || !isset($n['cells'][$day])) { continue; } [$t, $cls] = need_cell_words($n['cells'][$day]); ?>
                        <div class="fs-12 mt-2 <?= e($cls) ?>" id="forecast-phone-need-<?= e(str_replace(':', '-', $k)) ?>"><?= e($n['position']) ?>: <?= e($t) ?></div>
                    <?php endforeach; ?>
                </div></div>
            <?php endforeach; ?>
            <button type="submit" class="btn btn-primary btn-touch w-100 mb-3" id="forecast-phone-save-btn">Save this day's covers</button>
        </form>
    </div>

    <!-- from 992 px: the grid -->
    <form method="post" action="/labor/forecast.php" hx-post="/labor/forecast.php" hx-target="#flash" class="d-none d-lg-block" id="forecast-grid-form">
        <?= csrf_field() ?><input type="hidden" name="site" value="<?= $siteId ?>"><input type="hidden" name="week" value="<?= e($ws) ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
        <div class="card mb-3" id="forecast-grid-card">
            <div class="table-responsive" id="forecast-grid-scroll">
                <table class="table table-bordered mb-0 forecast-grid" id="forecast-grid">
                    <thead><tr><th class="grid-name" id="forecast-grid-corner">Covers expected</th>
                        <?php foreach ($days as [$date, $dow, $dom, $isToday]): ?><th class="grid-day<?= $isToday ? ' is-today' : '' ?>" id="forecast-grid-head-<?= e($date) ?>"><?= e($dow . ' ' . $dom) ?></th><?php endforeach; ?>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($parts as $p): $dp = $p['day_part_id']; ?>
                        <tr id="forecast-row-<?= $dp ?>">
                            <th class="grid-name" scope="row"><?= e($p['name']) ?><div class="fs-11 text-muted fw-normal"><?= e(day_part_hours($p['starts_at'], $p['ends_at'])) ?></div></th>
                            <?php foreach ($days as [$date]): $c = $cells[$dp][$date] ?? null; ?>
                                <td class="forecast-cell" id="forecast-cell-<?= $dp ?>-<?= e($date) ?>">
                                    <input type="number" inputmode="numeric" min="0" max="100000" step="1" name="cells[<?= $dp ?>][<?= e($date) ?>]" id="forecast-grid-field-<?= $dp ?>-<?= e($date) ?>" class="form-control btn-touch text-center" value="<?= $c === null ? '' : (int) $c['covers'] ?>" aria-label="<?= e($p['name'] . ' covers on ' . $date) ?>">
                                    <div class="text-center mt-1" id="forecast-source-<?= $dp ?>-<?= e($date) ?>"><?php if ($c !== null): ?><span id="forecast-cover-<?= (int) $c['id'] ?>"><?= $src($c) ?></span><?php endif; ?></div>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer"><button type="submit" class="btn btn-primary btn-touch" id="forecast-grid-save-btn">Save covers</button>
                <span class="fs-12 text-muted ms-2">An empty box clears a day. A number you type here is yours — Reservations will not replace it.</span></div>
        </div>
    </form>
    <?php endif; ?>

    <?= view('labor/partials/needs-table.php', ['needRows' => $needRows, 'days' => $days, 'day' => $day]) ?>

    <div class="card mb-3" id="forecast-ratios">
        <div class="card-header"><h6 class="card-title mb-0">Staffing ratios</h6></div>
        <div class="card-body p-3">
            <div class="fs-12 text-muted mb-3" id="forecast-ratios-note">How many people a position needs for the covers: one person per N covers, and never fewer than the minimum. Leave N empty for no recommendation from covers.</div>
            <?php if ($ratios === []): ?><div class="text-muted" id="forecast-ratios-empty">No positions yet.</div><?php endif; ?>
            <?php foreach ($ratios as $r): $pid = $r['position_id']; $per = $r['covers_per_staff'] === null ? '' : rtrim(rtrim(number_format($r['covers_per_staff'], 2, '.', ''), '0'), '.'); ?>
                <div class="border rounded p-3 mb-2" id="ratio-<?= $pid ?>">
                    <div class="fw-semibold mb-2"><?= pos_swatch($r['color'], 'dot') ?> <span id="ratio-<?= $pid ?>-name"><?= e($r['name']) ?></span></div>
                    <?php if ($may['ratios']): ?>
                        <form method="post" action="/labor/ratio.php" hx-post="/labor/ratio.php" hx-target="#flash" id="ratio-form-<?= $pid ?>">
                            <?= csrf_field() ?><input type="hidden" name="position" value="<?= $pid ?>"><input type="hidden" name="week" value="<?= e($ws) ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                            <div class="row g-2">
                                <div class="col-6"><label class="form-label fs-12 text-muted" for="ratio-form-<?= $pid ?>-field-per">One person per (covers)</label>
                                    <input type="number" inputmode="decimal" step="0.01" min="0.01" max="9999" name="covers_per_staff" id="ratio-form-<?= $pid ?>-field-per" class="form-control btn-touch" value="<?= e($per) ?>" placeholder="none"></div>
                                <div class="col-6"><label class="form-label fs-12 text-muted" for="ratio-form-<?= $pid ?>-field-min">At least</label>
                                    <input type="number" inputmode="numeric" step="1" min="0" max="200" name="min_staff" id="ratio-form-<?= $pid ?>-field-min" class="form-control btn-touch" value="<?= (int) $r['min_staff'] ?>"></div>
                            </div>
                            <button type="submit" class="btn btn-light btn-touch w-100 mt-2" id="ratio-form-<?= $pid ?>-save-btn">Save</button>
                        </form>
                    <?php else: ?>
                        <div id="ratio-<?= $pid ?>-words"><?= $r['covers_per_staff'] === null && $r['min_staff'] === 0 ? 'No ratio set.' : ($r['covers_per_staff'] === null ? '' : 'One per ' . e($per) . ' covers') . ($r['min_staff'] > 0 ? ($r['covers_per_staff'] === null ? '' : ', ') . 'at least ' . (int) $r['min_staff'] : '') ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
