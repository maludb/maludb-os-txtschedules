<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

// Pattern A fragment: the record picker's rows (app/picker.php). GET /pick/?source=<key>&q=&page=&<declared params>.
// A source names its filter params and its `allow` gate (a right asked AT the named site, as the screens that use the field
// ask it); nothing else of the request reaches it. A search is not an action: nothing is logged. Errors answer a small
// fragment, never a page, because the answer lands inside the modal.
require_login();
require_human();
$key = preg_replace('/[^a-z0-9_]/', '', mb_substr(request_string('source'), 0, 40));
$source = $key !== '' ? picker_source($key) : null;
header('Vary: HX-Request');
if ($source === null) {
    http_response_code(404);
    echo view('shared/picker-rows.php', ['error' => 'That list does not exist.']);
    exit;
}
$params = [];
foreach ($source['params'] as $name) {
    $params[$name] = request_integer($name);
}
if (!($source['allow'])($params)) {
    http_response_code(403);
    echo view('shared/picker-rows.php', ['error' => 'You do not have permission to choose from this list.']);
    exit;
}
$q = mb_substr(trim(request_string('q')), 0, 80);
$page = max(1, request_integer('page') ?? 1);
echo view('shared/picker-rows.php', [
    'source' => $key, 'q' => $q, 'params' => $params, 'selected' => request_integer('selected'), 'noun' => $source['noun'],
    'result' => ($source['search'])(db(), $q, $params, $page),
]);
