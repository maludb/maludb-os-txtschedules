<?php
/**
 * The app shell (design-system nxl skeleton), PHONE FIRST. Wraps a screen's page HTML.
 * Data: title, content, activeNav?, screen?, entity?, recordId?
 * Load-bearing: nxl-* classes, #mobile-collapse, #menu-mini-button, asset order, theme-customizer-init.min.js last.
 * #page-content is the HTMX swap target. At 375 px: the header names the restaurant, a bottom tab bar carries the five
 * staff screens (Home, Schedule, Market, Requests, More = the sidebar), the command bar sits above it. From 992 px:
 * the sidebar. The menu is app/features/shell/nav.php — an item shows only for a right held at the current site.
 */
$title     = $title     ?? app_name();
$content   = $content   ?? '';
$activeNav = $activeNav ?? '';
$screen    = $screen    ?? '';
$entity    = $entity    ?? '';
$recordId  = $recordId  ?? '';
$m         = current_member();
$site      = current_site();
$initials  = strtoupper(mb_substr((string) ($m['display_name'] ?? '?'), 0, 1));
$roleWords = ['staff' => 'Staff', 'shift_lead' => 'Shift lead', 'manager' => 'Manager', 'admin' => 'Admin'];
$roleBadge = $site !== null ? ($roleWords[$site['role_key'] ?? ''] ?? '') : '';
if ($roleBadge === '' && is_super_admin()) { $roleBadge = 'Super-admin'; }
// The zone is named only when a person's sites lie in more than one (NF-2).
$zones = array_unique(array_column(held_sites(), 'timezone'));
$zoneName = ($site !== null && count($zones) > 1)
    ? (new DateTimeImmutable('now', new DateTimeZone((string) $site['timezone'])))->format('T') : '';
$badges = [];
if ($site !== null && ($m['member_kind'] ?? '') === 'human') {
    require_once APP_ROOT . '/app/features/exchanges/queries.php';
    $n = count_approvals(db(), (int) $site['scope_id'], (int) ($m['id'] ?? 0));
    if ($n > 0) { $badges['approvals'] = $n; }
}
$navlink = function (string $id, string $url, string $icon, string $label) use ($activeNav, $badges): string {
    $active = $activeNav === $id ? ' active' : '';
    return '<li class="nxl-item" id="nav-' . e($id) . '">'
        . '<a class="nxl-link' . $active . '" href="' . e($url) . '"'
        . ' hx-get="' . e($url) . '" hx-target="#page-content" hx-swap="innerHTML"'
        . ' hx-push-url="' . e($url) . '">'
        . '<span class="nxl-micon"><i class="' . e($icon) . '"></i></span>'
        . '<span class="nxl-mtext">' . e($label) . '</span>'
        . (isset($badges[$id]) ? '<span class="badge bg-danger ms-auto" id="nav-' . e($id) . '-count">' . (int) $badges[$id] . '</span>' : '')
        . '</a></li>';
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta http-equiv="x-ua-compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="description" content="<?= e(app_name()) ?>" />
    <meta name="theme-color" content="#3454d1" />
    <!-- htmx 2 swaps nothing above 3xx by default; a refused write (422) re-renders its form or lands in #flash (HX-Retarget). -->
    <meta name="htmx-config" content='{"responseHandling":[{"code":"204","swap":false},{"code":"[23]..","swap":true},{"code":"422","swap":true,"error":false},{"code":"[45]..","swap":false,"error":true}]}'>
    <meta name="csrf-token" id="csrf-token-meta" content="<?= e(csrf_token()) ?>" />
    <title><?= e($title) ?> · <?= e(app_name()) ?></title>
    <link rel="manifest" href="/manifest.webmanifest" />
    <link rel="apple-touch-icon" href="/assets/images/apple-touch-icon.png" />
    <link rel="shortcut icon" type="image/png" href="/assets/images/favicon.png" />
    <link rel="stylesheet" type="text/css" href="/assets/css/bootstrap.min.css" />
    <link rel="stylesheet" type="text/css" href="/assets/vendors/css/vendors.min.css" />
    <link rel="stylesheet" type="text/css" href="/assets/vendors/css/select2.min.css" />
    <link rel="stylesheet" type="text/css" href="/assets/css/theme.min.css" />
    <link rel="stylesheet" type="text/css" href="/assets/css/app-overrides.css" />
</head>
<body>
    <!--! [Start] Navigation (the sidebar on a desktop; "More" on a phone) !-->
    <nav class="nxl-navigation" id="left-sidenav">
        <div class="navbar-wrapper">
            <div class="m-header">
                <a href="/" class="b-brand" hx-get="/" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="/">
                    <img src="/assets/images/logo-full.png" alt="<?= e(app_name()) ?>" class="logo logo-lg" />
                    <img src="/assets/images/logo-abbr.png" alt="" class="logo logo-sm" />
                </a>
            </div>
            <div class="navbar-content">
                <ul class="nxl-navbar">
                    <?= $navlink('dashboard', '/', 'feather-home', 'Home') ?>
                    <?php foreach (nav_groups() as $groupLabel => $items): ?>
                        <?php $shown = array_filter($items, static fn (array $i): bool => nav_has_right($i[4])); if ($shown === []) { continue; } ?>
                        <li class="nxl-item nxl-caption"><label><?= e($groupLabel) ?></label></li>
                        <?php foreach ($shown as $item) { echo $navlink($item[0], $item[1], $item[2], $item[3]); } ?>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </nav>
    <!--! [End] Navigation !-->

    <!--! [Start] Header !-->
    <header class="nxl-header" id="top-header">
        <div class="header-wrapper">
            <div class="header-left d-flex align-items-center gap-3">
                <a href="javascript:void(0);" class="nxl-head-mobile-toggler" id="mobile-collapse">
                    <div class="hamburger hamburger--arrowturn">
                        <div class="hamburger-box"><div class="hamburger-inner"></div></div>
                    </div>
                </a>
                <div class="nxl-navigation-toggle">
                    <a href="javascript:void(0);" id="menu-mini-button"><i class="feather-align-left"></i></a>
                    <a href="javascript:void(0);" id="menu-expend-button" style="display: none"><i class="feather-arrow-right"></i></a>
                </div>
                <?php if ($site !== null): ?>
                    <div class="site-name d-flex align-items-center" id="header-site-name">
                        <i class="feather-map-pin me-2 text-primary"></i>
                        <span class="fw-semibold text-dark text-truncate" id="header-site-name-text"><?= e($site['name']) ?></span>
                        <?php if ($zoneName !== ''): ?><span class="badge bg-soft-secondary text-secondary ms-2" id="header-site-zone"><?= e($zoneName) ?></span><?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="header-right ms-auto">
                <div class="d-flex align-items-center">
                    <?= view('shared/site-switcher.php') ?>
                    <div class="nxl-h-item d-none d-sm-flex">
                        <a href="<?= e(launcher_url()) ?>" class="nxl-head-link me-0" data-bs-toggle="tooltip" title="All applications" id="header-launcher-link">
                            <i class="feather-grid"></i>
                        </a>
                    </div>
                    <div class="nxl-h-item dark-light-theme">
                        <a href="javascript:void(0);" class="nxl-head-link me-0 dark-button"><i class="feather-moon"></i></a>
                        <a href="javascript:void(0);" class="nxl-head-link me-0 light-button" style="display:none"><i class="feather-sun"></i></a>
                    </div>
                    <div class="dropdown nxl-h-item">
                        <a href="javascript:void(0);" data-bs-toggle="dropdown" role="button" data-bs-auto-close="outside" id="header-user-menu" aria-label="Your account">
                            <span class="avatar-text avatar-md user-avtar me-0"><?= e($initials) ?></span>
                        </a>
                        <div class="dropdown-menu dropdown-menu-end nxl-h-dropdown nxl-user-dropdown">
                            <div class="dropdown-header">
                                <div class="d-flex align-items-center">
                                    <span class="avatar-text avatar-md user-avtar"><?= e($initials) ?></span>
                                    <div>
                                        <h6 class="text-dark mb-0" id="header-user-name"><?= e($m['display_name'] ?? 'Member') ?>
                                            <?php if ($roleBadge !== ''): ?><span class="badge bg-soft-primary text-primary ms-1" id="header-role-badge"><?= e($roleBadge) ?></span><?php endif; ?>
                                        </h6>
                                        <span class="fs-12 fw-medium text-muted"><?= e($m['email'] ?? '') ?></span>
                                    </div>
                                </div>
                            </div>
                            <div class="dropdown-divider"></div>
                            <a href="/settings/tokens/" class="dropdown-item" hx-get="/settings/tokens/" hx-target="#page-content" hx-push-url="/settings/tokens/" id="header-tokens-link">
                                <i class="feather-key"></i><span>Tokens</span>
                            </a>
                            <a href="<?= e(launcher_url()) ?>" class="dropdown-item" id="header-launcher-item">
                                <i class="feather-grid"></i><span>All applications</span>
                            </a>
                            <div class="dropdown-divider"></div>
                            <form method="post" action="/logout.php" class="px-2">
                                <?= csrf_field() ?>
                                <button type="submit" id="header-logout-btn" class="dropdown-item border-0 bg-transparent w-100 text-start">
                                    <i class="feather-log-out"></i><span>Sign out</span>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </header>
    <!--! [End] Header !-->

    <!--! [Start] Main Content !-->
    <main class="nxl-container app-has-assistant-bar app-has-tabbar">
        <div id="flash"></div>
        <div class="nxl-content" id="page-content"
             data-screen="<?= e($screen) ?>" data-entity="<?= e($entity) ?>" data-record-id="<?= e($recordId) ?>">
            <?= $content ?>
        </div>
        <footer class="footer" id="page-footer">
            <p class="fs-11 text-muted fw-medium text-uppercase mb-0 copyright">
                <span>© <?= date('Y') ?> <?= e(app_name()) ?> · an application for the Business OS</span>
            </p>
            <div class="d-flex align-items-center gap-4">
                <a href="/activity" class="fs-11 fw-semibold text-uppercase" hx-get="/activity" hx-target="#page-content" hx-push-url="/activity">Activity</a>
                <a href="<?= e(launcher_url()) ?>" class="fs-11 fw-semibold text-uppercase">Applications</a>
            </div>
        </footer>
    </main>
    <!--! [End] Main Content !-->

    <?= view('shared/assistant-bar.php') ?>

    <!--! [Start] The phone's tab bar (hidden from 992 px, where the sidebar is) !-->
    <nav class="app-tabbar d-lg-none" id="app-tabbar" aria-label="Main">
        <?php foreach (nav_tabs() as [$tid, $turl, $ticon, $tlabel, $tright]): ?>
            <?php if ($tright !== null && !has_right($tright)) { continue; } ?>
            <a href="<?= e($turl) ?>" id="tab-<?= e($tid) ?>" class="app-tab<?= $activeNav === $tid ? ' active' : '' ?>"
               hx-get="<?= e($turl) ?>" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="<?= e($turl) ?>">
                <i class="<?= e($ticon) ?>"></i><span><?= e($tlabel) ?></span>
            </a>
        <?php endforeach; ?>
        <a href="javascript:void(0);" id="tab-more" class="app-tab" role="button" aria-label="More"><i class="feather-menu"></i><span>More</span></a>
    </nav>
    <!--! [End] tab bar !-->

    <script src="/assets/vendors/js/vendors.min.js"></script>
    <script src="/assets/vendors/js/select2.min.js"></script>
    <script src="/assets/vendors/js/select2-active.min.js"></script>
    <script src="/assets/vendors/js/htmx.min.js"></script>
    <script>
        // CSRF over HTMX (php-session-auth): every non-GET request carries the session's token.
        document.body.addEventListener('htmx:configRequest', function (e) {
            if (e.detail.verb && e.detail.verb.toLowerCase() !== 'get') {
                var meta = document.querySelector('#csrf-token-meta');
                if (meta) { e.detail.headers['X-CSRF-Token'] = meta.content; }
            }
        });
        // "More" is the sidebar: the same toggle the hamburger uses.
        var more = document.getElementById('tab-more');
        if (more) { more.addEventListener('click', function () { var t = document.getElementById('mobile-collapse'); if (t) { t.click(); } }); }
        // A swapped screen: title, sidebar and tab highlight, theme widgets that bind on ready.
        document.body.addEventListener('htmx:afterSwap', function (e) {
            var pc = document.getElementById('page-content');
            if (!pc) return;
            var xt = e.detail && e.detail.xhr ? e.detail.xhr.getResponseHeader('HX-Title') : null;
            if (xt) { document.title = decodeURIComponent(xt); }
            if (e.detail && e.detail.target && e.detail.target.id === 'flash') { window.scrollTo({ top: 0, behavior: 'smooth' }); }
            var xhr = e.detail && e.detail.xhr;
            if (xhr && xhr.getResponseHeader('X-Screen') !== null) {
                pc.dataset.screen = xhr.getResponseHeader('X-Screen') || '';
                pc.dataset.entity = xhr.getResponseHeader('X-Entity') || '';
                pc.dataset.recordId = xhr.getResponseHeader('X-Record-Id') || '';
            }
            var screen = pc.dataset.screen || '';
            document.querySelectorAll('.nxl-navbar .nxl-link, #app-tabbar .app-tab').forEach(function (a) { a.classList.remove('active'); });
            var link = document.querySelector('#nav-' + screen + ' .nxl-link');
            if (link) { link.classList.add('active'); }
            var tab = document.getElementById('tab-' + screen);
            if (tab) { tab.classList.add('active'); }
            if (window.jQuery) {
                if (jQuery.fn.tooltip) { jQuery('[data-bs-toggle="tooltip"]').tooltip(); }
                if (jQuery.fn.select2) { jQuery('#page-content select[data-select2-selector]').each(function () { if (!jQuery(this).hasClass('select2-hidden-accessible')) { jQuery(this).select2({ width: '100%' }); } }); }
            }
            var nav = document.querySelector('nav.nxl-navigation');
            if (nav && nav.classList.contains('mob-navigation-active')) { nav.classList.remove('mob-navigation-active'); var ov = document.querySelector('.nxl-menu-overlay'); if (ov) ov.remove(); }
        });
        // A refused request (4xx) is retargeted to #flash by the server; a network failure says so.
        document.body.addEventListener('htmx:responseError', function (e) {
            var f = document.getElementById('flash');
            if (f && e.detail.xhr && e.detail.xhr.status >= 500) { f.innerHTML = '<div class="alert alert-danger m-3">Something went wrong. Try again.</div>'; }
        });
    </script>
    <script src="/assets/js/builder.js"></script>
    <script src="/assets/js/common-init.min.js"></script>
    <script src="/assets/js/theme-customizer-init.min.js"></script>
</body>
</html>
