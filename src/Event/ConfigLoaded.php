<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Event;

/**
 * Reports, through PSR-14, that a configuration file was loaded and
 * merged.
 *
 * Preconditions: `$filePath` is the path passed to load().
 *
 * Thread-safety: immutable; safe to share between fibers and requests.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     $listeners->listen(ConfigLoaded::class, static fn(ConfigLoaded $event) => $loaded[] = $event->filePath);
 *
 * References: PSR-14. ARFA 1.3 P5: observable lifecycle.
 *
 * @since 4.2.0
 */
final readonly class ConfigLoaded
{
    public function __construct(
        public string $filePath,
    ) {}
}
