<?php
/** Proof — db/015: an overlap is refused when a shift is TAKEN (the marketplace can say why before the button), for a swap the swapped-away shift does not count, and nothing else changed. */
require __DIR__ . '/lib.php';
$W = world();
as_member(30); as_member(31);
$s = mkshift(102, srv(), 26, 1800);
$mine = mkshift(102, srv(), 31, 1801);
$x = (int) one("SELECT ts_exchange_create('offer', :s, 26, NULL, NULL, NULL)", ['s' => $s]);
pdo()->exec("SELECT set_config('app.member_id', '31', false)");
$say = function (int $x, int $m): string { try { pdo()->prepare('SELECT ts_exchange_check_taker(x, :m) FROM exchanges x WHERE x.id = :i')->execute(['m' => $m, 'i' => $x]); return 'ok'; } catch (PDOException $e) { return preg_replace('/^.*ERROR:\s*/s', '', explode("\n", $e->getMessage())[0]); } };
ok(str_starts_with($say($x, 31), 'That overlaps your shift on '), 'check_taker says "That overlaps your shift on <weekday>." to a person with an overlapping shift: "' . $say($x, 31) . '"');
ok($say($x, 30) === 'ok', 'and passes a person who is free (Ana)');
$before = (int) one('SELECT count(*) FROM shifts WHERE assignee_member_id = 31');
try { pdo()->prepare('SELECT ts_exchange_claim(:x, 31)')->execute(['x' => $x]); ok(false, 'the claim was refused'); } catch (PDOException $e) { ok(str_contains($e->getMessage(), 'That overlaps your shift'), 'a claim is refused at once — not left to fail at approval'); }
ok(holder_of($s) === 26 && (int) one("SELECT count(*) FROM exchange_claims WHERE exchange_id = :x", ['x' => $x]) === 0, 'and left no claim row behind');
q("UPDATE exchanges SET status = 'cancelled' WHERE id = :x", ['x' => $x]);
// a swap: Lee gives his own overlapping shift back in the same exchange — that shift must not count against him
$theirs = mkshift(102, srv(), 31, 1850);            // Lee's, to be swapped away
$mineP = mkshift(102, srv(), 26, 1851);              // Priya's, overlapping Lee's (an overlap only the swap itself resolves)
$xs = (int) one("SELECT ts_exchange_create('swap', :s, 26, 31, :w, NULL)", ['s' => $mineP, 'w' => $theirs]);
ok($say($xs, 31) === 'ok', 'a swap: the colleague\'s swapped-away shift does not count as an overlap');
ok((string) one('SELECT ts_exchange_accept(:x, 31, true)', ['x' => $xs]) !== '' && holder_of($mineP) === 31 && holder_of($theirs) === 26, 'and the swap goes through');
finish();
