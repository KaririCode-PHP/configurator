<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Event;

/**
 * Reports, through PSR-14, that ReloadableConfiguration could not rebuild
 * itself after a watched file changed; the configuration it serves stays
 * the last one that built, and the same files are not rebuilt again until
 * they change.
 *
 * Preconditions: `$changedFiles` lists the watched files that changed
 * (every watched file after forceReload()); `$exception` is what the
 * builder threw, a LoaderException for a broken file.
 *
 * Thread-safety: immutable; safe to share between fibers and requests.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     $listeners->listen(ConfigReloadFailed::class, static function (ConfigReloadFailed $event): void {
 *         error_log('configuration not reloaded: ' . $event->exception->getMessage());
 *     });
 *
 * References: ADR-006, ADR-012; PSR-14. ARFA 1.3 P5: observable
 * failures.
 *
 * @since 4.2.0
 */
final readonly class ConfigReloadFailed
{
    /**
     * @param list<string> $changedFiles Files that triggered the reload
     */
    public function __construct(
        public array $changedFiles,
        public \Throwable $exception,
    ) {}
}
