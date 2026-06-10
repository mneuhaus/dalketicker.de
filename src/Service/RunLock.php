<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * flock-based guard against parallel runs of the same job (e.g. the AI
 * commands). Lock files live in var/run/<name>.lock; the OS releases the lock
 * automatically if the process dies, so stale locks can't happen. isLocked()
 * lets the admin UI show whether a run is currently in progress.
 *
 * In prod, var/run is a volume shared between the app replicas and the
 * scheduler container (docker-compose.prod.yml) — that's what makes these
 * locks hold across container boundaries, not just within one container.
 */
final class RunLock
{
    /** @var array<string, resource> */
    private array $handles = [];

    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
    }

    /** Try to take the lock; returns false when another process holds it. */
    public function acquire(string $name): bool
    {
        if (isset($this->handles[$name])) {
            return true;
        }
        $handle = fopen($this->path($name), 'c');
        if ($handle === false) {
            return false;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }
        $this->handles[$name] = $handle;

        return true;
    }

    public function release(string $name): void
    {
        $handle = $this->handles[$name] ?? null;
        if ($handle === null) {
            return;
        }
        flock($handle, LOCK_UN);
        fclose($handle);
        unset($this->handles[$name]);
    }

    /** Whether any process (including this one) currently holds the lock. */
    public function isLocked(string $name): bool
    {
        if (isset($this->handles[$name])) {
            return true;
        }
        $path = $this->lockDir().'/'.$this->fileName($name);
        if (!is_file($path)) {
            return false;
        }
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return false;
        }
        $acquired = flock($handle, LOCK_EX | LOCK_NB);
        if ($acquired) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);

        return !$acquired;
    }

    private function path(string $name): string
    {
        $dir = $this->lockDir();
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Lock-Verzeichnis "%s" kann nicht angelegt werden.', $dir));
        }

        return $dir.'/'.$this->fileName($name);
    }

    private function lockDir(): string
    {
        return $this->projectDir.'/var/run';
    }

    private function fileName(string $name): string
    {
        return preg_replace('/[^a-z0-9_-]+/i', '_', $name).'.lock';
    }
}
