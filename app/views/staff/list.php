<?php
/** The staff list (screen `staff-list`). Data: site, rows, more, page, q, position, on, positions, sites, notice */
$siteId = (int) $site['site_id'];
$here = '/staff/?site=' . $siteId . ($q !== '' ? '&q=' . rawurlencode($q) : '') . ($position ? '&position=' . (int) $position : '') . ($on !== '' ? '&on_schedule=' . rawurlencode($on) : '') . ($page > 1 ? '&page=' . (int) $page : '');
?>
<?= view('shared/header.php', ['id' => 'staff-list', 'title' => 'Staff', 'crumbs' => [['Home', '/'], ['Staff', null]]]) ?>
<div class="main-content" id="staff-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if (count($sites) > 1): ?>
        <div class="d-flex flex-wrap gap-1 mb-3" id="staff-list-sites">
            <?php foreach ($sites as $s): ?><?= hx_link('/staff/?site=' . (int) $s['scope_id'], e($s['name']), 'btn btn-touch ' . ((int) $s['scope_id'] === $siteId ? 'btn-primary' : 'btn-light'), 'id="staff-list-site-' . (int) $s['scope_id'] . '"') ?><?php endforeach; ?>
        </div>
    <?php endif; ?>
    <form method="get" action="/staff/" class="card mb-3" id="staff-list-filter" hx-get="/staff/" hx-target="#page-content" hx-push-url="true">
        <div class="card-body p-3">
            <input type="hidden" name="site" value="<?= $siteId ?>">
            <label class="form-label fs-12 text-muted" for="staff-list-filter-field-q">Name</label>
            <input type="search" name="q" id="staff-list-filter-field-q" class="form-control btn-touch mb-2" maxlength="60" value="<?= e($q) ?>" placeholder="Search by name">
            <div class="row g-2">
                <div class="col-6"><label class="form-label fs-12 text-muted" for="staff-list-filter-field-position">Position</label>
                    <select name="position" id="staff-list-filter-field-position" class="form-select btn-touch"><option value="">All</option>
                        <?php foreach ($positions as $p): ?><option value="<?= (int) $p['position_id'] ?>" <?= (int) $position === (int) $p['position_id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
                <div class="col-6"><label class="form-label fs-12 text-muted" for="staff-list-filter-field-on">On the schedule</label>
                    <select name="on_schedule" id="staff-list-filter-field-on" class="form-select btn-touch"><option value="">Everyone</option><option value="yes" <?= $on === 'yes' ? 'selected' : '' ?>>On the schedule</option><option value="no" <?= $on === 'no' ? 'selected' : '' ?>>Off the schedule</option></select></div>
            </div>
            <button type="submit" class="btn btn-primary btn-touch w-100 mt-3" id="staff-list-filter-btn">Show</button>
        </div>
    </form>
    <div class="fs-12 text-muted mb-2" id="staff-list-count"><?= count($rows) ?> <?= count($rows) === 1 ? 'person' : 'people' ?> at <?= e($site['name']) ?><?= $more || $page > 1 ? ' on this page' : '' ?></div>
    <?php if ($rows === []): ?>
        <div class="card"><div class="card-body text-center text-muted py-4" id="staff-list-empty">Nobody matches. People appear here once the kernel gives them a role at <?= e($site['name']) ?>.</div></div>
    <?php endif; ?>
    <div class="row g-3" id="staff-list-cards">
        <?php foreach ($rows as $r): ?><div class="col-12 col-md-6 col-xl-4"><?= view('staff/partials/card.php', ['r' => $r, 'siteId' => $siteId, 'back' => $here]) ?></div><?php endforeach; ?>
    </div>
    <?php if ($more || $page > 1): ?>
        <div class="d-flex justify-content-between mt-3" id="staff-list-pager">
            <?= $page > 1 ? hx_link('/staff/?' . http_build_query(array_filter(['site' => $siteId, 'q' => $q, 'position' => $position, 'on_schedule' => $on, 'page' => $page - 1 > 1 ? $page - 1 : null])), 'Newer', 'btn btn-light btn-touch', 'id="staff-list-prev"') : '<span></span>' ?>
            <?= $more ? hx_link('/staff/?' . http_build_query(array_filter(['site' => $siteId, 'q' => $q, 'position' => $position, 'on_schedule' => $on, 'page' => $page + 1])), 'More', 'btn btn-light btn-touch', 'id="staff-list-next"') : '' ?>
        </div>
    <?php endif; ?>
</div>
