<?php /** My requests (screen `my-requests`). Data: state, mine, claims, zone, notice */
$states = ['waiting' => 'Waiting', 'decided' => 'Decided', 'all' => 'All'];
$here = '/requests' . ($state !== 'waiting' ? '?state=' . $state : '');
?>
<?= view('shared/header.php', ['id' => 'requests', 'title' => 'My requests', 'crumbs' => [['Home', '/'], ['My requests', null]]]) ?>
<div class="main-content" id="requests-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="btn-group w-100 mb-3" role="group" aria-label="State" id="requests-tabs">
        <?php foreach ($states as $k => $l): ?><?= hx_link('/requests?state=' . $k, e($l), 'btn btn-touch ' . ($state === $k ? 'btn-primary' : 'btn-light'), 'id="requests-tab-' . e($k) . '"') ?><?php endforeach; ?>
    </div>
    <h6 class="text-muted text-uppercase fs-11">My offers, swaps and gives</h6>
    <div id="requests-mine">
        <?php if ($mine === []): ?><div class="card mb-3" id="requests-mine-empty"><div class="card-body text-muted text-center py-3">None <?= $state === 'waiting' ? 'waiting' : 'yet' ?>.</div></div><?php endif; ?>
        <?php foreach ($mine as $x) { echo view('exchanges/partials/exchange-card.php', ['x' => $x, 'mode' => 'summary', 'zone' => $zone, 'back' => $here]); } ?>
    </div>
    <h6 class="text-muted text-uppercase fs-11 mt-3">My claims</h6>
    <div id="requests-claims">
        <?php if ($claims === []): ?><div class="card mb-3" id="requests-claims-empty"><div class="card-body text-muted text-center py-3">No claims <?= $state === 'waiting' ? 'waiting' : 'yet' ?>.</div></div><?php endif; ?>
        <?php foreach ($claims as $x) { echo view('exchanges/partials/exchange-card.php', ['x' => $x, 'mode' => 'summary', 'zone' => $zone, 'back' => $here]); } ?>
    </div>
    <div class="fs-12 text-muted mt-3">Time off and availability requests will be listed here too.</div>
</div>
