<?php
declare(strict_types=1);

/**
 * The command bar (chat-actions), run by the kernel (sign-on-and-directory.md §5): the utterance
 * goes to the kernel's chat endpoint as the acting person; ONE turn of txtSchedules' expert answers. txtSchedules
 * holds no model key (or Twilio key). Renders the reply and the actions; fires HX-Trigger per entity changed.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

require_login();
require_post();
verify_csrf();
require_human();
$pdo = db();

$utterance = trim((string) ($_POST['message'] ?? ''));
$screen = request_string('screen');
$recordId = request_integer('record_id');
if ($utterance === '' || mb_strlen($utterance) > 2000) {
    http_response_code(422);
    echo view('shared/assistant-reply.php', ['error' => 'Say what you want in a sentence or two.']);
    exit;
}
$_SESSION['assistant_conversation'] ??= 'txtschedules-' . bin2hex(random_bytes(8));
$conversation = (string) $_SESSION['assistant_conversation'];

log_activity($pdo, 'assistant.message', null, null, ['screen' => $screen, 'after' => ['excerpt' => mb_substr($utterance, 0, 200), 'record_id' => $recordId]]);

$answer = kernel_call('POST', '/api/v1/agents/chat.php?agent=expert', [
    'utterance' => $utterance, 'screen' => $screen,
    'context' => ['record_id' => $recordId, 'application' => app_key()],
    'conversation_id' => $conversation, 'wait' => 60,
], ['X-Acting-Member: ' . current_member_id()], 75);            // the kernel holds the request up to `wait`

$refusals = [
    400 => 'The kernel did not know who was asking.',
    403 => 'You are not allowed to use the assistant here.',
    404 => 'txtSchedules has no expert yet — a super-admin names one in the kernel.',
    409 => 'The expert is busy; try again in a moment.',
    422 => 'Say what you want in a sentence or two.',
];
if ($answer === null) {
    http_response_code(503);
    echo view('shared/assistant-reply.php', ['error' => 'The kernel is not reachable right now.']);
    exit;
}
$body = $answer['body'] ?? [];
if ($answer['status'] >= 400) {
    http_response_code($answer['status'] === 401 ? 503 : $answer['status']);
    echo view('shared/assistant-reply.php', ['error' => $refusals[$answer['status']] ?? (string) ($body['error']['message'] ?? 'The assistant could not answer.')]);
    exit;
}
// A long run: poll until finished or the wait is spent.
$deadline = time() + 55;
while ($answer['status'] === 202 && empty($body['finished']) && time() < $deadline && !empty($body['run_id'])) {
    usleep(1500000);
    $answer = kernel_call('GET', '/api/v1/agents/chat.php?run=' . (int) $body['run_id'], null, [], 20);
    $body = $answer['body'] ?? [];
    if ($answer === null || $answer['status'] >= 400) {
        break;
    }
}
$actions = is_array($body['actions'] ?? null) ? $body['actions'] : [];
// A tool `{entity}_{verb}` that succeeded refreshes the screens listening for `{entity}Changed` (chat-actions, Pattern D).
$families = ['shift' => 'shift', 'exchange' => 'exchange', 'coverage' => 'exchange', 'claim' => 'exchange', 'week' => 'week', 'template' => 'template',
             'availability' => 'availability', 'time' => 'timeOff', 'staff' => 'staff', 'position' => 'position', 'certification' => 'certification',
             'forecast' => 'forecast', 'budget' => 'budget', 'announcement' => 'announcement', 'site' => 'site', 'rule' => 'rule', 'token' => 'token'];
$events = [];
foreach ($actions as $a) {
    if (($a['status'] ?? '') === 'ok' && preg_match('/^([a-z]+)(?:_[a-z_]+)?$/', (string) ($a['tool'] ?? ''), $m) && isset($families[$m[1]])) {
        $events[] = $families[$m[1]] . 'Changed';
    }
}
if ($events !== []) {
    hx_trigger(implode(', ', array_unique($events)));
}
log_activity($pdo, 'assistant.reply', null, null, ['screen' => $screen, 'after' => [
    'run_id' => $body['run_id'] ?? null, 'status' => $body['status'] ?? null, 'actions' => count($actions), 'cost' => $body['cost'] ?? null]]);
echo view('shared/assistant-reply.php', ['reply' => (string) ($body['reply'] ?? ''), 'status' => (string) ($body['status'] ?? ''),
    'actions' => $actions, 'approval' => $body['approval_request_id'] ?? null, 'finished' => !empty($body['finished'])]);
