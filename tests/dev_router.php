<?php
/**
 * Router for `php -S 127.0.0.1:8191 -t html tests/dev_router.php` — the vhost's rewrites (deploy/apache-txtschedules.conf),
 * so the proofs run without Apache: /sso, /sso/logout, /api/v1/health, and the canonical URLs (/x/new, /x/{id}/edit,
 * /x/{id}, /x → x.php or x/index.php). Anything that is a real file is served as it is.
 */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$root = dirname(__DIR__) . '/html';
if ($path !== '/' && is_file($root . $path)) {
    if (str_ends_with($path, '.webmanifest')) { header('Content-Type: application/manifest+json'); readfile($root . $path); return true; }
    return false;                                             // a static file or a real .php file
}
if (preg_match('#^/api/v1/calendar/([a-f0-9]{48})\.ics$#', $path, $m)) { $_GET['token'] = $m[1]; $_REQUEST['token'] = $m[1]; $_SERVER['SCRIPT_NAME'] = '/api/v1/calendar.php'; $_SERVER['SCRIPT_FILENAME'] = $root . '/api/v1/calendar.php'; chdir($root . '/api/v1'); require $root . '/api/v1/calendar.php'; return true; }
$map = ['/sso' => '/sso.php', '/sso/logout' => '/sso/logout.php', '/api/v1/health' => '/api/v1/health.php'];
$rel = rtrim($path, '/') ?: '/';
if (isset($map[$rel])) { $target = $map[$rel]; }
elseif ($rel === '/') { $target = '/index.php'; }
elseif (preg_match('#^/(.+)/new$#', $rel, $m) && is_file($root . '/' . $m[1] . '/form.php')) { $target = '/' . $m[1] . '/form.php'; }
elseif (preg_match('#^/(.+)/([0-9]+)/edit$#', $rel, $m) && is_file($root . '/' . $m[1] . '/form.php')) { $target = '/' . $m[1] . '/form.php'; $_GET['id'] = $m[2]; $_REQUEST['id'] = $m[2]; }
elseif (preg_match('#^/(.+)/([0-9]+)$#', $rel, $m) && is_file($root . '/' . $m[1] . '/view.php')) { $target = '/' . $m[1] . '/view.php'; $_GET['id'] = $m[2]; $_REQUEST['id'] = $m[2]; }
elseif (is_file($root . $rel . '.php')) { $target = $rel . '.php'; }
elseif (is_file($root . $rel . '/index.php')) { $target = $rel . '/index.php'; }
else { http_response_code(404); echo 'Not found'; return true; }
$_SERVER['SCRIPT_NAME'] = $target;
$_SERVER['SCRIPT_FILENAME'] = $root . $target;
chdir(dirname($root . $target));
require $root . $target;
return true;
