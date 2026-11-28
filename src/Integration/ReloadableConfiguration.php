<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Integration;

use KaririCode\Configurator\Configuration;
use KaririCode\Configurator\ConfigurationBuilder;
use KaririCode\Configurator\Contract\Configurator;
use KaririCode\Configurator\Event\ConfigReloaded;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Rebuilds the configuration of a long-running process (Swoole,
 * RoadRunner, ReactPHP, FrankenPHP workers) when a watched file changes,
 * so a configuration edit does not need a restart.
 *
 * It polls filemtime() (portable across Linux, macOS and containers,
 * unlike inotify) at most once per interval, from checkAndReload() or
 * from any read. A reload builds the new configuration completely before
 * swapping it in, so a read sees the old or the new one, never a mix; a
 * build that fails leaves the old configuration in place.
 *
 * Preconditions: the builder builds a sealed configuration every time;
 * the watched files are the ones the builder reads.
 *
 * Thread-safety: one instance per worker; a reload swaps the inner
 * configuration between two reads.
 *
 * Side effects: a read may stat the watched files, rebuild the
 * configuration and dispatch ConfigReloaded.
 *
 * Example:
 *
 *     $config = new ReloadableConfiguration($files, $builder, checkIntervalSec: 2.0, dispatcher: $dispatcher);
 *     $timeout = $config->getInt('http.timeout'); // reloads first when a file changed
 *
 * References: ADR-006. ARFA 1.3 P3: adaptive configuration for
 * long-running workers; P5: ConfigReloaded.
 *
 * @since 4.2.0
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

    // ── Hot Reload ───────────────────────────────────────────────────────

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

    // ── Configurator Contract (delegated) ────────────────────────────────

    #[\Override]
    public function get(string $path, mixed $default = null): mixed
    {
        $this->checkAndReload();

        return $this->inner->get($path, $default);
    }

    #[\Override]
    public function getString(string $path, string $default = ''): string
    {
        $this->checkAndReload();

        return $this->inner->getString($path, $default);
    }

    #[\Override]
    public function getInt(string $path, int $default = 0): int
    {
        $this->checkAndReload();

        return $this->inner->getInt($path, $default);
    }

    #[\Override]
    public function getFloat(string $path, float $default = 0.0): float
    {
        $this->checkAndReload();

        return $this->inner->getFloat($path, $default);
    }

    #[\Override]
    public function getBool(string $path, bool $default = false): bool
    {
        $this->checkAndReload();

        return $this->inner->getBool($path, $default);
    }

    #[\Override]
    public function getArray(string $path, array $default = []): array
    {
        $this->checkAndReload();

        return $this->inner->getArray($path, $default);
    }

    #[\Override]
    public function has(string $path): bool
    {
        $this->checkAndReload();

        return $this->inner->has($path);
    }

    #[\Override]
    public function all(): array
    {
        $this->checkAndReload();

        return $this->inner->all();
    }

    // ── Internal ─────────────────────────────────────────────────────────

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
