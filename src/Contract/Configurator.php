<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Contract;

use KaririCode\Configurator\Exception\AccessException;

/**
 * Reads configuration by dot-notation path with typed accessors, so code
 * depends on what it reads rather than on how the configuration was
 * built: `database.connections.default.host` resolves
 * `$data['database']['connections']['default']['host']`.
 *
 * Preconditions: get() and has() never throw; a stored null reads as
 * missing. The typed accessors coerce where it is safe (numeric strings,
 * the boolean words) and otherwise raise AccessException (CFG_2800). An
 * implementation does not change after its loading phase.
 *
 * Thread-safety: implementations are safe to share once loaded.
 *
 * Side effects: none in Configuration and EventDispatcherBridge;
 * ReloadableConfiguration may rebuild itself on a read.
 *
 * Example:
 *
 *     function connect(Configurator $config): \PDO
 *     {
 *         return new \PDO($config->getString('database.dsn'));
 *     }
 *
 * References: SPEC-CONFIGURATOR §8, ADR-003. ARFA 1.3 P1: read-only
 * access to an immutable value.
 *
 * @since 4.2.0
 */
interface Configurator
{
    /**
     * Retrieves a configuration value by dot-notation path.
     *
     * Never throws: returns $default when the path does not exist, is
     * malformed (empty segments), or holds null. A stored null is
     * indistinguishable from a missing key (see has()).
     */
    public function get(string $path, mixed $default = null): mixed;

    /**
     * Retrieves a string value, coercing scalars when safe.
     *
     * @throws AccessException When value cannot be represented as string (CFG_2800)
     */
    public function getString(string $path, string $default = ''): string;

    /**
     * Retrieves an integer value with strict numeric parsing.
     *
     * @throws AccessException When value is not numeric (CFG_2800)
     */
    public function getInt(string $path, int $default = 0): int;

    /**
     * Retrieves a float value with strict numeric parsing.
     *
     * @throws AccessException When value is not numeric (CFG_2800)
     */
    public function getFloat(string $path, float $default = 0.0): float;

    /**
     * Retrieves a boolean value.
     *
     * Truthy: true, any non-zero int, 'true', '1', 'yes', 'on'
     * Falsy:  false, 0, 'false', '0', 'no', 'off', ''
     * String matching is case-insensitive.
     *
     * @throws AccessException When the value is any other string, or not a
     *                         bool, int, or string (CFG_2800)
     */
    public function getBool(string $path, bool $default = false): bool;

    /**
     * Retrieves an array value.
     *
     * @param array<array-key, mixed> $default
     *
     * @return array<array-key, mixed>
     *
     * @throws AccessException When value is not an array (CFG_2800)
     */
    public function getArray(string $path, array $default = []): array;

    /**
     * Returns false both for a missing path and for a path holding null.
     */
    public function has(string $path): bool;

    /**
     * Returns all configuration data as a nested array.
     *
     * @return array<array-key, mixed>
     */
    public function all(): array;
}
