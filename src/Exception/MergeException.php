<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Exception;

use KaririCode\Exception\ConfigurationException as ConfigurationBase;

// ── Exception: MergeException ────────────────────────────────────────
// Two configuration arrays that cannot be merged (2860–2869).
// ─────────────────────────────────────────────────────────────────────

/**
 * Lets a custom MergeStrategy reject a merge, typically a scalar in the
 * base that meets an array in the source at the same path. The built-in
 * RecursiveMerge and ReplaceMerge never throw it: they resolve every
 * conflict in favour of the source.
 *
 * Preconditions: built only through its named constructor.
 *
 * Thread-safety: immutable once built.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     throw MergeException::conflict('database.options', 'string', 'array');
 *
 * References: ADR-001, ADR-011; Spec V4.3.3 §8.4 (Configuration family).
 * ARFA 1.3 P5: coded failures.
 *
 * @see https://github.com/KaririCode-PHP/exception/blob/main/README.md
 */
final class MergeException extends ConfigurationBase implements ConfigurationException
{
    public static function conflict(string $path, string $baseType, string $sourceType): self
    {
        return new self(
            ConfigCode::MergeConflict,
            'Merge conflict at ' . self::quote($path) . ": cannot merge {$baseType} with {$sourceType}.",
        );
    }
}
