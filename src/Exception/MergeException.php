<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Exception;

/**
 * Raised when two configuration arrays cannot be merged.
 *
 * Typical cause: a scalar in the base conflicts with an
 * array in the source at the same key path.
 *
 * @package KaririCode\Configurator
 * @since   4.0.0 ARFA 1.3
 */
final class MergeException extends ConfigurationException
{
    private const int CONFLICT = 1300;

    /**
     * For custom MergeStrategy implementations that reject a merge. The
     * built-in RecursiveMerge and ReplaceMerge never throw it.
     */
    public static function conflict(string $path, string $baseType, string $sourceType): self
    {
        return new self(
            "Merge conflict at '{$path}': cannot merge {$baseType} with {$sourceType}.",
            self::CONFLICT,
        );
    }
}
