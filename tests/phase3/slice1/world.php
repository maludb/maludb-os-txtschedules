<?php
/** Builds the slice's fixture once (everyone signs on, positions, shifts) and proves its shape. The other proofs re-read it from the database. */
require __DIR__ . '/lib.php';
$W = world();
ok(count(q('SELECT 1 FROM members WHERE display_name LIKE :n', ['n' => 'SMOKE %'])) >= 9, 'the cast signed on and became members (9 people)');
ok((int) one("SELECT main_scope_id FROM staff_profiles WHERE member_id = 27") === 101 && (int) one("SELECT main_scope_id FROM staff_profiles WHERE member_id = 26") === 102, 'Marco\'s main restaurant is Downtown, Priya\'s Airport');
ok(count($W) >= 20 && (int) one("SELECT count(*) FROM shifts WHERE published_at IS NULL") === 1, 'shifts are in place; exactly one is a draft (Priya\'s, four months out)');
finish();
