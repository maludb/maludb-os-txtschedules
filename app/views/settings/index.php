<?php
/** My settings (screen `settings`). Data: tab, prefs, default, refusal, feed, once, osChannels, notice */
?>
<?= view('shared/header.php', ['id' => 'settings', 'title' => 'My settings', 'crumbs' => [['Home', '/'], ['My settings', null]]]) ?>
<div class="main-content" id="settings-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="d-flex flex-wrap gap-1 mb-3" id="settings-tabs">
        <?= hx_link('/settings/?tab=notify', 'How I am told', 'btn btn-touch ' . ($tab === 'notify' ? 'btn-primary' : 'btn-light'), 'id="settings-tab-notify"') ?>
        <?= hx_link('/settings/?tab=calendar', 'My calendar link', 'btn btn-touch ' . ($tab === 'calendar' ? 'btn-primary' : 'btn-light'), 'id="settings-tab-calendar"') ?>
        <?= hx_link('/settings/tokens/', 'Tokens', 'btn btn-touch btn-light', 'id="settings-tab-tokens"') ?>
    </div>
    <?= $tab === 'calendar' ? view('settings/partials/calendar.php', ['feed' => $feed, 'once' => $once]) : view('settings/partials/prefs.php', ['prefs' => $prefs, 'default' => $default, 'refusal' => $refusal, 'osChannels' => $osChannels]) ?>
</div>
