<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Event;

/**
 * Emitted after a configuration file is successfully loaded and merged.
 *
 * @package KaririCode\Configurator
 * @since   4.0.0 ARFA 1.3
 */
final readonly class ConfigLoaded
{
    public function __construct(
        public string $filePath,
    ) {
    }
}
