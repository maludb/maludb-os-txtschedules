<?php
/** The restaurant's day-parts (screen `day-parts`; settings.manage). Data: site, parts (all, live first), editing, adding, notice, sites */
$siteId = (int) $site['site_id'];
$here = day_parts_url($siteId);
$fmt = static fn (array $d): string => day_part_hours((string) $d['starts_at'], (string) $d['ends_at']);
$live = array_filter($parts, static fn (array $d): bool => !$d['archived']);
$archived = array_filter($parts, static fn (array $d): bool => $d['archived']);
?>
<?= view('shared/header.php', ['id' => 'day-parts', 'title' => 'Day-parts', 'crumbs' => [['Home', '/'], ['Settings', site_url($siteId)], ['Day-parts', null]]]) ?>
<div class="main-content" id="day-parts-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if (count($sites) > 1): ?>
        <div class="d-flex flex-wrap gap-1 mb-3" id="day-parts-sites">
            <?php foreach ($sites as $x): ?><?= hx_link(day_parts_url((int) $x['scope_id']), e($x['name']), 'btn btn-touch ' . ((int) $x['scope_id'] === $siteId ? 'btn-primary' : 'btn-light'), 'id="day-parts-site-' . (int) $x['scope_id'] . '"') ?><?php endforeach; ?>
        </div>
    <?php endif; ?>
    <div class="fs-12 text-muted mb-3" id="day-parts-note"><?= e($site['name']) ?> — the forecast, the needs table and the day view speak in these. A service name is the word Reservations uses for the same service; that is how its covers land in the right day-part.</div>
    <div class="d-flex justify-content-between align-items-center mb-2"><h6 class="text-muted text-uppercase fs-11 mb-0">Day-parts</h6>
        <?= hx_link($here . '&add=1', '<i class="feather-plus me-1"></i>Add a day-part', 'btn btn-light btn-touch', 'id="day-parts-add-btn"') ?></div>
    <?php if ($adding || $editing !== null): $d = $editing; ?>
        <form method="post" action="/site/day-part.php" hx-post="/site/day-part.php" hx-target="#flash" class="card mb-3" id="day-part-form">
            <?= csrf_field() ?><input type="hidden" name="site" value="<?= $siteId ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
            <?php if ($d !== null): ?><input type="hidden" name="day_part" value="<?= (int) $d['day_part_id'] ?>"><?php endif; ?>
            <div class="card-header"><h5 class="card-title mb-0"><?= $d !== null ? 'Change ' . e($d['name']) : 'Add a day-part' ?></h5></div>
            <div class="card-body">
                <label class="form-label fs-12 text-muted" for="day-part-form-field-name">Name</label>
                <input type="text" name="name" id="day-part-form-field-name" class="form-control btn-touch mb-3" maxlength="40" required value="<?= e($d['name'] ?? '') ?>" placeholder="Brunch">
                <div class="row g-2 mb-3">
                    <div class="col-6"><label class="form-label fs-12 text-muted" for="day-part-form-field-starts">Starts</label>
                        <input type="time" name="starts_at" id="day-part-form-field-starts" class="form-control btn-touch" required value="<?= e($d['starts_at'] ?? '') ?>"></div>
                    <div class="col-6"><label class="form-label fs-12 text-muted" for="day-part-form-field-ends">Ends</label>
                        <input type="time" name="ends_at" id="day-part-form-field-ends" class="form-control btn-touch" required value="<?= e($d['ends_at'] ?? '') ?>"></div>
                </div>
                <div class="fs-12 text-muted mb-3">An end before the start means it runs past midnight.</div>
                <label class="form-label fs-12 text-muted" for="day-part-form-field-service">Service name in Reservations</label>
                <input type="text" name="service_name" id="day-part-form-field-service" class="form-control btn-touch mb-1" maxlength="40" value="<?= e($d['service_name'] ?? '') ?>" placeholder="Brunch">
                <div class="fs-12 text-muted mb-3">The word Reservations gives this service. Leave it empty if Reservations has none.</div>
                <label class="form-label fs-12 text-muted" for="day-part-form-field-order">Order in the list</label>
                <input type="number" name="sort_order" id="day-part-form-field-order" class="form-control btn-touch mb-3" min="0" max="1000" value="<?= (int) ($d['sort_order'] ?? count($live) + 1) ?>">
                <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="day-part-form-save-btn">Save</button>
                <?= hx_link($here, 'Cancel', 'btn btn-light btn-touch w-100', 'id="day-part-form-cancel-link"') ?>
            </div>
        </form>
    <?php endif; ?>
    <div class="row g-3 mb-3" id="day-parts-list">
        <?php if ($live === []): ?><div class="col-12"><div class="card"><div class="card-body text-muted" id="day-parts-empty">No day-parts yet. Add lunch and dinner, or whatever your restaurant serves.</div></div></div><?php endif; ?>
        <?php foreach ($live as $d): $id = (int) $d['day_part_id']; ?>
            <div class="col-12 col-md-6 col-xl-4">
                <div class="card h-100" id="day-part-<?= $id ?>"><div class="card-body p-3">
                    <div class="fw-semibold" id="day-part-<?= $id ?>-name"><?= e($d['name']) ?></div>
                    <div class="fs-13" id="day-part-<?= $id ?>-hours"><?= e($fmt($d)) ?><?= (string) $d['ends_at'] <= (string) $d['starts_at'] ? ' <span class="badge bg-soft-secondary text-secondary">past midnight</span>' : '' ?></div>
                    <div class="fs-12 text-muted mt-1" id="day-part-<?= $id ?>-service"><?= $d['service_name'] !== null && $d['service_name'] !== '' ? 'Reservations calls this ' . e($d['service_name']) : 'No service name — Reservations\' covers do not land here' ?></div>
                    <div class="row g-2 mt-2">
                        <div class="col-6"><?= hx_link($here . '&edit=' . $id, 'Change', 'btn btn-light btn-touch w-100', 'id="day-part-' . $id . '-edit-btn"') ?></div>
                        <div class="col-6"><form method="post" action="/site/day-part-archive.php" hx-post="/site/day-part-archive.php" hx-target="#flash" hx-confirm="Archive <?= e($d['name']) ?>? Its old forecasts are kept.">
                            <?= csrf_field() ?><input type="hidden" name="day_part" value="<?= $id ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                            <button type="submit" class="btn btn-light btn-touch w-100" id="day-part-<?= $id ?>-archive-btn">Archive</button></form></div>
                    </div>
                </div></div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php if ($archived !== []): ?>
        <h6 class="text-muted text-uppercase fs-11">Archived</h6>
        <div class="card mb-3" id="day-parts-archived"><ul class="list-group list-group-flush">
            <?php foreach ($archived as $d): ?><li class="list-group-item text-muted" id="day-part-<?= (int) $d['day_part_id'] ?>-archived"><?= e($d['name']) ?> <span class="fs-12"><?= e($fmt($d)) ?></span></li><?php endforeach; ?>
        </ul></div>
    <?php endif; ?>
</div>
