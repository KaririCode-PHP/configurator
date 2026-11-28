<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Event;

/**
 * Emitted when a ReloadableConfiguration detects file changes
 * and successfully rebuilds the configuration.
 *
 * Listeners can use this to invalidate caches, reconnect databases,
 * or update runtime state without restarting the process.
 *
 * @package KaririCode\Configurator
 * @since   4.1.0 ARFA 1.3
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
