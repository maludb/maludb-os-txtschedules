<?php
/** The region the marketplace swaps: cards for one tab. Data: tab, rows [[x, check]], zone, back. Refreshed on exchangeChanged and every 30 s while the tab is visible. */
$empty = ['grabs' => 'Nothing is up for grabs right now.', 'forme' => 'Nothing is waiting for your answer.', 'claims' => 'You have not asked for any shifts.'];
?>
<div id="marketplace-results" hx-get="<?= e('/marketplace?tab=' . $tab . ($siteQuery ?? '')) ?>" hx-trigger="exchangeChanged from:body, every 30s [document.visibilityState === 'visible']" hx-target="this" hx-swap="outerHTML">
    <?php if ($rows === []): ?>
        <div class="card" id="marketplace-empty"><div class="card-body text-center text-muted py-4"><?= e($empty[$tab]) ?></div></div>
    <?php endif; ?>
    <?php foreach ($rows as [$x, $check]) { echo view('exchanges/partials/exchange-card.php', ['x' => $x, 'mode' => $tab, 'check' => $check, 'zone' => $zone, 'back' => $back]); } ?>
</div>
