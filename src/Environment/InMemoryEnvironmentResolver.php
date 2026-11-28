<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Environment;

use KaririCode\Contract\Environment\EnvironmentResolver;

/**
 * Resolves environment variables from a map the caller owns, so tests
 * and tools interpolate `${VAR}` without touching the process
 * environment.
 *
 * Preconditions: names and values are strings; set() and remove() change
 * the map in place.
 *
 * Thread-safety: mutable; share it only when nothing calls set() or
 * remove() any more.
 *
 * Side effects: none outside the instance.
 *
 * Example:
 *
 *     $resolver = new InMemoryEnvironmentResolver(['DB_HOST' => 'localhost']);
 *     $config = ConfigurationBuilder::create()->withEnvInterpolation($resolver)->build();
 *
 * References: ADR-004; kariricode/contract
 * `Environment\EnvironmentResolver`.
 *
 * @since 4.2.0
 */
final class InMemoryEnvironmentResolver implements EnvironmentResolver
{
    /**
     * @param array<string, string> $variables
     */
    public function __construct(
        #[\SensitiveParameter]
        private array $variables = [],
    ) {}

    #[\Override]
    public function get(string $name): ?string
    {
        return $this->variables[$name] ?? null;
    }

    #[\Override]
    public function has(string $name): bool
    {
        return isset($this->variables[$name]);
    }

    public function set(string $name, #[\SensitiveParameter] string $value): self
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
