<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Event;

/**
 * Emitted when a configuration file fails to load or parse.
 *
 * @package KaririCode\Configurator
 * @since   4.0.0 ARFA 1.3
 */
final readonly class ConfigLoadError
{
    public function __construct(
        public string $filePath,
        public \Throwable $exception,
    ) {
    }
}
