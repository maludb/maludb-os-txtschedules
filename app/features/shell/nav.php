<?php
declare(strict_types=1);

/**
 * The shell's menu — ONE table: the sidebar, the phone's tab bar and the placeholder screens all read it, so a menu
 * item and the right that opens its screen can never disagree (docs/build-specs/sso-shell.md). An item shows only
 * when the person holds its right AT THE CURRENT SITE; a screen a later slice builds answers 200 with an empty state
 * to those who hold the right and 403 to those who do not.
 *   [id, url, icon, label, right, built?, what fills it]
 */
function nav_groups(): array
{
    return [
        'Schedule' => [
            ['my-schedule', '/my-schedule', 'feather-calendar', 'My schedule', 'schedule.view_own', true, ''],
            ['team-schedule', '/team-schedule', 'feather-users', 'Team schedule', 'schedule.view_own', true, ''],
            ['marketplace', '/marketplace', 'feather-repeat', 'Marketplace', 'market.trade', true, ''],
            ['my-requests', '/requests', 'feather-inbox', 'My requests', 'schedule.view_own', true, ''],
            ['availability', '/availability', 'feather-clock', 'Availability', 'availability.edit', true, ''],
            ['time-off', '/time-off', 'feather-sun', 'Time off', 'availability.edit', true, ''],
            ['announcements-list', '/announcements/', 'feather-volume-2', 'Announcements', 'schedule.view_own', false, 'What managers have posted for your restaurant. Announcements and notifications (slice 6) fill this.'],
        ],
        'Manage' => [
            ['builder', '/builder', 'feather-grid', 'Builder', 'schedule.build', true, ''],
            ['approvals', '/approvals', 'feather-check-circle', 'Approvals', 'requests.approve|market.approve_day', true, ''],
            ['coverage', '/coverage', 'feather-life-buoy', 'Coverage', 'coverage.fill', true, ''],
            ['templates-list', '/templates/', 'feather-copy', 'Templates', 'schedule.build', true, ''],
            ['staff-list', '/staff/', 'feather-user-check', 'Staff', 'schedule.build', true, ''],
            ['positions-list', '/positions/', 'feather-tag', 'Positions', 'schedule.build', true, ''],
            ['certifications', '/certifications/', 'feather-award', 'Certifications', 'schedule.build', true, ''],
            ['forecast', '/forecast', 'feather-trending-up', 'Forecast', 'schedule.build', true, ''],
            ['budget', '/budget', 'feather-dollar-sign', 'Budget', 'labor.view', true, ''],
            ['reports', '/reports/', 'feather-bar-chart-2', 'Reports', 'schedule.build', false, 'Hours, labor against budget, open shifts, trades, overtime and overrides. Settings, rules and reports (slice 7) fill this.'],
        ],
        'Restaurant' => [
            ['site-settings', '/site/', 'feather-settings', 'Settings', 'settings.manage', false, 'The restaurant\'s week, trade and reminder settings, time off hours per day and overtime. Settings, rules and reports (slice 7) fill this.'],
            ['rules', '/rules/', 'feather-shield', 'Rules', 'settings.manage', false, 'The rules the restaurant runs and how strict each is. Settings, rules and reports (slice 7) fill this.'],
        ],
        'Me' => [
            ['my-certifications', '/certifications/mine', 'feather-award', 'My certifications', 'schedule.view_own', true, ''],
            ['settings', '/settings/', 'feather-sliders', 'My settings', 'schedule.view_own', false, 'How you are told (email and text), which events, and your calendar link. Announcements and notifications (slice 6) fill this.'],
            ['tokens', '/settings/tokens/', 'feather-key', 'Tokens', 'schedule.view_own', true, ''],
            ['activity', '/activity', 'feather-activity', 'Activity', 'schedule.view_own', true, ''],
        ],
    ];
}

/** The five staff tabs on a phone: Home, Schedule, Marketplace, Requests, More (the sidebar). */
function nav_tabs(): array
{
    return [
        ['dashboard', '/', 'feather-home', 'Home', null],
        ['my-schedule', '/my-schedule', 'feather-calendar', 'Schedule', 'schedule.view_own'],
        ['marketplace', '/marketplace', 'feather-repeat', 'Market', 'market.trade'],
        ['my-requests', '/requests', 'feather-inbox', 'Requests', 'schedule.view_own'],
    ];
}

function nav_item(string $id): ?array
{
    foreach (nav_groups() as $items) {
        foreach ($items as $i) {
            if ($i[0] === $id) {
                return $i;
            }
        }
    }
    return null;
}

/** A menu right may name several joined by "|": any one of them opens the item (a shift lead reaches Approvals for same-day trades). */
function nav_has_right(string $spec): bool
{
    foreach (explode('|', $spec) as $right) {
        if (has_right($right)) {
            return true;
        }
    }
    return false;
}

/** A screen a later slice builds: 403 without its right at the current site, else the shell with an empty state. */
function render_nav_stub(string $id): void
{
    $item = nav_item($id);
    if ($item === null) {
        refuse(404, 'Not found.');
    }
    require_login();
    require_human();
    require_right($item[4]);
    render_module_stub($item[3], $id, $item[6]);
}

/** A link that navigates by HTMX into #page-content and still works as a plain link (progressive enhancement). $html is already escaped. */
function hx_link(string $url, string $html, string $class = '', string $extra = ''): string
{
    return '<a href="' . e($url) . '"' . ($class !== '' ? ' class="' . e($class) . '"' : '') . ($extra !== '' ? ' ' . $extra : '')
        . ' hx-get="' . e($url) . '" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="' . e($url) . '">' . $html . '</a>';
}

/** A path a page may send a person back to: local, no scheme, no protocol-relative — else null. */
function safe_local_path(?string $path): ?string
{
    if ($path === null || $path === '' || $path[0] !== '/' || str_starts_with($path, '//') || str_contains($path, '\\') || preg_match('/[\x00-\x1f]/', $path)) {
        return null;
    }
    return $path;
}

/** "Back to …" from the ?back= a link carried (click-around rule): [url, label] or null. */
function back_link(): ?array
{
    $back = safe_local_path($_GET['back'] ?? null);
    if ($back === null) {
        return null;
    }
    $path = parse_url($back, PHP_URL_PATH) ?: '/';
    $labels = ['/' => 'Home', '/my-schedule' => 'My schedule', '/team-schedule' => 'Team schedule', '/marketplace' => 'Marketplace',
               '/approvals' => 'Approvals', '/requests' => 'My requests', '/coverage' => 'Coverage', '/builder' => 'Builder', '/builder/day' => 'Day view',
               '/templates/' => 'Templates', '/availability' => 'Availability', '/time-off' => 'Time off', '/time-off/balances' => 'Balances', '/site/time-off' => 'Time-off types',
               '/staff/' => 'Staff', '/positions/' => 'Positions', '/certifications/' => 'Certifications', '/certifications/mine' => 'My certifications', '/forecast' => 'Forecast', '/budget' => 'Budget'];
    foreach ($labels as $p => $label) {
        if ($path === $p) {
            return [$back, $label];
        }
    }
    if (preg_match('#^/templates/\d+$#', $path)) {
        return [$back, 'the template'];
    }
    if (preg_match('#^/staff/\d+$#', $path)) {
        return [$back, 'the person'];
    }
    if (preg_match('#^/time-off/\d+$#', $path)) {
        return [$back, 'the request'];
    }
    if (preg_match('#^/(shifts|exchanges)/\d+$#', $path)) {
        return [$back, str_starts_with($path, '/shifts') ? 'the shift' : 'the trade'];
    }
    return null;
}

/** The URL of this same page, for a ?back= (path and query as requested). */
function here_url(): string
{
    return (string) ($_SERVER['REQUEST_URI'] ?? '/');
}

function with_back(string $url, string $here): string
{
    return $url . (str_contains($url, '?') ? '&' : '?') . 'back=' . rawurlencode($here);
}
