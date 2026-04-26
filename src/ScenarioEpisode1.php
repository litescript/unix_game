<?php
declare(strict_types=1);

// Episode 1: a small overnight discrepancy on cs-lab-3.
//
// All content is original and fictional. The scenario seeds a fake filesystem
// and an answer key for the report command. To branch into Episode 2 later,
// add a parallel ScenarioEpisode2 class with the same shape (host, now,
// fileSystem, answerKey, motd) and switch on it at the entry point.

final class ScenarioEpisode1
{
    public const HOST       = 'cs-lab-3';
    public const USER       = 'operator';
    public const NOW_STRING = 'Tue Oct 13 06:47:23 1992';
    public const HOME_DIR   = '/home/operator';

    public static function answerKey(): array
    {
        return [
            'user'              => 'guest',
            'time'              => '02:14',
            'sources'           => ['/usr/adm/wtmp', 'wtmp'],
            'tolerance_minutes' => 5,
        ];
    }

    public static function motd(): string
    {
        return <<<TXT
   Welcome to cs-lab-3.
   University Computing Lab - Building 4
   For account issues mail sysadm.
   Logging is enabled. Be courteous.

You have new mail.

TXT;
    }

    public static function endingText(): string
    {
        return <<<TXT
report accepted.

guest logged in at 02:14 from dialup-2 (tty04) and ran a small unattended
job that doesn't appear in any user's foreground session. The cpu time
shows up cleanly in the daily accounting roll-up; the login itself shows
up cleanly in /usr/adm/wtmp.

short, quiet, and the kind of thing that would have gone unnoticed if
sysadm hadn't bothered to cross-check the totals.

(end of episode 1)

TXT;
    }

    // Returns a flat map of absolute path -> entry. Each entry has:
    //   type:    'dir' | 'file'
    //   owner:   string
    //   group:   string
    //   perms:   ten-char rwx string (e.g. '-rw-r--r--')
    //   mtime:   'Mon DD HH:MM' string for ls -l
    //   content: string (files only)
    public static function fileSystem(): array
    {
        $dir = static function (string $owner = 'root', string $group = 'sys', string $perms = 'drwxr-xr-x', string $mtime = 'Aug 02 09:30'): array {
            return ['type' => 'dir', 'owner' => $owner, 'group' => $group, 'perms' => $perms, 'mtime' => $mtime, 'content' => ''];
        };
        $file = static function (string $content, string $owner, string $group = 'staff', string $perms = '-rw-r--r--', string $mtime = 'Oct 12 09:00'): array {
            return ['type' => 'file', 'owner' => $owner, 'group' => $group, 'perms' => $perms, 'mtime' => $mtime, 'content' => $content];
        };

        $passwd = <<<TXT
root:*:0:0:System Administrator:/:/bin/sh
operator:*:1:1:Operator Account:/home/operator:/bin/sh
sysadm:*:2:1:Systems Group:/home/sysadm:/bin/sh
mwhite:*:101:20:M. White - Physics:/home/mwhite:/bin/sh
jharris:*:102:20:J. Harris - Math:/home/jharris:/bin/sh
guest:*:50:50:Visiting Account:/home/guest:/bin/sh

TXT;

        $motd = self::motd();

        $acct = <<<TXT
Daily process accounting roll-up for Oct 12 - 13
USER          CONNECT(hr)  CPU(min)  PROCS
operator           3.94       0.41      58
mwhite             1.28       0.22      21
jharris            3.18       0.36      33
sysadm             0.07       0.03       4
guest              0.53       1.42      17
-------------------------------------------------
total              9.00       2.44     133

(connect from /usr/adm/wtmp; cpu and procs from /usr/adm/pacct)

TXT;

        $wtmp = <<<TXT
operator   tty01    console            Oct 12 17:02 - 18:14  (01:12)
mwhite     tty02    physlab            Oct 12 17:46 - 19:03  (01:17)
jharris    tty03    mathlab            Oct 12 18:11 - 21:22  (03:11)
operator   tty01    console            Oct 12 21:49 - 22:30  (00:41)
guest      tty04    dialup-2           Oct 13 02:14 - 02:46  (00:32)
operator   tty01    console            Oct 13 06:31     still logged in

TXT;

        $messages = <<<TXT
Oct 12 17:00:02 cs-lab-3 cron[112]: daily backup queued
Oct 12 17:14:31 cs-lab-3 lpd[88]: print job 1142 from operator (4 pages)
Oct 12 19:03:18 cs-lab-3 init: tty02 line idle
Oct 12 21:22:44 cs-lab-3 init: tty03 line idle
Oct 12 22:30:09 cs-lab-3 init: tty01 line idle
Oct 12 23:01:10 cs-lab-3 cron[112]: nightly accounting roll-up scheduled 06:30
Oct 13 02:13:48 cs-lab-3 login: ROOT LOGIN REFUSED on tty04
Oct 13 02:14:09 cs-lab-3 login: tty04 login guest
Oct 13 02:46:23 cs-lab-3 login: tty04 logout guest
Oct 13 06:30:00 cs-lab-3 cron[112]: accounting roll-up complete
Oct 13 06:31:14 cs-lab-3 login: tty01 login operator

TXT;

        $mail = <<<TXT
From sysadm Tue Oct 13 06:35:11 1992
To: operator
Subject: overnight numbers

Morning -

When I cross-checked the daily acct against wtmp this morning the totals
didn't quite tie. It's small, well under two cpu minutes, but it is
consistent with one account running something it shouldn't have overnight.
I haven't had time to chase it.

If you've got an hour, see if you can pin down which account, roughly
when, and which log shows it cleanest. File a short report when you
have it (see 'help report').

- sysadm

TXT;

        $notes = <<<TXT
- check tape rotation Wed
- ask sysadm about new printer queue (jam Mon afternoon)
- account audit per memo from director, end of week

TXT;

        $history = <<<TXT
ls
cd /tmp
ls -l
cat readme
cc -o p p.c
./p
./p
who
last
exit

TXT;

        $readme = <<<TXT
build notes for p:
  cc -o p p.c
  ./p > /dev/null
remove when done.

TXT;

        return [
            '/'                         => $dir(),
            '/etc'                      => $dir(),
            '/etc/passwd'               => $file($passwd, 'root', 'sys', '-rw-r--r--', 'Sep 14 11:02'),
            '/etc/motd'                 => $file($motd,   'root', 'sys', '-rw-r--r--', 'Aug 02 09:30'),
            '/usr'                      => $dir(),
            '/usr/adm'                  => $dir('sysadm', 'sys', 'drwxr-x---', 'Oct 13 06:30'),
            '/usr/adm/acct'             => $file($acct,     'sysadm', 'sys', '-rw-r-----', 'Oct 13 06:30'),
            '/usr/adm/wtmp'             => $file($wtmp,     'sysadm', 'sys', '-rw-r-----', 'Oct 13 06:31'),
            '/usr/adm/messages'         => $file($messages, 'sysadm', 'sys', '-rw-r-----', 'Oct 13 06:31'),
            '/usr/spool'                => $dir(),
            '/usr/spool/mail'           => $dir('root', 'mail', 'drwxrwxr-x', 'Oct 13 06:35'),
            '/usr/spool/mail/operator'  => $file($mail, 'sysadm', 'mail', '-rw-rw----', 'Oct 13 06:35'),
            '/home'                     => $dir(),
            '/home/operator'            => $dir('operator', 'staff', 'drwxr-x---', 'Oct 11 16:22'),
            '/home/operator/notes.txt'  => $file($notes, 'operator', 'staff', '-rw-r-----', 'Oct 11 16:22'),
            '/home/guest'               => $dir('guest', 'guest', 'drwxr-x---', 'Oct 13 02:46'),
            '/home/guest/.history'      => $file($history, 'guest', 'guest', '-rw-------', 'Oct 13 02:46'),
            '/tmp'                      => $dir('root', 'sys', 'drwxrwxrwt', 'Oct 13 02:18'),
            '/tmp/readme'               => $file($readme, 'guest', 'guest', '-rw-r--r--', 'Oct 13 02:18'),
        ];
    }
}
