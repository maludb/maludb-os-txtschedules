<?php
/** The confirm page before an announcement goes out. Data: site, f (the fields), reach (people, email, sms, nobody), positionName, named */
$siteId = (int) $site['site_id'];
$n = (int) $reach['people'];
$channels = $reach['email'] > 0 && $reach['sms'] > 0 ? 'email and text' : ($reach['email'] > 0 ? 'email' : ($reach['sms'] > 0 ? 'text' : 'the announcements page only'));
?>
<?= view('shared/header.php', ['id' => 'announcement-confirm', 'title' => 'Send this announcement?', 'crumbs' => [['Home', '/'], ['Announcements', '/announcements/?site=' . $siteId], ['Post', '/announcements/new?site=' . $siteId], ['Confirm', null]]]) ?>
<div class="main-content" id="announcement-confirm-content">
    <div class="alert alert-info" role="status" id="announcement-confirm-reach">
        <strong>This will be sent to <?= $n ?> <?= $n === 1 ? 'person' : 'people' ?> by <?= e($channels) ?>.</strong>
        <div class="fs-12 mt-1"><?= (int) $reach['email'] ?> by email, <?= (int) $reach['sms'] ?> by text — each person's own choices<?= $reach['nobody'] > 0 ? '; ' . (int) $reach['nobody'] . ' will only see it on the announcements page' : '' ?>.</div>
    </div>
    <div class="card mb-3"><div class="card-body p-3">
        <div class="fw-bold" id="announcement-confirm-title"><?= e($f['title']) ?></div>
        <div class="fs-12 text-muted mt-1" id="announcement-confirm-for"><?= e($site['name']) ?> · <?= e(ANNOUNCE_AUDIENCES[$f['audience']]) ?><?= $positionName !== null ? ': ' . e($positionName) : '' ?><?= $f['audience'] === 'people' ? ': ' . e(implode(', ', array_column($named, 'name'))) : '' ?><?= $f['pinned_until'] !== null ? ' · pinned until ' . e(format_date($f['pinned_until'])) : '' ?></div>
        <div class="mt-2 text-break" id="announcement-confirm-body"><?= announcement_html($f['body']) ?></div>
    </div></div>
    <form method="post" action="/announcements/save.php" hx-post="/announcements/save.php" hx-target="#flash" id="announcement-confirm-form">
        <?= csrf_field() ?><input type="hidden" name="confirm" value="yes"><input type="hidden" name="site" value="<?= $siteId ?>">
        <input type="hidden" name="title" value="<?= e($f['title']) ?>"><input type="hidden" name="body" value="<?= e($f['body']) ?>"><input type="hidden" name="audience" value="<?= e($f['audience']) ?>">
        <?php if ($f['position_id'] !== null): ?><input type="hidden" name="position" value="<?= (int) $f['position_id'] ?>"><?php endif; ?>
        <?php foreach ($f['member_ids'] as $m): ?><input type="hidden" name="members[]" value="<?= (int) $m ?>"><?php endforeach; ?>
        <?php if ($f['pinned_until'] !== null): ?><input type="hidden" name="pinned_until" value="<?= e($f['pinned_until']) ?>"><?php endif; ?>
        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="announcement-confirm-send-btn">Send to <?= $n ?> <?= $n === 1 ? 'person' : 'people' ?></button>
        <?= hx_link('/announcements/new?site=' . $siteId . '&audience=' . e($f['audience']), 'Change it', 'btn btn-light btn-touch w-100', 'id="announcement-confirm-change-link"') ?>
    </form>
</div>
