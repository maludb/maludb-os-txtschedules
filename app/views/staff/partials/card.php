<?php
/** One person as a card. Data: r (a find_staff() row), siteId, back. Never a wage, an email or a phone. */
$id = (int) $r['member_id'];
?>
<div class="card h-100 staff-card" id="staff-card-<?= $id ?>"><div class="card-body p-3">
    <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
        <div class="fw-bold" id="staff-card-<?= $id ?>-name"><?= hx_link(with_back('/staff/' . $id, $back), e($r['display_name']), 'text-dark') ?></div>
        <div class="text-end" id="staff-card-<?= $id ?>-chips">
            <?php if ($r['expired_certifications'] > 0): ?><span class="badge bg-soft-danger text-danger" id="staff-card-<?= $id ?>-expired"><i class="feather-award me-1"></i>Expired certification<?= $r['expired_certifications'] > 1 ? 's' : '' ?></span><?php endif; ?>
            <?php if ($r['is_minor']): ?><span class="badge bg-soft-warning text-warning" id="staff-card-<?= $id ?>-minor">Minor<?= $r['minor_until'] !== null ? ' until ' . e(format_date($r['minor_until'])) : '' ?></span><?php endif; ?>
            <?php if (!$r['on_schedule']): ?><span class="badge bg-soft-secondary text-secondary" id="staff-card-<?= $id ?>-off">Off the schedule</span><?php endif; ?>
        </div>
    </div>
    <div class="mb-1" id="staff-card-<?= $id ?>-positions">
        <?php if ($r['positions'] === []): ?><span class="fs-12 text-muted">No position here yet</span><?php endif; ?>
        <?php foreach ($r['positions'] as $p): ?><span class="badge bg-soft-secondary text-dark me-1"><?= pos_swatch($p['color'], 'dot') ?> <?= e($p['name']) ?><?= $p['is_primary'] ? ' <i class="feather-star ms-1" aria-label="main position"></i>' : '' ?></span><?php endforeach; ?>
    </div>
    <div class="fs-12 text-muted" id="staff-card-<?= $id ?>-main"><?= $r['main_site_id'] === $siteId ? 'Main restaurant: here' : 'Main restaurant: ' . e($r['main_site_name'] ?? ($r['main_site_id'] === null ? 'not set' : 'another restaurant')) ?><?= $r['max_hours_week'] !== null ? ' · up to ' . e(hours_label($r['max_hours_week'])) . ' a week' : '' ?></div>
</div></div>
