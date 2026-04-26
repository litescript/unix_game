<?php
declare(strict_types=1);

// Tab-completion endpoint. Reads the current input line, returns JSON with
// the new line value and (when ambiguous) a list of candidates to display.
// Only inspects the in-memory FakeFilesystem; never reads the real disk.

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../src/FakeFilesystem.php';
require_once __DIR__ . '/../src/SessionState.php';
require_once __DIR__ . '/../src/ScenarioEpisode1.php';

const COMPLETE_MAX_LENGTH = 256;

const COMPLETE_COMMAND_NAMES = [
    'cat', 'cd', 'clear', 'date', 'exit', 'grep',
    'help', 'last', 'ls', 'pwd', 'report', 'restart', 'who',
];

const COMPLETE_PATH_COMMANDS = ['cd', 'ls', 'cat', 'grep'];

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function complete_send(bool $ok, string $line = '', array $matches = []): void
{
    echo json_encode(['ok' => $ok, 'line' => $line, 'matches' => $matches]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    complete_send(false);
}

SessionState::start(ScenarioEpisode1::HOME_DIR);

if (!SessionState::validateCsrf($_POST['csrf'] ?? null)) {
    http_response_code(400);
    complete_send(false);
}

$line = (string) ($_POST['line'] ?? '');

// Hostile-input guards. On rejection we still return ok=true with the line
// unchanged, so the client just experiences "tab does nothing".
if (strlen($line) > COMPLETE_MAX_LENGTH) {
    complete_send(true, $line);
}
if (preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $line) === 1) {
    complete_send(true, $line);
}

$fs  = new FakeFilesystem(ScenarioEpisode1::fileSystem());
$cwd = SessionState::cwd();

// Tokenize. If the line ends in whitespace, the partial token is empty
// (we're completing a brand-new arg position).
$lastChar      = $line === '' ? '' : substr($line, -1);
$endsWithSpace = $lastChar === ' ' || $lastChar === "\t";
$trimmed       = trim($line);
$tokens        = $trimmed === '' ? [] : preg_split('/\s+/', $trimmed);
if ($tokens === false) {
    complete_send(true, $line);
}

if ($endsWithSpace || empty($tokens)) {
    $partial  = '';
    $position = count($tokens);
} else {
    $partial  = (string) end($tokens);
    $position = count($tokens) - 1;
}

$command = strtolower((string) ($tokens[0] ?? ''));

// ---- command-name completion (position 0) ---------------------------------

if ($position === 0) {
    $matches = array_values(array_filter(
        COMPLETE_COMMAND_NAMES,
        static fn ($c) => str_starts_with($c, $partial)
    ));
    sort($matches);

    if (empty($matches)) {
        complete_send(true, $line);
    }
    if (count($matches) === 1) {
        complete_send(true, complete_strip_partial($line, $partial) . $matches[0] . ' ');
    }
    $common  = complete_common_prefix($matches);
    $newLine = complete_strip_partial($line, $partial) . $common;
    complete_send(true, $newLine, $matches);
}

// ---- path completion (cd / ls / cat / grep with arg pos >= 1) -------------

if (!in_array($command, COMPLETE_PATH_COMMANDS, true)) {
    complete_send(true, $line);
}
if ($command === 'grep' && $position === 1) {
    // grep PATTERN ... — first arg is the pattern, no completion
    complete_send(true, $line);
}

[$dirPart, $basePart] = complete_split_path($partial);
$listArg              = rtrim($dirPart, '/');
if ($dirPart !== '' && $listArg === '') {
    $listArg = '/';
}
$dirAbs = $dirPart === '' ? $cwd : $fs->resolve($cwd, $listArg);

if (!$fs->isDir($dirAbs)) {
    complete_send(true, $line);
}

$entries    = $fs->listDir($dirAbs);
$candidates = [];
foreach ($entries as $name => $entry) {
    if ($name[0] === '.' && ($basePart === '' || $basePart[0] !== '.')) {
        continue;
    }
    if (!str_starts_with($name, $basePart)) {
        continue;
    }
    $candidates[] = ['name' => $name, 'isDir' => $entry['type'] === 'dir'];
}
usort($candidates, static fn ($a, $b) => strcmp($a['name'], $b['name']));

if (empty($candidates)) {
    complete_send(true, $line);
}

if (count($candidates) === 1) {
    $c       = $candidates[0];
    $insert  = $c['name'] . ($c['isDir'] ? '/' : ' ');
    $newLine = complete_strip_partial($line, $partial) . $dirPart . $insert;
    complete_send(true, $newLine);
}

$names   = array_map(static fn ($c) => $c['name'], $candidates);
$common  = complete_common_prefix($names);
$newLine = complete_strip_partial($line, $partial) . $dirPart . $common;
$display = array_map(
    static fn ($c) => $c['name'] . ($c['isDir'] ? '/' : ''),
    $candidates
);
complete_send(true, $newLine, $display);

// ---- helpers --------------------------------------------------------------

function complete_strip_partial(string $line, string $partial): string
{
    if ($partial === '') {
        return $line;
    }
    return substr($line, 0, strlen($line) - strlen($partial));
}

function complete_split_path(string $partial): array
{
    $i = strrpos($partial, '/');
    if ($i === false) {
        return ['', $partial];
    }
    return [substr($partial, 0, $i + 1), substr($partial, $i + 1)];
}

function complete_common_prefix(array $strings): string
{
    if (empty($strings)) {
        return '';
    }
    $first = $strings[0];
    $len   = strlen($first);
    for ($i = 0; $i < $len; $i++) {
        $ch = $first[$i];
        foreach ($strings as $s) {
            if (!isset($s[$i]) || $s[$i] !== $ch) {
                return substr($first, 0, $i);
            }
        }
    }
    return $first;
}
