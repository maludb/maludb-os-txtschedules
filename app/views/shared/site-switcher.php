<?php
/**
 * The restaurant switcher (scoped-applications.md §4.2): only for a person who holds several. Lists exactly the
 * sites they hold; a POST for any other is refused (html/switch-site.php).
 */
$sites = held_sites();
if (count($sites) < 2) { return; }
$current = current_site_id();
$here = current_site();
?>
<div class="dropdown nxl-h-item" id="site-switcher">
    <a href="javascript:void(0);" data-bs-toggle="dropdown" role="button" data-bs-auto-close="outside" class="nxl-head-link me-0" id="site-switcher-toggle" aria-label="Switch restaurant">
        <i class="feather-repeat"></i>
    </a>
    <div class="dropdown-menu dropdown-menu-end nxl-h-dropdown" id="site-switcher-menu">
        <div class="dropdown-header"><span class="fs-12 fw-medium text-muted text-uppercase">Restaurant</span></div>
        <?php foreach ($sites as $s): ?>
            <form method="post" action="/switch-site.php" class="px-2" id="site-switch-form-<?= (int) $s['scope_id'] ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="site" value="<?= (int) $s['scope_id'] ?>">
                <button type="submit" id="site-switch-<?= (int) $s['scope_id'] ?>" class="dropdown-item border-0 bg-transparent w-100 text-start<?= $s['scope_id'] === $current ? ' active' : '' ?>">
                    <i class="feather-map-pin"></i><span><?= e($s['name']) ?></span>
                    <?php if ($s['scope_id'] === $current): ?><i class="feather-check ms-auto"></i><?php endif; ?>
                </button>
            </form>
        <?php endforeach; ?>
    </div>
</div>
