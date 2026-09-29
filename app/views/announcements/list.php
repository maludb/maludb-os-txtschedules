<?php
/** The announcements screen (screen `announcements-list`). Data: site, items, readers, mayPost, mayManage, me, unread, notice, sites */
$siteId = (int) $site['site_id'];
?>
<?= view('shared/header.php', ['id' => 'announcements-list', 'title' => 'Announcements', 'crumbs' => [['Home', '/'], ['Announcements', null]]]) ?>
<div class="main-content" id="announcements-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if (count($sites) > 1): ?>
        <div class="d-flex flex-wrap gap-1 mb-3" id="announcements-sites">
            <?php foreach ($sites as $s): ?><?= hx_link('/announcements/?site=' . (int) $s['scope_id'], e($s['name']), 'btn btn-touch ' . ((int) $s['scope_id'] === $siteId ? 'btn-primary' : 'btn-light'), 'id="announcements-site-' . (int) $s['scope_id'] . '"') ?><?php endforeach; ?>
        </div>
    <?php endif; ?>
    <div class="d-flex justify-content-between align-items-center mb-2 gap-2">
        <h6 class="text-muted text-uppercase fs-11 mb-0" id="announcements-heading"><?= e($site['name']) ?><?= $unread > 0 ? ' · ' . (int) $unread . ' unread' : '' ?></h6>
        <?php if ($mayPost): ?><?= hx_link('/announcements/new?site=' . $siteId, '<i class="feather-plus me-1"></i>Post one', 'btn btn-primary btn-touch', 'id="announcements-add-btn"') ?><?php endif; ?>
    </div>
    <?php if ($items === []): ?><div class="card mb-3"><div class="card-body text-center text-muted py-4" id="announcements-empty">Nothing has been posted for you at <?= e($site['name']) ?>.</div></div><?php endif; ?>
    <div class="row g-3 mb-3" id="announcements-cards">
        <?php foreach ($items as $a): $aid = $a['announcement_id']; $unreadOne = !$a['read_by_me'] && $a['posted_by'] !== $me;      // what you posted yourself is not news to you ?>
            <div class="col-12 col-xl-6">
                <div class="card h-100 <?= $unreadOne ? 'border-primary' : '' ?>" id="announcement-<?= $aid ?>"><div class="card-body p-3">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div class="fw-bold" id="announcement-<?= $aid ?>-title"><i class="feather-volume-2 me-1"></i><?= e($a['title']) ?></div>
                        <div class="text-end">
                            <?php if ($unreadOne): ?><span class="badge bg-primary" id="announcement-<?= $aid ?>-unread">New</span><?php endif; ?>
                            <?php if ($a['pinned']): ?><span class="badge bg-soft-warning text-warning" id="announcement-<?= $aid ?>-pinned">Pinned until <?= e(format_date($a['pinned_until'])) ?></span><?php endif; ?>
                        </div>
                    </div>
                    <div class="fs-12 text-muted mt-1" id="announcement-<?= $aid ?>-meta"><?= e($a['posted_by_name'] ?? 'A manager') ?> · <?= e(format_ts($a['created_at'], (string) $site['timezone'])) ?> · <?= e(ANNOUNCE_AUDIENCES[$a['audience']]) ?></div>
                    <div class="mt-2 text-break" id="announcement-<?= $aid ?>-body"><?= announcement_html($a['body']) ?></div>
                    <div class="row g-2 mt-2">
                        <?php if ($unreadOne): ?>
                            <div class="col-12"><form method="post" action="/announcements/read.php" hx-post="/announcements/read.php" hx-target="#flash">
                                <?= csrf_field() ?><input type="hidden" name="announcement" value="<?= $aid ?>"><input type="hidden" name="return_to" value="/announcements/?site=<?= $siteId ?>">
                                <button type="submit" class="btn btn-primary btn-touch w-100" id="announcement-<?= $aid ?>-read-btn">Got it</button></form></div>
                        <?php endif; ?>
                    </div>
                    <?php if ($mayPost): $rd = $readers[$aid] ?? []; ?>
                        <details class="mt-2" id="announcement-<?= $aid ?>-readers">
                            <summary class="btn btn-light btn-touch w-100 text-start" id="announcement-<?= $aid ?>-read-count"><i class="feather-eye me-1"></i><?= (int) ($a['read_count'] ?? 0) ?> read</summary>
                            <?php if ($rd === []): ?><div class="fs-12 text-muted p-2">Nobody has read it yet.</div><?php endif; ?>
                            <ul class="list-unstyled mb-0 p-2 fs-12"><?php foreach ($rd as $r): ?><li><?= e($r['name']) ?> <span class="text-muted">· <?= e(format_ts($r['read_at'], (string) $site['timezone'])) ?></span></li><?php endforeach; ?></ul>
                        </details>
                        <?php if ($a['posted_by'] === $me || $mayManage): ?>
                            <form method="post" action="/announcements/remove.php" hx-post="/announcements/remove.php" hx-target="#flash" class="mt-2" hx-confirm="Remove &quot;<?= e($a['title']) ?>&quot;? It leaves everyone's list; notices already sent stay sent.">
                                <?= csrf_field() ?><input type="hidden" name="announcement" value="<?= $aid ?>"><input type="hidden" name="return_to" value="/announcements/?site=<?= $siteId ?>">
                                <button type="submit" class="btn btn-light btn-touch w-100" id="announcement-<?= $aid ?>-remove-btn">Remove</button></form>
                        <?php endif; ?>
                    <?php endif; ?>
                </div></div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
