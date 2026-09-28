<?php
/** The dashboard (screen `dashboard`). Data: me, site, next, week, waiting, announcements, tz, roleWords
 *  Each card is an empty state naming the slice that fills it (docs/build-specs/sso-shell.md); the shape is fixed now. */
$link = static fn (string $url, string $text, string $cls = ''): string => '<a href="' . e($url) . '" class="' . e($cls) . '" hx-get="' . e($url) . '" hx-target="#page-content" hx-push-url="' . e($url) . '">' . $text . '</a>';
$when = static fn (?string $utc, string $fmt = 'D M j, g:i A'): string => e(format_ts($utc, $tz, $fmt));
?>
<div class="page-header" id="dashboard-header">
    <div class="page-header-left d-flex align-items-center">
        <div class="page-header-title"><h5 class="m-b-10">Home</h5></div>
        <ul class="breadcrumb">
            <li class="breadcrumb-item"><?= e($site['name'] ?? 'txtSchedules') ?></li>
        </ul>
    </div>
</div>
<div class="main-content" id="dashboard-content">
    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card stretch stretch-full" id="home-next-shift">
                <div class="card-header"><h5 class="card-title">My next shift</h5><?= has_right('schedule.view_own') ? $link('/my-schedule', 'Schedule', 'fs-12') : '' ?></div>
                <div class="card-body">
                    <?php if ($next === null): ?>
                        <div class="d-flex align-items-center gap-3" id="home-next-shift-empty">
                            <span class="avatar-text avatar-lg rounded"><i class="feather-calendar"></i></span>
                            <div><div class="fw-semibold">Nothing scheduled yet</div>
                                <div class="fs-12 text-muted">When your manager publishes a week, your next shift and who is on with you appear here.</div></div>
                        </div>
                    <?php else: ?>
                        <div class="fw-bold fs-5" id="home-next-shift-when"><?= $when($next['starts_at']) ?> – <?= $when($next['ends_at'], 'g:i A') ?></div>
                        <div class="text-muted"><?= e($next['position_name']) ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card stretch stretch-full" id="home-waiting">
                <div class="card-header"><h5 class="card-title">Waiting for me</h5></div>
                <div class="card-body">
                    <?php if ($waiting === []): ?>
                        <p class="text-muted mb-0" id="home-waiting-empty">Nothing waits for you. Trades offered to you and requests you have made show here.</p>
                    <?php else: ?>
                        <ul class="list-unstyled mb-0">
                            <?php foreach ($waiting as $i => $w): ?>
                                <li id="home-waiting-<?= (int) $i ?>"><?= $link($w['href'], e($w['text'])) ?> <span class="fs-12 text-muted"><?= $when($w['when']) ?></span></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card stretch stretch-full" id="home-my-week">
                <div class="card-header"><h5 class="card-title">My week</h5></div>
                <div class="card-body">
                    <?php if ($week === []): ?>
                        <p class="text-muted mb-0" id="home-my-week-empty">No shifts in the next seven days.</p>
                    <?php else: ?>
                        <ul class="list-unstyled mb-0">
                            <?php foreach ($week as $s): ?>
                                <li id="home-week-shift-<?= (int) $s['shift_id'] ?>"><span class="fw-semibold"><?= $when($s['starts_at'], 'D M j') ?></span> <?= $when($s['starts_at'], 'g:i A') ?> – <?= $when($s['ends_at'], 'g:i A') ?> <span class="text-muted">· <?= e($s['position_name']) ?></span></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card stretch stretch-full" id="home-announcements">
                <div class="card-header"><h5 class="card-title">Announcements</h5><?= has_right('schedule.view_own') ? $link('/announcements/', 'All', 'fs-12') : '' ?></div>
                <div class="card-body">
                    <?php if ($announcements === []): ?>
                        <p class="text-muted mb-0" id="home-announcements-empty">No announcements. What managers post for <?= e($site['name'] ?? 'your restaurant') ?> appears here.</p>
                    <?php else: ?>
                        <?php foreach ($announcements as $a): ?>
                            <div class="mb-2" id="home-announcement-<?= (int) $a['announcement_id'] ?>"><div class="fw-semibold"><?= e($a['title']) ?></div><div class="fs-12 text-muted text-truncate-2-line"><?= e($a['body']) ?></div></div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
