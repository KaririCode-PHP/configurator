<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Contract;

use KaririCode\Configurator\Exception\AccessException;

/**
 * Central configuration access contract.
 *
 * Provides unified, type-safe access to hierarchical configuration
 * data with dot-notation path resolution. Implementations must
 * guarantee immutability after the loading phase completes.
 *
 * Dot-notation resolves nested keys:
 *   'database.connections.default.host' → $data['database']['connections']['default']['host']
 *
 * @package KaririCode\Configurator
 * @since   4.0.0 ARFA 1.3
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
