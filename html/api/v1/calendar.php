<?php
declare(strict_types=1);
/**
 * GET /api/v1/calendar/{token}.ics — the private calendar feed (FR-M3; the vhost rewrites the URL to ?token=). The token is the authority: only its SHA-256 is stored; a wrong or replaced token is a plain 404
 * (never a hint which). Carries the person's own published shifts for 60 days: no other name, no pay, no note. Not cached, not indexed, no session cookie.
 */
require_once dirname(__DIR__, 3) . '/app/api/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/calendar/ics.php';
api_require_get();
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
header_remove('Set-Cookie');
$pdo = db();
$who = feed_for_token($pdo, (string) ($_GET['token'] ?? ''));
if ($who === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Not found.\n");
}
$body = ics_for($pdo, $who['member_id']);
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="my-shifts.ics"');
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');
echo $body;
