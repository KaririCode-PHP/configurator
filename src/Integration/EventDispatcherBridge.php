<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Integration;

use KaririCode\Configurator\Configuration;
use KaririCode\Configurator\Contract\ConfigValidator;
use KaririCode\Configurator\Contract\Configurator;
use KaririCode\Configurator\Contract\MergeStrategy;
use KaririCode\Configurator\Event\ConfigLoaded;
use KaririCode\Configurator\Event\ConfigLoadError;
use KaririCode\Configurator\Event\ConfigSealed;
use KaririCode\Configurator\MergeStrategy\RecursiveMerge;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Decorates Configuration with PSR-14 event dispatching.
 *
 * Emits lifecycle events: ConfigLoaded (after each file),
 * ConfigSealed (after seal), ConfigLoadError (on failure).
 * This enables observability, audit logging, and reactive
 * configuration hot-reloading in long-running processes.
 *
 * Requires: kariricode/event-dispatcher (PSR-14 EventDispatcherInterface)
 *
 * @package KaririCode\Configurator
 * @since   4.0.0 ARFA 1.3
 */
final readonly class EventDispatcherBridge implements Configurator
{
    /**
     * The decorated configuration, for what the bridge does not expose
     * (registerLoader(), merge()); loading through it emits no events.
     */
    public Configuration $inner;

    public function __construct(
        private EventDispatcherInterface $dispatcher,
        ?MergeStrategy $mergeStrategy = null,
        ?ConfigValidator $validator = null,
    ) {
        $this->inner = new Configuration(
            $mergeStrategy ?? new RecursiveMerge(),
            $validator,
        );
    }

    /**
     * Loads a file and emits ConfigLoaded on success, ConfigLoadError on failure.
     */
    public function load(string $path): self
    {
        try {
            $this->inner->load($path);
            $this->dispatcher->dispatch(new ConfigLoaded($path));
        } catch (\Throwable $exception) {
            $this->dispatcher->dispatch(new ConfigLoadError($path, $exception));

            throw $exception;
        }

        return $this;
    }

    public function seal(): self
    {
        $this->inner->seal();
        $this->dispatcher->dispatch(new ConfigSealed(array_map(strval(...), array_keys($this->inner->all()))));

        return $this;
    }

    // ── Configurator contract delegation ──────────────────────────────

    #[\Override]
    public function get(string $path, mixed $default = null): mixed
    {
        return $this->inner->get($path, $default);
    }

    #[\Override]
    public function getString(string $path, string $default = ''): string
    {
        return $this->inner->getString($path, $default);
    }

    #[\Override]
    public function getInt(string $path, int $default = 0): int
    {
        return $this->inner->getInt($path, $default);
    }

    #[\Override]
    public function getFloat(string $path, float $default = 0.0): float
    {
        return $this->inner->getFloat($path, $default);
    }

    #[\Override]
    public function getBool(string $path, bool $default = false): bool
    {
        return $this->inner->getBool($path, $default);
    }

    #[\Override]
    public function getArray(string $path, array $default = []): array
    {
        return $this->inner->getArray($path, $default);
    }

    #[\Override]
    public function has(string $path): bool
    {
        return $this->inner->has($path);
    }

    #[\Override]
    public function all(): array
    {
        return $this->inner->all();
    }
}
