<?php
declare(strict_types=1);

// HTMX endpoint. Receives one command per POST, returns an HTML fragment
// that the client appends to the scrollback (with an out-of-band swap that
// replaces the prompt line so the cwd label updates).

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../src/FakeFilesystem.php';
require_once __DIR__ . '/../src/SessionState.php';
require_once __DIR__ . '/../src/ScenarioEpisode1.php';
require_once __DIR__ . '/../src/CommandRunner.php';

const COMMAND_MAX_LENGTH = 256;

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo render_error('method not allowed');
    exit;
}

SessionState::start(ScenarioEpisode1::HOME_DIR);

if (!SessionState::validateCsrf($_POST['csrf'] ?? null)) {
    http_response_code(400);
    echo render_error('session expired. refresh the page.');
    exit;
}

$raw = (string) ($_POST['command'] ?? '');

if (strlen($raw) > COMMAND_MAX_LENGTH) {
    echo render_exchange(prompt_label(SessionState::cwd()), '<truncated>', "input too long\n");
    echo render_prompt_line_oob(SessionState::cwd());
    exit;
}

if (preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $raw) === 1) {
    echo render_exchange(prompt_label(SessionState::cwd()), '<rejected>', "invalid characters in input\n");
    echo render_prompt_line_oob(SessionState::cwd());
    exit;
}

if (!SessionState::recordAndCheckRate(time())) {
    echo render_exchange(prompt_label(SessionState::cwd()), $raw, "rate limit exceeded; slow down\n");
    echo render_prompt_line_oob(SessionState::cwd());
    exit;
}

// Capture cwd *before* dispatch so the echoed prompt reflects the directory
// the command was run from (e.g. `cd /usr/adm` echoes with the prior `~$`).
$cwdBefore = SessionState::cwd();

try {
    $fs     = new FakeFilesystem(ScenarioEpisode1::fileSystem());
    $runner = new CommandRunner(
        $fs,
        ScenarioEpisode1::answerKey(),
        ScenarioEpisode1::HOME_DIR,
        ScenarioEpisode1::NOW_STRING
    );
    $result = $runner->run($raw);
} catch (\Throwable $e) {
    error_log('terminal.php exception: ' . $e->getMessage());
    http_response_code(500);
    echo render_error('internal error');
    exit;
}

if ($result['clearScreen']) {
    $initial = $result['output'] === ''
        ? ''
        : '<pre class="output">' . e($result['output']) . '</pre>';
    echo '<div id="scrollback" hx-swap-oob="true">' . $initial . '</div>';
    echo render_prompt_line_oob(SessionState::cwd());
    exit;
}

echo render_exchange(prompt_label($cwdBefore), $raw, $result['output']);
echo render_prompt_line_oob(SessionState::cwd());

// ---------- rendering helpers -----------------------------------------------

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function prompt_label(string $cwd): string
{
    $home = ScenarioEpisode1::HOME_DIR;
    $shown = $cwd;
    if ($cwd === $home) {
        $shown = '~';
    } elseif (str_starts_with($cwd, $home . '/')) {
        $shown = '~' . substr($cwd, strlen($home));
    }
    // Trailing space is part of the label so live + echo rows share the same
    // monospace gap (preserved by `.prompt { white-space: pre }` in CSS).
    return ScenarioEpisode1::USER . '@' . ScenarioEpisode1::HOST . ':' . $shown . '$ ';
}

function render_exchange(string $promptLabel, string $command, string $output): string
{
    $echo = '<div class="echo"><span class="prompt">'
          . e($promptLabel) . '</span>'
          . e($command) . '</div>';

    $body = $output === ''
        ? ''
        : '<pre class="output">' . e($output) . '</pre>';

    return '<div class="exchange">' . $echo . $body . '</div>';
}

function render_prompt_line_oob(string $cwd): string
{
    $token  = SessionState::csrfToken();
    $label  = prompt_label($cwd);

    return '<form id="prompt-line" hx-swap-oob="outerHTML"'
         . ' hx-post="terminal.php" hx-target="#scrollback" hx-swap="beforeend"'
         . ' autocomplete="off">'
         . '<span class="prompt">' . e($label) . '</span>'
         . '<span class="input-wrap">'
         . '<input type="text" name="command" maxlength="' . COMMAND_MAX_LENGTH . '"'
         . ' autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false">'
         . '<span class="crt-cursor-measure" aria-hidden="true"></span>'
         . '<span class="crt-cursor" aria-hidden="true"></span>'
         . '</span>'
         . '<input type="hidden" name="csrf" value="' . e($token) . '">'
         . '</form>';
}

function render_error(string $msg): string
{
    return '<div class="exchange"><pre class="output">' . e($msg) . "\n</pre></div>";
}
