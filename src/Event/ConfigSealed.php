<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Event;

/**
 * Emitted after configuration is sealed and becomes immutable.
 *
 * @package KaririCode\Configurator
 * @since   4.0.0 ARFA 1.3
 */
final readonly class ConfigSealed
{
    /**
     * @param list<string> $keys Top-level configuration keys present at seal time
     */
    public function __construct(
        public array $keys,
    ) {
    }
}
