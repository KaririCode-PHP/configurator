<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Environment;

use KaririCode\Contract\Environment\EnvironmentResolver;

/**
 * Resolves environment variables from the process: `$_ENV`, then
 * `$_SERVER`, then getenv(), the order phpdotenv and Symfony Dotenv use,
 * so values set by Docker, Kubernetes, systemd or a .env loader are all
 * found.
 *
 * It is the default resolver of `${VAR}` interpolation. Entries that are
 * not scalars (`$_SERVER['argv']`) are not environment values and
 * resolve to null.
 *
 * Preconditions: none.
 *
 * Thread-safety: immutable; reads the process environment on every call
 * and caches nothing.
 *
 * Side effects: none; it never writes the environment.
 *
 * Example:
 *
 *     $home = new SystemEnvironmentResolver()->get('HOME');
 *
 * References: ADR-004; kariricode/contract
 * `Environment\EnvironmentResolver`.
 *
 * @since 4.2.0
 */
final readonly class SystemEnvironmentResolver implements EnvironmentResolver
{
    #[\Override]
    public function get(string $name): ?string
    {
        $value = $this->scalarToString($_ENV[$name] ?? null)
            ?? $this->scalarToString($_SERVER[$name] ?? null)
            ?? getenv($name);

        return $value !== false ? $value : null;
    }

    #[\Override]
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
