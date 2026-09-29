<?php /** My calendar link. Data: feed, once (the new link, shown this once, or null) */ ?>
<div class="card mb-3" id="calendar-link"><div class="card-header"><h5 class="card-title mb-0">Add my shifts to my phone's calendar</h5></div><div class="card-body">
    <p class="fs-12 text-muted">A private link your calendar app reads: your own published shifts for the next 60 days — nobody else's, and no pay. Anyone who has the link can see your shifts, so keep it to yourself.</p>
    <?php if ($once !== null): ?>
        <label class="form-label fs-12 text-muted" for="calendar-link-field-url">Your link — copy it now, it is shown only once</label>
        <input type="text" readonly class="form-control btn-touch mb-2" id="calendar-link-field-url" value="<?= e($once) ?>" onfocus="this.select()">
        <div class="fs-12 text-muted mb-3">In your calendar app choose "Add calendar from URL" (Google Calendar) or "Add subscribed calendar" (Apple Calendar) and paste it.</div>
    <?php elseif ($feed !== null): ?>
        <div class="alert alert-light border fs-12" id="calendar-link-state">You have a link (made <?= e(format_date(substr((string) $feed['made'], 0, 10))) ?>). For your safety it cannot be shown again — make a new one if you lost it.</div>
    <?php else: ?>
        <div class="alert alert-light border fs-12" id="calendar-link-state">You have no link yet.</div>
    <?php endif; ?>
    <form method="post" action="/settings/calendar-feed.php" hx-post="/settings/calendar-feed.php" hx-target="#flash" <?= $feed !== null ? 'hx-confirm="Make a new link? The old one stops working, so your phone\'s calendar must be given the new one."' : '' ?>>
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-<?= $feed === null ? 'primary' : 'light' ?> btn-touch w-100" id="calendar-link-make-btn"><?= $feed === null ? 'Make my calendar link' : 'Make a new link' ?></button>
    </form>
</div></div>
