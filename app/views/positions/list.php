<?php
/** The positions list (screen `positions-list`). Data: site, rows, may, notice, sites. PAY appears only when may['labor']; the rate form only when may['pay']. */
$siteId = (int) $site['site_id'];
$here = '/positions/?site=' . $siteId;
?>
<?= view('shared/header.php', ['id' => 'positions-list', 'title' => 'Positions', 'crumbs' => [['Home', '/'], ['Positions', null]]]) ?>
<div class="main-content" id="positions-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if (count($sites) > 1): ?>
        <div class="d-flex flex-wrap gap-1 mb-3" id="positions-list-sites">
            <?php foreach ($sites as $s): ?><?= hx_link('/positions/?site=' . (int) $s['scope_id'], e($s['name']), 'btn btn-touch ' . ((int) $s['scope_id'] === $siteId ? 'btn-primary' : 'btn-light'), 'id="positions-list-site-' . (int) $s['scope_id'] . '"') ?><?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if ($may['manage']): ?><div class="mb-3"><?= hx_link('/positions/new?site=' . $siteId, '<i class="feather-plus me-1"></i>Add a position', 'btn btn-primary btn-touch w-100', 'id="positions-list-add-btn"') ?></div><?php endif; ?>
    <?php if ($rows === []): ?><div class="card"><div class="card-body text-center text-muted py-4" id="positions-list-empty">No positions at <?= e($site['name']) ?> yet.</div></div><?php endif; ?>
    <div class="row g-3" id="positions-list-cards">
        <?php foreach ($rows as $r): ?><div class="col-12 col-md-6 col-xl-4"><?= view('positions/partials/card.php', ['r' => $r, 'may' => $may, 'here' => $here, 'site' => $site]) ?></div><?php endforeach; ?>
    </div>
</div>
