<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Event;

/**
 * Reports, through PSR-14, that a configuration was sealed and is
 * immutable from now on.
 *
 * Preconditions: `$keys` lists the top-level keys at seal time.
 *
 * Thread-safety: immutable; safe to share between fibers and requests.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     $listeners->listen(ConfigSealed::class, static fn(ConfigSealed $event) => $sections = $event->keys);
 *
 * References: ADR-003; PSR-14. ARFA 1.3 P5: observable lifecycle.
 *
 * @since 4.2.0
 */
final readonly class ConfigSealed
{
    /**
     * @param list<string> $keys Top-level configuration keys present at seal time
     */
    public function __construct(
        public array $keys,
    ) {}
}
