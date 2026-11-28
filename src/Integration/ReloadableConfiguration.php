<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Integration;

use KaririCode\Configurator\Configuration;
use KaririCode\Configurator\ConfigurationBuilder;
use KaririCode\Configurator\Contract\Configurator;
use KaririCode\Configurator\Event\ConfigReloaded;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Decorator that monitors config files and rebuilds on change.
 *
 * Designed for long-running processes (Swoole, RoadRunner, ReactPHP)
 * where restarting the process to reload configuration is undesirable.
 *
 * Uses filemtime() polling (not inotify) for portability across
 * Linux, macOS, and containerized environments. The polling interval
 * is configurable; default 5 seconds balances responsiveness with
 * filesystem overhead.
 *
 * Thread-safety: the reload operation is atomic — a new Configuration
 * instance is built completely before replacing the reference. Readers
 * during reload see the previous (consistent) configuration.
 *
 * Ref: Spring Cloud Config refresh scope; Kubernetes ConfigMap reload;
 *      ARFA 1.3 P5 (observability via EventDispatcherBridge).
 *
 * @package KaririCode\Configurator
 * @since   4.1.0 ARFA 1.3
 */
final class ReloadableConfiguration implements Configurator
{
    private Configuration $inner;

    /** @var array<string, int> file path → last known mtime */
    private array $fileMtimes = [];

    private float $lastCheckTime = 0.0;

    /**
     * @param list<string>                     $watchFiles        Files to monitor
     * @param ConfigurationBuilder             $builder           Builder for rebuilds
     * @param float                            $checkIntervalSec  Polling interval
     * @param EventDispatcherInterface|null    $dispatcher        Optional event dispatch
     */
    public function __construct(
        private readonly array $watchFiles,
        private readonly ConfigurationBuilder $builder,
        private readonly float $checkIntervalSec = 5.0,
        private readonly ?EventDispatcherInterface $dispatcher = null,
    ) {
        $this->inner = $this->builder->build();
        $this->captureFileMtimes();
    }

    // ── Hot Reload ────────────────────────────────────────────────────

    /**
     * Checks for file changes and reloads if needed.
     *
     * Call this from your event loop tick, middleware, or timer.
     * Returns true if a reload occurred.
     */
    public function checkAndReload(): bool
    {
        $now = microtime(true);

        if (($now - $this->lastCheckTime) < $this->checkIntervalSec) {
            return false;
        }

        $this->lastCheckTime = $now;
        $changed = $this->detectChanges();

        if ($changed === []) {
            return false;
        }

        $this->reload($changed);

        return true;
    }

    /**
     * Forces an immediate reload regardless of the check interval.
     */
    public function forceReload(): void
    {
        $this->reload($this->watchFiles);
    }

    // ── Configurator Contract (delegated) ─────────────────────────────

    public function get(string $path, mixed $default = null): mixed
    {
        $this->checkAndReload();

        return $this->inner->get($path, $default);
    }

    public function getString(string $path, string $default = ''): string
    {
        $this->checkAndReload();

        return $this->inner->getString($path, $default);
    }

    public function getInt(string $path, int $default = 0): int
    {
        $this->checkAndReload();

        return $this->inner->getInt($path, $default);
    }

    public function getFloat(string $path, float $default = 0.0): float
    {
        $this->checkAndReload();

        return $this->inner->getFloat($path, $default);
    }

    public function getBool(string $path, bool $default = false): bool
    {
        $this->checkAndReload();

        return $this->inner->getBool($path, $default);
    }

    public function getArray(string $path, array $default = []): array
    {
        $this->checkAndReload();

        return $this->inner->getArray($path, $default);
    }

    public function has(string $path): bool
    {
        $this->checkAndReload();

        return $this->inner->has($path);
    }

    public function all(): array
    {
        $this->checkAndReload();

        return $this->inner->all();
    }

    // ── Internal ──────────────────────────────────────────────────────

    /**
     * @param list<string> $changedFiles
     */
    private function reload(array $changedFiles): void
    {
        $startTime = hrtime(true);

        // Atomic rebuild — new instance built completely before swap
        $this->inner = $this->builder->build();
        $this->captureFileMtimes();

        $elapsedMs = (hrtime(true) - $startTime) / 1_000_000;

        $this->dispatcher?->dispatch(
            new ConfigReloaded($changedFiles, $elapsedMs),
        );
    }

    /** @return list<string> Files whose mtime changed */
    private function detectChanges(): array
    {
        $changed = [];

        foreach ($this->watchFiles as $file) {
            if (!is_file($file)) {
                continue;
            }

            clearstatcache(true, $file);
            $currentMtime = filemtime($file);

            if ($currentMtime === false) {
                continue;
            }

            $previousMtime = $this->fileMtimes[$file] ?? 0;

            if ($currentMtime !== $previousMtime) {
                $changed[] = $file;
            }
        }

        return $changed;
    }

    private function captureFileMtimes(): void
    {
        $this->fileMtimes = [];

        foreach ($this->watchFiles as $file) {
            if (is_file($file)) {
                clearstatcache(true, $file);
                $mtime = filemtime($file);

                if ($mtime !== false) {
                    $this->fileMtimes[$file] = $mtime;
                }
            }
        }
    }
}
