<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Event;

/**
 * Reports, through PSR-14, that a configuration file failed to load or
 * parse; EventDispatcherBridge dispatches it and then rethrows the
 * exception.
 *
 * Preconditions: `$filePath` is the path passed to load(); `$exception`
 * is what the loader threw.
 *
 * Thread-safety: immutable; safe to share between fibers and requests.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     $listeners->listen(ConfigLoadError::class, static function (ConfigLoadError $event): void {
 *         error_log($event->filePath . ': ' . $event->exception->getMessage());
 *     });
 *
 * References: ADR-006; PSR-14. ARFA 1.3 P5: observable failures.
 *
 * @since 4.2.0
 */
final readonly class ConfigLoadError
{
    public function __construct(
        public string $filePath,
        public \Throwable $exception,
    ) {}
}
