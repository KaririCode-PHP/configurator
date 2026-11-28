<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Integration;

use KaririCode\Configurator\Configuration;
use KaririCode\Configurator\ConfigurationBuilder;
use KaririCode\Configurator\Contract\Configurator;
use KaririCode\Configurator\Event\ConfigReloaded;
use KaririCode\Configurator\Event\ConfigReloadFailed;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Rebuilds the configuration of a long-running process (Swoole,
 * RoadRunner, ReactPHP, FrankenPHP workers) when a watched file changes,
 * so a configuration edit does not need a restart.
 *
 * It polls filemtime() (portable across Linux, macOS and containers,
 * unlike inotify) at most once per interval, from checkAndReload() or
 * from any read. A reload builds the new configuration completely before
 * swapping it in, so a read sees the old or the new one, never a mix. A
 * rebuild that fails keeps the last good configuration and dispatches
 * ConfigReloadFailed: a read never fails because a file is broken, while
 * checkAndReload() and forceReload() rethrow (ADR-012).
 *
 * Preconditions: the builder builds a sealed configuration every time;
 * the watched files are the ones the builder reads.
 *
 * Thread-safety: one instance per worker; a reload swaps the inner
 * configuration between two reads.
 *
 * Side effects: a read may stat the watched files, rebuild the
 * configuration and dispatch ConfigReloaded or ConfigReloadFailed.
 *
 * Example:
 *
 *     $config = new ReloadableConfiguration($files, $builder, checkIntervalSec: 2.0, dispatcher: $dispatcher);
 *     $timeout = $config->getInt('http.timeout'); // reloads first when a file changed
 *
 * References: ADR-006, ADR-012. ARFA 1.3 P3: adaptive configuration for
 * long-running workers; P5: ConfigReloaded and ConfigReloadFailed.
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
     * Rebuilds the configuration when a watched file changed since the
     * last check, at most once per interval; true when it reloaded.
     *
     * Call it from an event-loop tick, a middleware or a timer to see
     * failures: a rebuild that fails keeps the current configuration,
     * dispatches ConfigReloadFailed and rethrows here, while a read that
     * triggers the same check keeps serving the current configuration.
     *
     * @throws \Throwable What the builder threw, when the rebuild fails
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
     * Rebuilds the configuration now, whatever the interval and the file
     * times say; a failure keeps the current configuration and rethrows.
     *
     * @throws \Throwable What the builder threw, when the rebuild fails
     */
    public function forceReload(): void
    {
        $this->reload($this->watchFiles);
    }

    // ── Configurator Contract (delegated) ────────────────────────────────

    #[\Override]
    public function get(string $path, mixed $default = null): mixed
    {
        $this->refresh();

        return $this->inner->get($path, $default);
    }

    #[\Override]
    public function getString(string $path, string $default = ''): string
    {
        $this->refresh();

        return $this->inner->getString($path, $default);
    }

    #[\Override]
    public function getInt(string $path, int $default = 0): int
    {
        $this->refresh();

        return $this->inner->getInt($path, $default);
    }

    #[\Override]
    public function getFloat(string $path, float $default = 0.0): float
    {
        $this->refresh();

        return $this->inner->getFloat($path, $default);
    }

    #[\Override]
    public function getBool(string $path, bool $default = false): bool
    {
        $this->refresh();

        return $this->inner->getBool($path, $default);
    }

    #[\Override]
    public function getArray(string $path, array $default = []): array
    {
        $this->refresh();

        return $this->inner->getArray($path, $default);
    }

    #[\Override]
    public function has(string $path): bool
    {
        $this->refresh();

        return $this->inner->has($path);
    }

    #[\Override]
    public function all(): array
    {
        $this->refresh();

        return $this->inner->all();
    }

    // ── Internal ─────────────────────────────────────────────────────────

    /**
     * The check a read runs: a failed rebuild was dispatched as
     * ConfigReloadFailed and the current configuration stays, so the
     * read never fails because a file is broken.
     */
    private function refresh(): void
    {
        try {
            $this->checkAndReload();
        } catch (\Throwable) {
            // Reported through ConfigReloadFailed; the last good configuration serves the read.
        }
    }

    /**
     * Builds the new configuration completely before swapping it in, so a
     * read sees the old or the new one, never a mix. The file times are
     * recorded either way: a broken file is not rebuilt again until it
     * changes.
     *
     * @param list<string> $changedFiles
     *
     * @throws \Throwable What the builder threw
     */
    private function reload(array $changedFiles): void
    {
        $startTime = hrtime(true);

        try {
            $configuration = $this->builder->build();
        } catch (\Throwable $exception) {
            $this->captureFileMtimes();
            $this->dispatcher?->dispatch(new ConfigReloadFailed($changedFiles, $exception));

            throw $exception;
        }

        $this->inner = $configuration;
        $this->captureFileMtimes();

        $this->dispatcher?->dispatch(
            new ConfigReloaded($changedFiles, (hrtime(true) - $startTime) / 1_000_000),
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

            if ($currentMtime !== ($this->fileMtimes[$file] ?? null)) {
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
