<?php
/** Templates (screen `templates-list`). Data: site, templates, week (a date or null), notice */
$siteId = (int) $site['site_id'];
?>
<?= view('shared/header.php', ['id' => 'templates-list', 'title' => 'Templates', 'crumbs' => [['Home', '/'], ['Builder', builder_url($siteId, $week ?? week_start_of((new DateTimeImmutable('now', new DateTimeZone((string) $site['timezone'])))->format('Y-m-d'), (int) $site['week_start']))], ['Templates', null]]]) ?>
<div class="main-content" id="templates-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($week !== null): ?><div class="alert alert-info fs-12" id="templates-list-target" role="note">Starting the week of <?= e(week_label($week)) ?> — open a template and choose <strong>Start a week from it</strong>.</div><?php endif; ?>
    <?php if ($templates === []): ?>
        <div class="card" id="templates-list-empty"><div class="card-body text-center py-4">
            <div class="avatar-text avatar-xl rounded mx-auto mb-3"><i class="feather-copy"></i></div>
            <div class="fw-semibold">No templates yet</div>
            <div class="text-muted fs-12 mt-1">Build a week, then choose Save as template on the builder.</div>
        </div></div>
    <?php endif; ?>
    <?php foreach ($templates as $t): $url = '/templates/' . (int) $t['template_id'] . ($week !== null ? '?week=' . $week : ''); ?>
        <a href="<?= e($url) ?>" class="card shift-card mb-2 text-decoration-none" id="template-card-<?= (int) $t['template_id'] ?>" hx-get="<?= e($url) ?>" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="<?= e($url) ?>">
            <div class="card-body p-3 d-flex justify-content-between align-items-center gap-2">
                <div class="min-w-0"><div class="fw-bold text-dark" id="template-card-<?= (int) $t['template_id'] ?>-name"><?= e($t['name']) ?></div>
                    <div class="text-muted fs-12"><?= (int) $t['shift_count'] ?> shift<?= (int) $t['shift_count'] === 1 ? '' : 's' ?> · saved <?= e(format_ts($t['created_at'], (string) $site['timezone'], 'M j, Y')) ?></div></div>
                <i class="feather-chevron-right text-muted"></i>
            </div>
        </a>
    <?php endforeach; ?>
</div>
