<?php
/** Post an announcement (screen `announcement-add`). Data: site, audience, positions, people, f, sites */
$siteId = (int) $site['site_id'];
?>
<?= view('shared/header.php', ['id' => 'announcement-add', 'title' => 'Post an announcement', 'crumbs' => [['Home', '/'], ['Announcements', '/announcements/?site=' . $siteId], ['Post', null]]]) ?>
<div class="main-content" id="announcement-add-content">
    <?php if (count($sites) > 1): ?>
        <div class="d-flex flex-wrap gap-1 mb-3" id="announcement-add-sites">
            <?php foreach ($sites as $s): ?><?= hx_link('/announcements/new?site=' . (int) $s['scope_id'] . '&audience=' . e($audience), e($s['name']), 'btn btn-touch ' . ((int) $s['scope_id'] === $siteId ? 'btn-primary' : 'btn-light'), 'id="announcement-add-site-' . (int) $s['scope_id'] . '"') ?><?php endforeach; ?>
        </div>
    <?php endif; ?>
    <form method="post" action="/announcements/save.php" hx-post="/announcements/save.php" hx-target="#page-content" id="announcement-form">
        <?= csrf_field() ?><input type="hidden" name="site" value="<?= $siteId ?>">
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0"><?= e($site['name']) ?></h5></div><div class="card-body">
            <label class="form-label fs-12 text-muted" for="announcement-form-field-title">Title</label>
            <input type="text" name="title" id="announcement-form-field-title" class="form-control btn-touch mb-3" maxlength="120" required value="<?= e($f['title'] ?? '') ?>" placeholder="Parking behind the building this Friday">
            <label class="form-label fs-12 text-muted" for="announcement-form-field-body">Message</label>
            <textarea name="body" id="announcement-form-field-body" class="form-control mb-1" rows="6" maxlength="4000" required placeholder="What people need to know. Plain text — links are made clickable."><?= e($f['body'] ?? '') ?></textarea>
            <div class="fs-12 text-muted mb-3">Up to 4,000 characters. No replies — people can only mark it read.</div>
            <label class="form-label fs-12 text-muted" for="announcement-form-field-pin">Pin it to the top until (optional)</label>
            <input type="date" name="pinned_until" id="announcement-form-field-pin" class="form-control btn-touch" value="<?= e($f['pinned_until'] ?? '') ?>">
        </div></div>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Who it is for</h5></div><div class="card-body" id="announcement-form-audience">
            <?php foreach (ANNOUNCE_AUDIENCES as $k => $label): ?>
                <label class="d-flex align-items-center gap-2 border rounded px-3 mb-2 btn-touch" for="announcement-form-field-audience-<?= e($k) ?>"><input type="radio" class="form-check-input mt-0" name="audience" value="<?= e($k) ?>" id="announcement-form-field-audience-<?= e($k) ?>" <?= $audience === $k ? 'checked' : '' ?>><?= e($label) ?></label>
            <?php endforeach; ?>
            <label class="form-label fs-12 text-muted mt-2" for="announcement-form-field-position">If one position</label>
            <select name="position" id="announcement-form-field-position" class="form-select btn-touch mb-3"><option value="">Choose a position…</option>
                <?php foreach ($positions as $p): ?><option value="<?= (int) $p['position_id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?></select>
            <div class="form-label fs-12 text-muted">If named people</div>
            <input type="hidden" name="members[]" value="">
            <?php if ($people === []): ?><div class="text-muted">Nobody works here yet.</div><?php endif; ?>
            <?php foreach ($people as $p): ?>
                <label class="d-flex align-items-center gap-2 border rounded px-3 mb-2 btn-touch" for="announcement-form-field-person-<?= (int) $p['member_id'] ?>"><input type="checkbox" class="form-check-input mt-0" name="members[]" value="<?= (int) $p['member_id'] ?>" id="announcement-form-field-person-<?= (int) $p['member_id'] ?>"><?= e($p['name']) ?></label>
            <?php endforeach; ?>
        </div></div>
        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="announcement-form-review-btn">Review and send</button>
        <?= hx_link('/announcements/?site=' . $siteId, 'Cancel', 'btn btn-light btn-touch w-100', 'id="announcement-form-cancel-link"') ?>
    </form>
</div>
