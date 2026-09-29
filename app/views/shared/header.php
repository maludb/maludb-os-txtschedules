<?php
/** A page header. Data: id, title, crumbs = [[label, url|null], …] (every crumb links but the last), back? = [url, label] ("Back to …"). */
$back = $back ?? null;
?>
<div class="page-header" id="<?= e($id) ?>-header">
    <div class="page-header-left d-flex align-items-center">
        <div class="page-header-title"><h5 class="m-b-10"><?= e($title) ?></h5></div>
        <ul class="breadcrumb">
            <?php foreach ($crumbs as [$label, $url]): ?>
                <li class="breadcrumb-item"><?= $url === null ? e($label) : hx_link($url, e($label)) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
<?php if ($back !== null): ?>
    <div class="px-3 pb-2"><?= hx_link($back[0], '<i class="feather-arrow-left me-1"></i>Back to ' . e($back[1]), 'fs-12 fw-semibold', 'id="' . e($id) . '-back"') ?></div>
<?php endif; ?>
