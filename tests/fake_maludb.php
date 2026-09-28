<?php
/** A FAKE MaluDB API for the ingest proof — `php -S 127.0.0.1:8193 tests/fake_maludb.php` — appends every POST /v1/episodes body to $FAKE_MALUDB_LOG (one JSON per line) and answers 201. */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/v1/episodes' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer ' . getenv('MALUDB_API_TOKEN')) { http_response_code(401); exit; }
    file_put_contents((string) getenv('FAKE_MALUDB_LOG'), file_get_contents('php://input') . "\n", FILE_APPEND);
    http_response_code(201); header('Content-Type: application/json'); echo '{"id":1}'; exit;
}
http_response_code(404);
