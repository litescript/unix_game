<?php
declare(strict_types=1);

// Thin wrapper around $_SESSION. Owns:
//   - cwd                 current working directory (in-game)
//   - attempts            failed report attempts
//   - solved              true once player has filed a correct report
//   - hint_shown          true once the post-3-attempts nudge has been shown
//   - command_log         rolling timestamps, for rate limiting
//   - csrf_token          per-session token, validated on every POST

final class SessionState
{
    public const RATE_WINDOW_SECONDS = 60;
    public const RATE_MAX_COMMANDS   = 60;

    public static function start(string $defaultCwd): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        // Cookie settings are tightened for production. http only and samesite
        // mitigate the worst classes of session-fixation/CSRF; secure is
        // controlled by the caller via php.ini or an .htaccess on the VPS.
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_name('unixgame');
        session_start();

        if (!isset($_SESSION['cwd'])) {
            $_SESSION['cwd'] = $defaultCwd;
        }
        if (!isset($_SESSION['attempts'])) {
            $_SESSION['attempts'] = 0;
        }
        if (!isset($_SESSION['solved'])) {
            $_SESSION['solved'] = false;
        }
        if (!isset($_SESSION['hint_shown'])) {
            $_SESSION['hint_shown'] = false;
        }
        if (!isset($_SESSION['command_log'])) {
            $_SESSION['command_log'] = [];
        }
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
        }
    }

    // Resets gameplay state to a clean slate. The CSRF token is intentionally
    // preserved so any open page's form keeps working without a hard reload.
    public static function reset(string $defaultCwd): void
    {
        $_SESSION['cwd']         = $defaultCwd;
        $_SESSION['attempts']    = 0;
        $_SESSION['solved']      = false;
        $_SESSION['hint_shown']  = false;
        $_SESSION['command_log'] = [];
    }

    public static function cwd(): string
    {
        return (string) $_SESSION['cwd'];
    }

    public static function setCwd(string $cwd): void
    {
        $_SESSION['cwd'] = $cwd;
    }

    public static function attempts(): int
    {
        return (int) $_SESSION['attempts'];
    }

    public static function incrementAttempts(): void
    {
        $_SESSION['attempts']++;
    }

    public static function isSolved(): bool
    {
        return (bool) $_SESSION['solved'];
    }

    public static function setSolved(): void
    {
        $_SESSION['solved'] = true;
    }

    public static function hintShown(): bool
    {
        return (bool) $_SESSION['hint_shown'];
    }

    public static function markHintShown(): void
    {
        $_SESSION['hint_shown'] = true;
    }

    public static function csrfToken(): string
    {
        return (string) $_SESSION['csrf_token'];
    }

    public static function validateCsrf(?string $submitted): bool
    {
        if ($submitted === null || $submitted === '') {
            return false;
        }
        return hash_equals(self::csrfToken(), $submitted);
    }

    // Rolling rate limit. Returns true if the request should be allowed.
    // Side effect on allowance: records the new timestamp.
    public static function recordAndCheckRate(int $now): bool
    {
        $log    = (array) $_SESSION['command_log'];
        $cutoff = $now - self::RATE_WINDOW_SECONDS;

        $log = array_values(array_filter($log, static fn ($t) => $t >= $cutoff));

        if (count($log) >= self::RATE_MAX_COMMANDS) {
            $_SESSION['command_log'] = $log;
            return false;
        }

        $log[] = $now;
        $_SESSION['command_log'] = $log;
        return true;
    }
}
