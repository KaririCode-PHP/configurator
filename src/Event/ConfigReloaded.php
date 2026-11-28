<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Event;

/**
 * Reports, through PSR-14, that ReloadableConfiguration rebuilt itself
 * after a watched file changed, so listeners can drop caches, reconnect
 * or refresh runtime state without restarting the process.
 *
 * Preconditions: `$changedFiles` lists the watched files that changed
 * (every watched file after forceReload()); `$reloadTimeMs` is the
 * rebuild time in milliseconds.
 *
 * Thread-safety: immutable; safe to share between fibers and requests.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     $listeners->listen(ConfigReloaded::class, static fn(ConfigReloaded $event) => $cache->clear());
 *
 * References: ADR-006; PSR-14. ARFA 1.3 P5: observable lifecycle.
 *
 * @since 4.2.0
 */
final readonly class ConfigReloaded
{
    /**
     * @param list<string> $changedFiles Files that triggered the reload
     * @param float        $reloadTimeMs Time spent rebuilding in milliseconds
     */
    public function __construct(
        public array $changedFiles,
        public float $reloadTimeMs,
    ) {}
}
