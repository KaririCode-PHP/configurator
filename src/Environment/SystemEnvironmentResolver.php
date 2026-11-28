<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Environment;

use KaririCode\Contract\Environment\EnvironmentResolverInterface;

/**
 * Resolves environment variables from PHP runtime sources.
 *
 * Lookup order: $_ENV → $_SERVER → getenv(). This matches the
 * resolution order used by phpdotenv and Symfony DotEnv, ensuring
 * compatibility with both .env file loaders and system-level
 * environment variables set by Docker, Kubernetes, or systemd.
 *
 * This is the default resolver when no Dotenv integration is
 * configured. For production, prefer DotenvEnvironmentResolver
 * which provides .env file loading with system fallback.
 *
 * For testing, use InMemoryEnvironmentResolver instead.
 *
 * @package KaririCode\Configurator
 * @since   4.1.0 ARFA 1.3
 */
final readonly class SystemEnvironmentResolver implements EnvironmentResolverInterface
{
    public function get(string $name): ?string
    {
        $value = $this->scalarToString($_ENV[$name] ?? null)
            ?? $this->scalarToString($_SERVER[$name] ?? null)
            ?? getenv($name);

        return $value !== false ? $value : null;
    }

    public function has(string $name): bool
    {
        return $this->get($name) !== null;
    }

    /**
     * Superglobals also hold non-scalar entries ($_SERVER['argv'] is an
     * array); only scalars are environment values. Casting an array
     * would yield "Array" and emit a warning.
     */
    private function scalarToString(mixed $value): ?string
    {
        return is_string($value) || is_int($value) || is_float($value) ? (string) $value : null;
    }
}
