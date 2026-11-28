<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Environment;

use KaririCode\Contract\Environment\EnvironmentResolverInterface;

/**
 * In-memory environment resolver for testing.
 *
 * Provides a controlled, deterministic environment for unit tests
 * and integration tests without touching system globals.
 *
 * @package KaririCode\Configurator
 * @since   4.1.0 ARFA 1.3
 */
final class InMemoryEnvironmentResolver implements EnvironmentResolverInterface
{
    /**
     * @param array<string, string> $variables
     */
    public function __construct(
        private array $variables = [],
    ) {
    }

    public function get(string $name): ?string
    {
        return $this->variables[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->variables[$name]);
    }

    public function set(string $name, string $value): self
    {
        $this->variables[$name] = $value;

        return $this;
    }

    public function remove(string $name): self
    {
        unset($this->variables[$name]);

        return $this;
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->variables;
    }
}
