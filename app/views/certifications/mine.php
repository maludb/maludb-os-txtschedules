<?php
/** My certifications (screen `my-certifications`). Data: me, name, cards, kinds, editCert, addKind, multi, notice */
?>
<?= view('shared/header.php', ['id' => 'my-certifications', 'title' => 'My certifications', 'crumbs' => [['Home', '/'], ['My certifications', null]]]) ?>
<div class="main-content" id="my-certifications-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?= view('staff/certifications.php', ['p' => ['member_id' => $me, 'display_name' => $name], 'self' => true, 'me' => $me, 'cards' => $cards, 'kinds' => $kinds, 'mayManage' => false, 'editCert' => $editCert, 'addKind' => $addKind,
        'multi' => $multi, 'here' => '/certifications/mine', 'mine' => true]) ?>
    <div class="mb-3"><?= hx_link('/staff/' . $me, 'My positions and hours', 'fw-semibold fs-12', 'id="my-certifications-profile-link"') ?></div>
</div>
