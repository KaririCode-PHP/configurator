<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Tests\Integration;

use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * A PSR-14 dispatcher that keeps every event it receives, in order.
 */
final class RecordingDispatcher implements EventDispatcherInterface
{
    /** @var list<object> */
    public private(set) array $events = [];

    #[\Override]
    public function dispatch(object $event): object
    {
        $this->events[] = $event;

        return $event;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    public function of(string $class): array
    {
        return array_values(array_filter($this->events, static fn(object $event): bool => $event instanceof $class));
    }
}
