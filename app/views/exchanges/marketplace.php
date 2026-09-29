<?php
/** Marketplace (screen `marketplace`). Data: tab, tabs [key => [label, count]], resultsHtml, notice, site, siteQuery */
?>
<?= view('shared/header.php', ['id' => 'marketplace', 'title' => 'Marketplace', 'crumbs' => [['Home', '/'], [$site['name'], null], ['Marketplace', null]]]) ?>
<div class="main-content" id="marketplace-content" data-entity="exchange">
    <?= view('shared/notice.php', ['notice' => $notice, 'link' => $noticeLink ?? null]) ?>
    <div class="btn-group w-100 mb-3" role="group" aria-label="Marketplace" id="marketplace-tabs">
        <?php foreach ($tabs as $key => [$label, $count]): ?>
            <?= hx_link('/marketplace?tab=' . $key . $siteQuery, e($label) . ($count > 0 ? ' <span class="badge bg-' . ($tab === $key ? 'light text-primary' : 'primary') . ' ms-1">' . (int) $count . '</span>' : ''),
                'btn btn-touch ' . ($tab === $key ? 'btn-primary' : 'btn-light'), 'id="marketplace-tab-' . e($key) . '"') ?>
        <?php endforeach; ?>
    </div>
    <?= $resultsHtml ?>
</div>
