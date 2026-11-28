<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Exception;

/**
 * Base exception for all configuration errors.
 *
 * Error code ranges:
 *   1000–1009  General configuration errors
 *   1100–1199  Loader errors (file I/O, parsing)
 *   1200–1299  Validation errors (schema, type)
 *   1300–1399  Merge errors
 *
 * @package KaririCode\Configurator
 * @since   4.0.0 ARFA 1.3
 */
class ConfigurationException extends \RuntimeException
{
    // ── General ───────────────────────────────────────────────────────
    private const int PATH_NOT_FOUND = 1000;
    private const int TYPE_MISMATCH = 1001;
    private const int IMMUTABLE_VIOLATION = 1002;
    private const int UNSUPPORTED_FORMAT = 1003;
    private const int UNDEFINED_ENV_VAR = 1004;
    private const int TYPE_CAST_FAILED = 1005;
    private const int DECRYPTION_FAILED = 1006;
    private const int UNSUPPORTED_YAML_FEATURE = 1007;
    private const int UNSUPPORTED_TOML_FEATURE = 1008;
    private const int ENCRYPTION_FAILED = 1009;

    public static function pathNotFound(string $path): self
    {
        return new self(
            "Configuration path not found: '{$path}'.",
            self::PATH_NOT_FOUND,
        );
    }

    public static function typeMismatch(string $path, string $expected, string $actual): self
    {
        return new self(
            "Type mismatch at '{$path}': expected {$expected}, got {$actual}.",
            self::TYPE_MISMATCH,
        );
    }

    public static function immutableViolation(): self
    {
        return new self(
            'Configuration is immutable after loading completes.',
            self::IMMUTABLE_VIOLATION,
        );
    }

    public static function unsupportedFormat(string $extension): self
    {
        return new self(
            "No loader registered for extension: '{$extension}'.",
            self::UNSUPPORTED_FORMAT,
        );
    }

    public static function undefinedEnvironmentVariable(string $name): self
    {
        return new self(
            "Undefined environment variable: '{$name}'. "
            . 'Provide a default value with ${' . $name . ':default} '
            . 'or define the variable in your environment.',
            self::UNDEFINED_ENV_VAR,
        );
    }

    public static function typeCastFailed(string $type, string $value): self
    {
        return new self(
            "Type cast to '{$type}' failed for value: '{$value}'.",
            self::TYPE_CAST_FAILED,
        );
    }

    public static function decryptionFailed(string $reason): self
    {
        return new self(
            "Configuration value decryption failed: {$reason}.",
            self::DECRYPTION_FAILED,
        );
    }

    public static function encryptionFailed(string $reason): self
    {
        return new self(
            "Configuration value encryption failed: {$reason}.",
            self::ENCRYPTION_FAILED,
        );
    }

    public static function unsupportedYamlFeature(string $feature, int $line): self
    {
        return new self(
            "Unsupported YAML feature at line {$line}: {$feature}. "
            . 'Use PHP format for advanced YAML features, or remove the unsupported syntax.',
            self::UNSUPPORTED_YAML_FEATURE,
        );
    }

    public static function unsupportedTomlFeature(string $feature, int $line): self
    {
        return new self(
            "Unsupported TOML feature at line {$line}: {$feature}. "
            . 'Use PHP format for advanced TOML features, or remove the unsupported syntax.',
            self::UNSUPPORTED_TOML_FEATURE,
        );
    }
}
