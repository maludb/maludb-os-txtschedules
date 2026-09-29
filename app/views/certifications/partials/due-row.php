<?php
/** One row of the due list. Data: r (a find_certifications_due() row), here, siteId. The action is Verify (a card to check) or Add the card. */
[$label, $col] = cert_state_badge($r['state']);
$key = $r['certification_id'] !== null ? 'cert-' . $r['certification_id'] : 'missing-' . $r['member_id'] . '-' . $r['kind_id'];
$mid = (int) $r['member_id'];
?>
<div class="card mb-2 due-row" id="due-<?= e($key) ?>"><div class="card-body p-3">
    <div class="d-flex justify-content-between align-items-start gap-2">
        <div>
            <div class="fw-bold" id="due-<?= e($key) ?>-who"><?= hx_link(with_back('/staff/' . $mid, $here), e($r['display_name']), 'text-dark') ?></div>
            <div id="due-<?= e($key) ?>-kind"><?= e($r['kind_name']) ?></div>
        </div>
        <span class="badge bg-soft-<?= e($col) ?> text-<?= e($col) ?> text-nowrap" id="due-<?= e($key) ?>-state"><?= e($label) ?></span>
    </div>
    <div class="fs-12 text-muted mt-1" id="due-<?= e($key) ?>-words"><?= e(cert_due_words($r)) ?></div>
    <div class="mt-2">
        <?php if ($r['state'] === 'to_verify' && $r['certification_id'] !== null): ?>
            <form method="post" action="/staff/certifications/verify.php" hx-post="/staff/certifications/verify.php" hx-target="#flash">
                <?= csrf_field() ?><input type="hidden" name="certification" value="<?= (int) $r['certification_id'] ?>"><input type="hidden" name="verified" value="yes"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                <button type="submit" class="btn btn-primary btn-touch w-100" id="due-<?= e($key) ?>-verify-btn">Verify</button></form>
        <?php else: ?>
            <?= hx_link(with_back('/staff/' . $mid . '?kind=' . (int) $r['kind_id'], $here) . '#certification-form', 'Add the card', 'btn btn-light btn-touch w-100', 'id="due-' . e($key) . '-add-btn"') ?>
        <?php endif; ?>
    </div>
</div></div>
