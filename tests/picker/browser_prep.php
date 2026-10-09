<?php
/** Builds what the browser pass looks at (all SMOKE) and prints it as JSON: the ids and the cast's claims. Thirty people at the Airport so Show more has something to show. */
require dirname(__DIR__) . '/phase3/slice4/lib.php';
$W = reset4();
foreach ([1, 26, 30, 31, 33] as $m) { as_member($m, $m === 1 ? 102 : null); }
admin_sql("DELETE FROM members WHERE display_name LIKE 'SMOKE Picker %'");
admin_sql("INSERT INTO members (id, member_kind, display_name, email, business_role, is_external, status, capability, roles)
           SELECT 9000 + n, 'human', format('SMOKE Picker %s', lpad(n::text, 2, '0')), format('picker%s@example.invalid', n), 'user', false, 'active', 'write', '{staff}' FROM generate_series(1, 30) n;
           INSERT INTO member_site_roles (member_id, scope_id, role_key, roles, capability) SELECT id, 102, 'staff', '{staff}', 'write' FROM members WHERE display_name LIKE 'SMOKE Picker %';
           INSERT INTO staff_profiles (member_id, main_scope_id) SELECT id, 102 FROM members WHERE display_name LIKE 'SMOKE Picker %';
           INSERT INTO staff_positions (member_id, position_id) SELECT id, {$W['aSrv']} FROM members WHERE display_name LIKE 'SMOKE Picker %';");
echo json_encode(['ids' => ['mine' => (int) $W['p1'], 'mine2' => (int) $W['p2'], 'draft' => (int) $W['pdraft'], 'srv' => (int) $W['aSrv']], 'claims' => cast()]);
