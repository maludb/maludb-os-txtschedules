<?php
/** The certifications screen (screen `certifications`). Data: site, kinds, due, counts, state, position, positions, posNames, may, notice, sites */
$siteId = (int) $site['site_id'];
$base = '/certifications/?site=' . $siteId . ($position ? '&position=' . (int) $position : '');
$here = $base . ($state !== null ? '&state=' . rawurlencode($state) : '');
?>
<?= view('shared/header.php', ['id' => 'certifications', 'title' => 'Certifications', 'crumbs' => [['Home', '/'], ['Certifications', null]]]) ?>
<div class="main-content" id="certifications-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if (count($sites) > 1): ?>
        <div class="d-flex flex-wrap gap-1 mb-3" id="certifications-sites">
            <?php foreach ($sites as $s): ?><?= hx_link('/certifications/?site=' . (int) $s['scope_id'], e($s['name']), 'btn btn-touch ' . ((int) $s['scope_id'] === $siteId ? 'btn-primary' : 'btn-light'), 'id="certifications-site-' . (int) $s['scope_id'] . '"') ?><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <h6 class="text-muted text-uppercase fs-11">Who needs attention</h6>
    <div class="d-flex flex-wrap gap-1 mb-2" id="certifications-states">
        <?= hx_link($base, 'All', 'btn btn-touch ' . ($state === null ? 'btn-primary' : 'btn-light'), 'id="certifications-state-all"') ?>
        <?php foreach (CERT_STATES as $k => $label): ?><?= hx_link($base . '&state=' . $k, e($label) . ' · ' . (int) $counts[$k], 'btn btn-touch ' . ($state === $k ? 'btn-primary' : 'btn-light'), 'id="certifications-state-' . e($k) . '"') ?><?php endforeach; ?>
    </div>
    <form method="get" action="/certifications/" class="mb-3" id="certifications-filter" hx-get="/certifications/" hx-target="#page-content" hx-push-url="true" hx-trigger="change">
        <input type="hidden" name="site" value="<?= $siteId ?>"><?php if ($state !== null): ?><input type="hidden" name="state" value="<?= e($state) ?>"><?php endif; ?>
        <label class="form-label fs-12 text-muted" for="certifications-filter-field-position">Position they work</label>
        <select name="position" id="certifications-filter-field-position" class="form-select btn-touch"><option value="">All positions</option>
            <?php foreach ($positions as $p): ?><option value="<?= (int) $p['position_id'] ?>" <?= (int) $position === (int) $p['position_id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select>
        <noscript><button type="submit" class="btn btn-light btn-touch mt-2">Show</button></noscript>
    </form>
    <?php if ($due === []): ?><div class="card mb-3"><div class="card-body text-center text-muted py-4" id="certifications-due-empty">Nothing needs attention<?= $state !== null ? ' in that state' : '' ?>.</div></div><?php endif; ?>
    <div class="mb-3" id="certifications-due">
        <?php foreach ($due as $r): ?><?= view('certifications/partials/due-row.php', ['r' => $r, 'here' => $here, 'siteId' => $siteId]) ?><?php endforeach; ?>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-2"><h6 class="text-muted text-uppercase fs-11 mb-0">Certifications at <?= e($site['name']) ?></h6>
        <?php if ($may['manage']): ?><?= hx_link('/certifications/kinds/new?site=' . $siteId, '<i class="feather-plus me-1"></i>Add one', 'btn btn-light btn-touch', 'id="certifications-add-btn"') ?><?php endif; ?></div>
    <?php if ($kinds === []): ?><div class="card mb-3"><div class="card-body text-muted" id="certifications-kinds-empty">This restaurant has no certifications yet.</div></div><?php endif; ?>
    <div class="row g-3 mb-3" id="certifications-kinds">
        <?php foreach ($kinds as $k): $kid = (int) $k['kind_id']; ?>
            <div class="col-12 col-md-6 col-xl-4">
                <div class="card h-100" id="kind-<?= $kid ?>"><div class="card-body p-3">
                    <div class="fw-bold" id="kind-<?= $kid ?>-name"><i class="feather-award me-1"></i><?= e($k['name']) ?></div>
                    <div class="fs-12 text-muted mt-1" id="kind-<?= $kid ?>-rule"><?= $k['track_expiry'] ? 'Expires — warned ' . (int) $k['warn_days'] . ' day' . ($k['warn_days'] === 1 ? '' : 's') . ' before' : 'Does not expire' ?></div>
                    <div class="mt-1" id="kind-<?= $kid ?>-positions">
                        <?php if ($k['required_position_ids'] === []): ?><span class="fs-12 text-muted">No position needs it</span><?php endif; ?>
                        <?php foreach ($k['required_position_ids'] as $pid): ?><span class="badge bg-soft-primary text-primary me-1"><?= e($posNames[$pid] ?? 'Position') ?></span><?php endforeach; ?>
                    </div>
                    <?php if ($may['manage']): ?>
                        <div class="row g-2 mt-2">
                            <div class="col-6"><?= hx_link('/certifications/kinds/' . $kid . '/edit', 'Change', 'btn btn-light btn-touch w-100', 'id="kind-' . $kid . '-edit-btn"') ?></div>
                            <div class="col-6"><form method="post" action="/certifications/kinds/archive.php" hx-post="/certifications/kinds/archive.php" hx-target="#flash" hx-confirm="Archive <?= e($k['name']) ?>? Cards already entered stay; it leaves the pickers and the rule.">
                                <?= csrf_field() ?><input type="hidden" name="kind" value="<?= $kid ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                                <button type="submit" class="btn btn-light btn-touch w-100" id="kind-<?= $kid ?>-archive-btn">Archive</button></form></div>
                        </div>
                    <?php endif; ?>
                </div></div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
