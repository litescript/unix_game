<?php
declare(strict_types=1);

// In-memory, read-only filesystem. No disk I/O. No user input ever reaches a
// real path operation; FakeFilesystem only ever resolves to a key in its own
// map, and rejects paths that don't exist there.

final class FakeFilesystem
{
    /** @var array<string, array{type:string,owner:string,group:string,perms:string,mtime:string,content:string}> */
    private array $files;

    public function __construct(array $files)
    {
        $this->files = $files;
    }

    public function exists(string $absPath): bool
    {
        return isset($this->files[$absPath]);
    }

    public function isDir(string $absPath): bool
    {
        return isset($this->files[$absPath]) && $this->files[$absPath]['type'] === 'dir';
    }

    public function isFile(string $absPath): bool
    {
        return isset($this->files[$absPath]) && $this->files[$absPath]['type'] === 'file';
    }

    public function get(string $absPath): ?array
    {
        return $this->files[$absPath] ?? null;
    }

    // Resolve a path argument against a current working directory. Returns
    // a normalized absolute path. The returned path is not guaranteed to
    // exist in the filesystem; callers must check with exists().
    public function resolve(string $cwd, string $arg): string
    {
        $arg = trim($arg);
        if ($arg === '') {
            return $cwd;
        }

        $base = ($arg[0] === '/') ? '/' : $cwd;
        $combined = ($base === '/' ? '' : $base) . '/' . ltrim($arg, '/');

        $parts = [];
        foreach (explode('/', $combined) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $seg;
        }

        return '/' . implode('/', $parts);
    }

    // List direct children of a directory. Returns an associative array of
    // name => entry, sorted alphabetically.
    public function listDir(string $absDir): array
    {
        if (!$this->isDir($absDir)) {
            return [];
        }

        $prefix = ($absDir === '/') ? '/' : $absDir . '/';
        $children = [];
        foreach ($this->files as $path => $entry) {
            if ($path === $absDir) {
                continue;
            }
            if (strncmp($path, $prefix, strlen($prefix)) !== 0) {
                continue;
            }
            $rest = substr($path, strlen($prefix));
            if (strpos($rest, '/') !== false) {
                continue;
            }
            $children[$rest] = $entry;
        }
        ksort($children);
        return $children;
    }

    public function size(string $absPath): int
    {
        $entry = $this->files[$absPath] ?? null;
        if ($entry === null) {
            return 0;
        }
        if ($entry['type'] === 'dir') {
            return 512;
        }
        return strlen($entry['content']);
    }
}
