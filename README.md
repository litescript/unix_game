# cs-lab-3 — Episode 1

A small, free, browser-based UNIX investigation game. The player is the
overnight `operator` on a fictional university system. Their morning mail
contains a quiet note from `sysadm` about a small accounting discrepancy.
They have an hour or so to figure out which account ran something it
shouldn't have, roughly when, and which log shows it cleanest — then file a
short report.

Period and tone are inspired by Clifford Stoll's *The Cuckoo's Egg*; the
names, host, numbers, and sequence of events are fictional and not a
recreation of any specific incident. MIT licensed (see [LICENSE](LICENSE)).

## Run

```sh
php -S localhost:8000 -t public
```

Then open http://localhost:8000 .

Requires PHP 8.1+ (for `match`, `str_starts_with`, `str_contains`,
constructor promotion). No frameworks, no Composer, no build step.

## Project structure

```
public/
  index.php       initial page (motd, prompt, htmx wiring)
  terminal.php    HTMX endpoint — one command per POST
  style.css       black background, amber monospace
  htmx.min.js     vendored, pinned 1.9.12
src/
  CommandRunner.php       command parser + dispatcher
  FakeFilesystem.php      in-memory read-only filesystem
  SessionState.php        thin $_SESSION wrapper
  ScenarioEpisode1.php    seed data + answer key
```

## Commands

`help`, `pwd`, `cd`, `ls` (`-l`), `cat`, `grep PAT FILE`, `who`, `last`,
`date`, `clear`, `report …`, `exit`. `help report` shows the report syntax.

The arrow keys cycle through your in-page command history. Tab autocompletes
commands and paths. Ctrl-L clears the screen. **Alt-C** toggles an optional
CRT visual mode (scanlines, phosphor glow, soft vignette, slow flicker);
the preference persists in `localStorage`.

## Win condition

```
report user=NAME time=HH:MM source=PATH
```

Tolerances:

- `user`: case-insensitive
- `time`: ±5 minutes (24-hour)
- `source`: full absolute path *or* the bare log filename

After three wrong reports, a single quiet hint is added to the rejection
message.

## Design notes

- **No real shell, ever.** User input never touches `system`, `exec`,
  `passthru`, `proc_open`, `popen`, `shell_exec`, or backticks. It also
  never reaches a real path operation: paths resolve only against the
  in-memory map in `FakeFilesystem`, and unknown keys return cleanly.
- **No randomness, no clocks.** The in-game date is fixed
  (`Tue Oct 13 06:47:23 1992`) so logs and `date` agree. Same input,
  same output.
- **Single responsibility.** Each `src/` file has one job. `CommandRunner`
  has no state of its own — it reads and writes through `SessionState`
  and reads through `FakeFilesystem`.
- **No premature abstraction.** There is no `Scenario` interface; Episode 2
  can copy `ScenarioEpisode1.php` and the entry points can switch on a
  single constant.

## Production hardening notes

This is intended to eventually live on a public VPS. The code already does:

- `htmlspecialchars` on every dynamic output (`ENT_QUOTES | ENT_SUBSTITUTE`)
- CSRF token per session, validated on every POST
- 256-byte cap on command input, control characters rejected (tab allowed)
- Rolling per-session rate limit (60 commands / 60 s)
- `Cache-Control: no-store`, `X-Content-Type-Options: nosniff`
- Session cookie `HttpOnly` + `SameSite=Strict`
- `display_errors=0`, `log_errors=1`, generic error message on exceptions

Before deploying to a real VPS, also do:

1. **Serve over HTTPS only.** Add `'secure' => true` to
   `session_set_cookie_params(...)` in `SessionState::start()`. Set
   `session.cookie_secure=1` in `php.ini`.
2. **Switch off the dev server.** Use `nginx`+`php-fpm` (or Apache+mod_php).
   `php -S` is single-threaded and not for production.
3. **IP-based rate limiting.** The per-session limiter is a fallback;
   behind a reverse proxy, layer one of:
   - nginx `limit_req_zone $binary_remote_addr ...`
   - `fail2ban` watching `php-fpm` access logs
   - Cloudflare or equivalent in front
   When you trust the proxy, read the client IP from `X-Forwarded-For`,
   not `REMOTE_ADDR` directly.
4. **Tighten PHP.** In production `php.ini`:
   ```
   expose_php = Off
   display_errors = Off
   display_startup_errors = Off
   log_errors = On
   error_log = /var/log/php/cs-lab-3.log
   session.use_strict_mode = 1
   session.cookie_httponly = 1
   session.cookie_secure = 1
   session.cookie_samesite = "Strict"
   ```
5. **Filesystem permissions.** Only `public/` should be reachable from the
   web; serve only `index.php`, `terminal.php`, `style.css`, `htmx.min.js`.
   Keep `src/` outside the document root or behind a `deny from all`.
6. **HTTP headers worth adding at the proxy:**
   - `Strict-Transport-Security: max-age=63072000; includeSubDomains`
   - `Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self'; img-src 'self' data:; frame-ancestors 'none'`
     (the inline `<script>` in `index.php` for arrow-key history requires
     either `'unsafe-inline'` or moving that block to a separate file with
     a nonce — preferable for production)
   - `Referrer-Policy: no-referrer`
   - `X-Frame-Options: DENY`
7. **Resource caps.** `php.ini`: `memory_limit=64M`, `max_execution_time=5`,
   `post_max_size=8K`, `upload_max_filesize=0`. The endpoint never reads
   uploads, but tightening these reduces blast radius.
8. **Session storage.** Default file-based sessions are fine for low
   volume. If you scale up, move to Redis (`session.save_handler=redis`).
9. **Logging.** `error_log` already gets exception messages. Add a JSON
   access-log line in `terminal.php` if you want to track command volume
   per IP — but be careful not to log player input verbatim if you want
   to keep the experience private.

## What this game is not

- It is not a real shell. There is no I/O, no signals, no environment.
- It is not a tutorial. The player is expected to recognize what each
  log file is and to read carefully.
- It is not a re-creation of any specific historical incident. The
  numbers, names, hosts, and sequence of events are fiction.
