<?php
/** Builds what the browser proof looks at (all SMOKE) and prints it as JSON: the ids, the weeks, the cast's claims. */
require __DIR__ . '/lib.php';
$W = w2(); reset_people(); only(null);
$mara = as_member(33); as_member(26); as_member(30); as_member(31); as_member(32); as_planner(); as_dee();
$srv = srv(); $bar = (int) $W['aBar'];
$P = [];
// B1: a busy DRAFT week for the screens — a warning, open shifts, an overnight shift, a budget and a forecast
$b1 = wk(55); $P['b1'] = $b1;
$P['ana'] = fx(102, $srv, 30, $b1, 0, '17:00', '23:00');
$P['priyaTue'] = fx(102, $srv, 26, $b1, 1, '17:00', '23:00');
$P['leeWed'] = fx(102, $srv, 31, $b1, 2, '17:00', '23:00');
$P['priyaWed'] = fx(102, $srv, 26, $b1, 2, '17:00', '23:00');                            // Priya Wednesday until 23:00 …
$P['priyaThu'] = fx(102, $srv, 26, $b1, 3, '07:00', '12:00');                            // … and Thursday from 07:00: eight hours' rest — a soft warning
$P['anaBar'] = fx(102, $bar, 30, $b1, 3, '17:00', '21:00');
$P['openFri'] = fx(102, $srv, null, $b1, 4, '11:00', '15:00');
$P['openSat'] = fx(102, $srv, null, $b1, 5, '17:00', '23:00');
$P['night'] = fx(102, $srv, 32, $b1, 4, '22:00', '02:00');
$P['moveMe'] = fx(102, $srv, 32, $b1, 6, '12:00', '16:00');
admin_sql("INSERT INTO labor_budgets (scope_id, week_start, area, budget_hours, budget_amount) VALUES (102, '$b1', 'all', 90, 1200) ON CONFLICT DO NOTHING;
           INSERT INTO staffing_ratios (scope_id, position_id, covers_per_staff, min_staff) VALUES (102, $srv, 20, 1) ON CONFLICT DO NOTHING;
           INSERT INTO forecast_covers (scope_id, on_date, day_part_id, expected_covers) SELECT 102, '" . dayn($b1, 4) . "', id, 80 FROM day_parts WHERE scope_id = 102 AND key = 'dinner' ON CONFLICT DO NOTHING;");
[$c, $b] = bp($mara, '/templates/save.php', ['site' => 102, 'name' => 'SMOKE Weekday template', 'week' => week_id_of(102, $b1)]);
$P['template'] = (int) ($b['record_id'] ?? 0);
// B2: the drag week
$b2 = wk(57); $P['b2'] = $b2;
$P['dragDay'] = fx(102, $srv, 30, $b2, 0, '17:00', '23:00');
$P['dragPerson'] = fx(102, $srv, 31, $b2, 2, '17:00', '23:00');
$P['dragRefused'] = fx(102, $bar, 30, $b2, 3, '17:00', '21:00');
$P['dragOpen'] = fx(102, $srv, null, $b2, 4, '11:00', '15:00');
// B3: a PUBLISHED week (with a change after publishing)
$b3 = wk(56); $P['b3'] = $b3;
$P['liveA'] = fx(102, $srv, 30, $b3, 1, '17:00', '23:00'); $P['liveB'] = fx(102, $srv, 26, $b3, 2, '17:00', '23:00'); fx(102, $srv, null, $b3, 3, '11:00', '15:00');
[$c] = bp($mara, '/weeks/publish.php', ['week' => week_id_of(102, $b3)]);
bp($mara, '/shifts/change.php', ['shift' => $P['liveA'], 'note' => 'SMOKE changed after']);
bp($mara, '/shifts/change.php', ['shift' => $P['liveA'], 'ends_at' => at($b3, 1, '22:00')]);
// B4: a fresh empty week for adding a shift from the phone
$P['b4'] = wk(58);
echo json_encode(['ids' => $P, 'claims' => cast() + ['35' => planner_claims(), '36' => dee_claims()]]);
