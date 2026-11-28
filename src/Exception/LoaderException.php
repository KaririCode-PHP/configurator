<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Exception;

use KaririCode\Exception\ConfigurationException as ConfigurationBase;

// ── Exception: LoaderException ───────────────────────────────────────
// A file the configuration cannot load or parse (2810–2829).
// ─────────────────────────────────────────────────────────────────────

/**
 * Reports a configuration file that cannot be used: an extension no
 * loader handles, a file that is missing or unreadable, content that
 * breaks its format or uses a feature outside the supported subset, or
 * a PHP or JSON file that does not hold an array. Messages name the file
 * and the line; they describe what broke without repeating the content,
 * so a secret on a broken line does not reach a log.
 *
 * Preconditions: built only through its named constructors. The parsers
 * raise `invalidSyntax()`, which knows no file; the loader that read the
 * file reports it as `parseError()` with the same `$reason`.
 *
 * Thread-safety: immutable once built.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     try {
 *         $data = new YamlLoader()->load('/app/config/app.yaml');
 *     } catch (LoaderException $e) {
 *         $why = $e->reason; // "bad indentation on line 4", without the file
 *     }
 *
 * References: ADR-002, ADR-008, ADR-011; Spec V4.3.3 §8.4 (Configuration
 * family). ARFA 1.3 P5: coded failures.
 *
 * @see https://github.com/KaririCode-PHP/exception/blob/main/README.md
 */
final class LoaderException extends ConfigurationBase implements ConfigurationException
{
    /** What made the content unparseable, without the file; empty for the other failures. */
    public readonly string $reason;

    private function __construct(ConfigCode $code, string $message, string $reason = '', ?\Throwable $previous = null)
    {
        parent::__construct($code, $message, $previous);
        $this->reason = $reason;
    }

    public static function unsupportedFormat(string $extension): self
    {
        return new self(code: ConfigCode::UnsupportedFormat, message: 'No loader registered for extension: ' . self::quote($extension) . '.');
    }

    public static function fileNotFound(string $path): self
    {
        return new self(code: ConfigCode::FileNotFound, message: 'Configuration file not found: ' . self::quote($path) . '.');
    }

    public static function fileUnreadable(string $path): self
    {
        return new self(code: ConfigCode::FileUnreadable, message: 'Configuration file is not readable: ' . self::quote($path) . '.');
    }

    /**
     * For custom Loader implementations that confine paths to a base
     * directory. None of the built-in loaders performs that check.
     */
    public static function pathTraversal(string $path): self
    {
        return new self(code: ConfigCode::PathTraversal, message: 'Path traversal detected: ' . self::quote($path) . '.');
    }

    /**
     * Content that breaks its format, found while parsing; the loader
     * that read the file reports it through parseError().
     */
    public static function invalidSyntax(string $reason): self
    {
        return new self(code: ConfigCode::ParseError, message: $reason, reason: $reason);
    }

    public static function parseError(string $path, string $reason, ?\Throwable $previous = null): self
    {
        return new self(
            code: ConfigCode::ParseError,
            message: 'Failed to parse ' . self::quote($path) . ": {$reason}.",
            reason: $reason,
            previous: $previous,
        );
    }

    public static function invalidReturnType(string $path, string $actual): self
    {
        return new self(code: ConfigCode::InvalidReturnType, message: 'Configuration file ' . self::quote($path) . " must hold an array, got {$actual}.");
    }

    public static function unsupportedYamlFeature(string $feature, int $line): self
    {
        return new self(
            code: ConfigCode::UnsupportedYamlFeature,
            message: "Unsupported YAML feature at line {$line}: {$feature}. Use PHP format for advanced YAML features, or remove the unsupported syntax.",
        );
    }

    public static function unsupportedTomlFeature(string $feature, int $line): self
    {
        return new self(
            code: ConfigCode::UnsupportedTomlFeature,
            message: "Unsupported TOML feature at line {$line}: {$feature}. Use PHP format for advanced TOML features, or remove the unsupported syntax.",
        );
    }
}
