<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Exception;

use KaririCode\Exception\ConfigurationException as ConfigurationBase;

// ── Exception: AccessException ───────────────────────────────────────
// A built configuration used in a way it does not allow (2800–2809).
// ─────────────────────────────────────────────────────────────────────

/**
 * Rejects a use a built configuration does not allow: a typed read of a
 * value of another type, such as `getInt()` of a list, or a change after
 * `seal()`. The message names the path and the two types; the value is
 * never part of it.
 *
 * Preconditions: built only through its named constructors.
 *
 * Thread-safety: immutable once built.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     try {
 *         $port = $config->getInt('server.port');
 *     } catch (AccessException $e) {
 *         $message = $e->getMessage(); // [CFG_2800] Type mismatch at "server.port": expected int, got array.
 *     }
 *
 * References: ADR-003, ADR-011; Spec V4.3.3 §8.4 (Configuration family).
 * ARFA 1.3 P5: coded failures.
 *
 * @see https://github.com/KaririCode-PHP/exception/blob/main/README.md
 */
final class AccessException extends ConfigurationBase implements ConfigurationException
{
    /**
     * A typed accessor found a value it cannot return as $expected;
     * $actual describes the value's type, not the value.
     */
    public static function typeMismatch(string $path, string $expected, string $actual): self
    {
        return new self(ConfigCode::TypeMismatch, 'Type mismatch at ' . self::quote($path) . ": expected {$expected}, got {$actual}.");
    }

    public static function immutableViolation(): self
    {
        return new self(ConfigCode::ImmutableViolation, 'Configuration is immutable after loading completes.');
    }
}
