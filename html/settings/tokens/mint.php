<?php
declare(strict_types=1);
/** Action `token_mint` (log `token.mint`: the label, never the token): a personal mcp token, shown once on the next screen. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/settings/queries.php';
require_post();
verify_csrf();
require_login();
require_human();
$pdo = db();
$label = request_string('label');
if ($label === '' || mb_strlen($label) > 80) {
    refuse(422, 'A label is required (up to 80 characters) — what will use this token.');
}
$scope = request_string('scope', 'mcp') ?: 'mcp';
if ($scope !== 'mcp') {
    refuse(422, 'The scope is mcp.');
}
$minted = mint_token($pdo, (int) current_member_id(), $label);
log_activity($pdo, 'token.mint', 'mcp_access_token', $minted['id'], ['after' => ['label' => $label, 'scope' => 'mcp']]);
emit_action_status(true, ['did' => 'Minted the token "' . $label . '" — shown once on the Tokens screen', 'record_id' => $minted['id'], 'refresh' => 'tokenChanged']);
if (wants_json()) {
    respond_saved(['id' => $minted['id'], 'token' => $minted['raw'], 'location' => '/settings/tokens/']);   // the value, once
}
$_SESSION['minted_token'] = $minted;
saved_go('/settings/tokens/', 'tokenChanged');
