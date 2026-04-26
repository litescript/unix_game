<?php
declare(strict_types=1);

require_once __DIR__ . '/FakeFilesystem.php';
require_once __DIR__ . '/SessionState.php';
require_once __DIR__ . '/ScenarioEpisode1.php';

// Parses a single line of user input and produces a result struct. Never
// touches the host shell, host filesystem, or any subprocess. All paths
// resolve against an in-memory map; all patterns are matched with strpos.
//
// run() returns:
//   output       string: text to render in the scrollback (may be empty)
//   clearScreen  bool:   if true, the client should clear its scrollback
//   solved       bool:   true when this command was the winning report

final class CommandRunner
{
    public function __construct(
        private FakeFilesystem $fs,
        private array $answerKey,
        private string $homeDir,
        private string $nowString,
    ) {}

    public function run(string $raw): array
    {
        $line = trim($raw);
        if ($line === '') {
            return self::ok('');
        }

        $tokens = preg_split('/\s+/', $line) ?: [];
        $cmd    = strtolower($tokens[0]);
        $args   = array_slice($tokens, 1);

        return match ($cmd) {
            'help'           => $this->cmdHelp($args),
            'pwd'            => $this->cmdPwd(),
            'cd'             => $this->cmdCd($args),
            'ls'             => $this->cmdLs($args),
            'cat'            => $this->cmdCat($args),
            'grep'           => $this->cmdGrep($args),
            'who'            => $this->cmdWho(),
            'last'           => $this->cmdLast($args),
            'date'           => $this->cmdDate(),
            'clear'          => self::clearScreen(),
            'report'         => $this->cmdReport($args),
            'restart'        => $this->cmdRestart(),
            'exit', 'logout' => self::ok("logout.\n"),
            default          => self::ok("sh: {$cmd}: not found\n"),
        };
    }

    // ---- help ------------------------------------------------------------

    private function cmdHelp(array $args): array
    {
        if (!empty($args) && strtolower($args[0]) === 'report') {
            $txt = <<<TXT
usage: report user=NAME time=HH:MM source=PATH

submit your findings on the overnight discrepancy. all three keys are
required. time is 24-hour. source is the log file or path that best
shows the evidence.

example:
  report user=alice time=03:11 source=/usr/adm/messages

TXT;
            return self::ok($txt);
        }

        $txt = <<<TXT
available commands:
  help [CMD]      show this list, or details on a single command
  pwd             print the current directory
  cd [PATH]       change directory (no arg = home)
  ls [-l] [PATH]  list directory contents
  cat FILE        print file contents
  grep PAT FILE   search for PAT (substring) in FILE
  who             list users currently logged in
  last [USER]     show login history from wtmp
  date            show system date and time
  clear           clear the screen
  report ...      submit your findings (try 'help report')
  restart         reset and start a new investigation
  exit            end session

TXT;
        return self::ok($txt);
    }

    // ---- pwd / cd / ls ---------------------------------------------------

    private function cmdPwd(): array
    {
        return self::ok(SessionState::cwd() . "\n");
    }

    private function cmdCd(array $args): array
    {
        $target = empty($args) ? $this->homeDir : $args[0];
        $abs    = $this->fs->resolve(SessionState::cwd(), $target);

        if (!$this->fs->exists($abs)) {
            return self::ok("cd: {$target}: no such file or directory\n");
        }
        if (!$this->fs->isDir($abs)) {
            return self::ok("cd: {$target}: not a directory\n");
        }

        SessionState::setCwd($abs);
        return self::ok('');
    }

    private function cmdLs(array $args): array
    {
        $long = false;
        $path = null;
        foreach ($args as $a) {
            if ($a === '-l') {
                $long = true;
            } elseif ($path === null) {
                $path = $a;
            } else {
                return self::ok("ls: too many arguments\n");
            }
        }

        $cwd    = SessionState::cwd();
        $target = $path === null ? $cwd : $this->fs->resolve($cwd, $path);

        if (!$this->fs->exists($target)) {
            return self::ok("ls: {$path}: no such file or directory\n");
        }

        if ($this->fs->isFile($target)) {
            $name = self::baseName($target);
            if (!$long) {
                return self::ok($name . "\n");
            }
            $entry = $this->fs->get($target);
            return self::ok($this->formatLong($name, $entry, $this->fs->size($target)) . "\n");
        }

        $children = $this->fs->listDir($target);
        if (!$long) {
            $names = array_keys($children);
            return self::ok(empty($names) ? '' : implode("\n", $names) . "\n");
        }

        $total = 0;
        foreach ($children as $name => $_) {
            $childPath = ($target === '/' ? '/' : $target . '/') . $name;
            $total += (int) ceil($this->fs->size($childPath) / 512);
        }

        $out = "total {$total}\n";
        foreach ($children as $name => $entry) {
            $childPath = ($target === '/' ? '/' : $target . '/') . $name;
            $out .= $this->formatLong($name, $entry, $this->fs->size($childPath)) . "\n";
        }
        return self::ok($out);
    }

    private function formatLong(string $name, array $entry, int $size): string
    {
        return sprintf(
            '%s  1 %-8s %-6s %6d %s %s',
            $entry['perms'],
            $entry['owner'],
            $entry['group'],
            $size,
            $entry['mtime'],
            $name
        );
    }

    // ---- cat / grep ------------------------------------------------------

    private function cmdCat(array $args): array
    {
        if (empty($args)) {
            return self::ok("usage: cat FILE [FILE...]\n");
        }
        $out = '';
        foreach ($args as $f) {
            $abs = $this->fs->resolve(SessionState::cwd(), $f);
            if (!$this->fs->exists($abs)) {
                $out .= "cat: {$f}: no such file or directory\n";
                continue;
            }
            if ($this->fs->isDir($abs)) {
                $out .= "cat: {$f}: is a directory\n";
                continue;
            }
            $out .= $this->fs->get($abs)['content'];
        }
        return self::ok($out);
    }

    private function cmdGrep(array $args): array
    {
        if (count($args) < 2) {
            return self::ok("usage: grep PATTERN FILE [FILE...]\n");
        }
        $pattern = $args[0];
        if ($pattern === '') {
            return self::ok("grep: empty pattern\n");
        }
        $files = array_slice($args, 1);
        $multi = count($files) > 1;
        $out   = '';

        foreach ($files as $f) {
            $abs = $this->fs->resolve(SessionState::cwd(), $f);
            if (!$this->fs->exists($abs)) {
                $out .= "grep: {$f}: no such file or directory\n";
                continue;
            }
            if ($this->fs->isDir($abs)) {
                $out .= "grep: {$f}: is a directory\n";
                continue;
            }
            $content = $this->fs->get($abs)['content'];
            foreach (explode("\n", $content) as $line) {
                if ($line === '') {
                    continue;
                }
                if (strpos($line, $pattern) !== false) {
                    $out .= ($multi ? $f . ':' : '') . $line . "\n";
                }
            }
        }
        return self::ok($out);
    }

    // ---- who / last / date ----------------------------------------------

    private function cmdWho(): array
    {
        $entry = $this->fs->get('/usr/adm/wtmp');
        if ($entry === null) {
            return self::ok("who: cannot read login records\n");
        }
        $out = '';
        foreach (explode("\n", $entry['content']) as $line) {
            if (strpos($line, 'still logged in') !== false) {
                $out .= rtrim($line) . "\n";
            }
        }
        if ($out === '') {
            $out = "no users logged in\n";
        }
        return self::ok($out);
    }

    private function cmdLast(array $args): array
    {
        $entry = $this->fs->get('/usr/adm/wtmp');
        if ($entry === null) {
            return self::ok("last: /usr/adm/wtmp: cannot open\n");
        }
        $lines = array_filter(
            explode("\n", $entry['content']),
            static fn ($l) => $l !== ''
        );
        if (!empty($args)) {
            $needle = strtolower($args[0]);
            $lines  = array_filter(
                $lines,
                static function ($l) use ($needle) {
                    $first = strtolower(strtok($l, " \t"));
                    return $first === $needle;
                }
            );
        }
        $out = '';
        foreach ($lines as $l) {
            $out .= $l . "\n";
        }
        $out .= "\nwtmp begins Oct 12 17:00\n";
        return self::ok($out);
    }

    private function cmdDate(): array
    {
        return self::ok($this->nowString . "\n");
    }

    // ---- report ----------------------------------------------------------

    private function cmdReport(array $args): array
    {
        if (SessionState::isSolved()) {
            return self::ok("you've already filed a report. (type 'restart' or refresh the page to start a new session)\n");
        }

        $kv = self::parseKeyValues($args);
        $user = $kv['user']   ?? null;
        $time = $kv['time']   ?? null;
        $src  = $kv['source'] ?? null;

        if ($user === null || $time === null || $src === null) {
            return self::ok("usage: report user=NAME time=HH:MM source=PATH\n");
        }

        $key      = $this->answerKey;
        $userOk   = strcasecmp($user, $key['user']) === 0;
        $tTarget  = self::parseHHMM($key['time']);
        $tGiven   = self::parseHHMM($time);
        $timeOk   = $tGiven !== null && $tTarget !== null
                  && abs($tGiven - $tTarget) <= (int) $key['tolerance_minutes'];
        $valid    = array_map('strtolower', $key['sources']);
        $srcOk    = in_array(strtolower(trim($src)), $valid, true);

        if ($userOk && $timeOk && $srcOk) {
            SessionState::setSolved();
            return [
                'output'      => ScenarioEpisode1::endingText(),
                'clearScreen' => false,
                'solved'      => true,
            ];
        }

        SessionState::incrementAttempts();
        $msg = "report does not match known evidence. keep looking.\n";
        if (SessionState::attempts() >= 3 && !SessionState::hintShown()) {
            SessionState::markHintShown();
            $msg .= "\n(hint: cross-check the daily acct against wtmp around the suspicious window.)\n";
        }
        return self::ok($msg);
    }

    // ---- restart ---------------------------------------------------------

    private function cmdRestart(): array
    {
        SessionState::reset($this->homeDir);
        return [
            'output'      => ScenarioEpisode1::motd() . "type 'help' for a command list.\n",
            'clearScreen' => true,
            'solved'      => false,
        ];
    }

    private static function parseKeyValues(array $args): array
    {
        $out = [];
        foreach ($args as $a) {
            $eq = strpos($a, '=');
            if ($eq === false || $eq === 0) {
                continue;
            }
            $k = strtolower(substr($a, 0, $eq));
            $v = substr($a, $eq + 1);
            $out[$k] = trim($v);
        }
        return $out;
    }

    private static function parseHHMM(string $s): ?int
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim($s), $m)) {
            return null;
        }
        $h = (int) $m[1];
        $n = (int) $m[2];
        if ($h > 23 || $n > 59) {
            return null;
        }
        return $h * 60 + $n;
    }

    // ---- helpers ---------------------------------------------------------

    private static function baseName(string $absPath): string
    {
        if ($absPath === '/') {
            return '/';
        }
        $i = strrpos($absPath, '/');
        return $i === false ? $absPath : substr($absPath, $i + 1);
    }

    private static function ok(string $output): array
    {
        return ['output' => $output, 'clearScreen' => false, 'solved' => false];
    }

    private static function clearScreen(): array
    {
        return ['output' => '', 'clearScreen' => true, 'solved' => false];
    }
}
