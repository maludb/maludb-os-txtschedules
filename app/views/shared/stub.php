<?php /** A screen a later slice builds. Data: title, what, screen */ ?>
<div class="page-header" id="<?= e($screen ?? 'stub') ?>-header">
    <div class="page-header-left d-flex align-items-center">
        <div class="page-header-title"><h5 class="m-b-10"><?= e($title) ?></h5></div>
        <ul class="breadcrumb">
            <li class="breadcrumb-item"><a href="/" hx-get="/" hx-target="#page-content" hx-push-url="/">Home</a></li>
            <li class="breadcrumb-item"><?= e($title) ?></li>
        </ul>
    </div>
</div>
<div class="main-content">
    <div class="row">
        <div class="col-lg-12">
            <div class="card stretch stretch-full" id="<?= e($screen ?? 'stub') ?>-empty">
                <div class="card-body text-center py-5">
                    <div class="avatar-text avatar-xl rounded mx-auto mb-3"><i class="feather-tool"></i></div>
                    <h5 class="mb-2"><?= e($title) ?> is not built yet</h5>
                    <p class="text-muted mb-0"><?= e($what) ?></p>
                </div>
            </div>
        </div>
    </div>
</div>
