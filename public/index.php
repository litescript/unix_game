<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../src/SessionState.php';
require_once __DIR__ . '/../src/ScenarioEpisode1.php';

SessionState::start(ScenarioEpisode1::HOME_DIR);

// If the player solved the puzzle in a prior request, treat a page reload
// as "play again" — wipe gameplay state so they land on a fresh prompt.
// The CSRF token survives the reset, so the rendered form is still valid.
if (SessionState::isSolved()) {
    SessionState::reset(ScenarioEpisode1::HOME_DIR);
}

$csrf  = SessionState::csrfToken();
$cwd   = SessionState::cwd();
$home  = ScenarioEpisode1::HOME_DIR;
$shown = ($cwd === $home)
    ? '~'
    : (str_starts_with($cwd, $home . '/') ? '~' . substr($cwd, strlen($home)) : $cwd);
$promptLabel = ScenarioEpisode1::USER . '@' . ScenarioEpisode1::HOST . ':' . $shown . '$ ';

$motd = ScenarioEpisode1::motd();

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="referrer" content="no-referrer">
<title>cs-lab-3</title>
<link rel="stylesheet" href="style.css">
<script src="htmx.min.js"></script>
<script src="terminal.js" defer></script>
</head>
<body>
<div id="terminal">
  <div id="scrollback"><pre class="motd"><?= h($motd) ?></pre><pre class="output">type 'help' for a command list.
</pre></div>
  <form id="prompt-line"
        hx-post="terminal.php"
        hx-target="#scrollback"
        hx-swap="beforeend"
        autocomplete="off">
    <span class="prompt"><?= h($promptLabel) ?></span><span class="input-wrap"><input type="text" name="command" maxlength="256"
           autocomplete="off" autocapitalize="off"
           autocorrect="off" spellcheck="false" autofocus><span class="crt-cursor-measure" aria-hidden="true"></span><span class="crt-cursor" aria-hidden="true"></span></span><input type="hidden" name="csrf" value="<?= h($csrf) ?>">
  </form>
</div>
</body>
</html>
