<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Exception;

/**
 * Raised when a configuration file cannot be loaded or parsed.
 *
 * @package KaririCode\Configurator
 * @since   4.0.0 ARFA 1.3
 */
final class LoaderException extends ConfigurationException
{
    private const int FILE_NOT_FOUND = 1100;
    private const int FILE_UNREADABLE = 1101;
    private const int PARSE_ERROR = 1102;
    private const int INVALID_RETURN_TYPE = 1103;
    private const int PATH_TRAVERSAL = 1104;

    public static function fileNotFound(string $path): self
    {
        return new self(
            "Configuration file not found: '{$path}'.",
            self::FILE_NOT_FOUND,
        );
    }

    public static function fileUnreadable(string $path): self
    {
        return new self(
            "Configuration file is not readable: '{$path}'.",
            self::FILE_UNREADABLE,
        );
    }

    public static function parseError(string $path, string $reason): self
    {
        return new self(
            "Failed to parse '{$path}': {$reason}.",
            self::PARSE_ERROR,
        );
    }

    public static function invalidReturnType(string $path, string $actual): self
    {
        return new self(
            "PHP config file '{$path}' must return array, got {$actual}.",
            self::INVALID_RETURN_TYPE,
        );
    }

    /**
     * For custom Loader implementations that confine paths to a base
     * directory. None of the built-in loaders performs that check.
     */
    public static function pathTraversal(string $path): self
    {
        return new self(
            "Path traversal detected: '{$path}'.",
            self::PATH_TRAVERSAL,
        );
    }
}
