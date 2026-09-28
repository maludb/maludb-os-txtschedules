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
            ['my-schedule', '/my-schedule', 'feather-calendar', 'My schedule', 'schedule.view_own', false, 'Your own shifts by week or as a list, with who else is on. Shifts and the marketplace (slice 1) fill this.'],
            ['team-schedule', '/team-schedule', 'feather-users', 'Team schedule', 'schedule.view_own', false, 'The published week for your restaurant by day and position. Shifts and the marketplace (slice 1) fill this.'],
            ['marketplace', '/marketplace', 'feather-repeat', 'Marketplace', 'market.trade', false, 'Shifts that are up for offer, open or given to you, and whether you may take each. Shifts and the marketplace (slice 1) fill this.'],
            ['my-requests', '/requests', 'feather-inbox', 'My requests', 'schedule.view_own', false, 'Your requests and trades and what became of them. Shifts and the marketplace (slice 1) fill this.'],
            ['availability', '/availability', 'feather-clock', 'Availability', 'availability.edit', false, 'When you can work each week. Availability and time off (slice 3) fill this.'],
            ['time-off', '/time-off', 'feather-sun', 'Time off', 'availability.edit', false, 'Time off you have asked for, your balances, and who is off. Availability and time off (slice 3) fill this.'],
            ['announcements-list', '/announcements/', 'feather-volume-2', 'Announcements', 'schedule.view_own', false, 'What managers have posted for your restaurant. Announcements and notifications (slice 6) fill this.'],
        ],
        'Manage' => [
            ['builder', '/builder', 'feather-grid', 'Builder', 'schedule.build', false, 'The week as a grid: staff and positions down, days across, hours, labor against the budget and the warnings. The week builder (slice 2) fills this.'],
            ['approvals', '/approvals', 'feather-check-circle', 'Approvals', 'requests.approve', false, 'Trades, time off and availability waiting for a manager. Shifts and the marketplace (slice 1) and availability and time off (slice 3) fill this.'],
            ['coverage', '/coverage', 'feather-life-buoy', 'Coverage', 'coverage.fill', false, 'Who could take a gap, fewest hours first, and ask one or several. Shifts and the marketplace (slice 1) fill this.'],
            ['templates-list', '/templates/', 'feather-copy', 'Templates', 'schedule.build', false, 'Saved weeks to start a new week from. The week builder (slice 2) fills this.'],
            ['staff-list', '/staff/', 'feather-user-check', 'Staff', 'schedule.build', false, 'The people who work here, with position and main restaurant. People and positions (slice 4) fill this.'],
            ['positions-list', '/positions/', 'feather-tag', 'Positions', 'schedule.build', false, 'The restaurant\'s positions and their default rates. People and positions (slice 4) fill this.'],
            ['certifications', '/certifications/', 'feather-award', 'Certifications', 'schedule.build', false, 'Certification kinds, and who is expired, due, missing one or has one to verify. People and positions (slice 4) fill this.'],
            ['forecast', '/forecast', 'feather-trending-up', 'Forecast', 'schedule.build', false, 'Covers expected per day-part and the staffing they call for. Labor and forecast (slice 5) fill this.'],
            ['budget', '/budget', 'feather-dollar-sign', 'Budget', 'labor.view', false, 'The weekly labor budget against what is scheduled. Labor and forecast (slice 5) fill this.'],
            ['reports', '/reports/', 'feather-bar-chart-2', 'Reports', 'schedule.build', false, 'Hours, labor against budget, open shifts, trades, overtime and overrides. Settings, rules and reports (slice 7) fill this.'],
        ],
        'Restaurant' => [
            ['site-settings', '/site/', 'feather-settings', 'Settings', 'settings.manage', false, 'The restaurant\'s week, trade and reminder settings, time off hours per day and overtime. Settings, rules and reports (slice 7) fill this.'],
            ['rules', '/rules/', 'feather-shield', 'Rules', 'settings.manage', false, 'The rules the restaurant runs and how strict each is. Settings, rules and reports (slice 7) fill this.'],
        ],
        'Me' => [
            ['my-certifications', '/certifications/mine', 'feather-award', 'My certifications', 'schedule.view_own', false, 'Your certifications, and adding one. People and positions (slice 4) fill this.'],
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
