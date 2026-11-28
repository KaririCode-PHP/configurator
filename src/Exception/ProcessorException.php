<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Exception;

use KaririCode\Exception\ConfigurationException as ConfigurationBase;

// ── Exception: ProcessorException ────────────────────────────────────
// A value processor that cannot produce its value (2830–2849).
// ─────────────────────────────────────────────────────────────────────

/**
 * Reports a value the processor chain cannot resolve when the
 * configuration is sealed: `${VAR}` without a value or a default in
 * strict mode, a cast prefix whose payload does not convert, or an
 * `ENC(...)` value that does not decrypt with the configured key, and an
 * encryption key of the wrong size. Messages name variables, cast types
 * and causes; never a value, a payload or a key.
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
 *         $config = ConfigurationBuilder::create()->withEnvInterpolation()->withFile($path)->build();
 *     } catch (ProcessorException $e) {
 *         $undefined = $e->errorCode === ConfigCode::UndefinedVariable;
 *     }
 *
 * References: ADR-004, ADR-005, ADR-011; Spec V4.3.3 §8.4 (Configuration
 * family), §8.3 (fail closed). ARFA 1.3 P5: coded failures.
 *
 * @see https://github.com/KaririCode-PHP/exception/blob/main/README.md
 */
final class ProcessorException extends ConfigurationBase implements ConfigurationException
{
    public static function undefinedVariable(string $name): self
    {
        return new self(
            ConfigCode::UndefinedVariable,
            'Undefined environment variable: ' . self::quote($name) . '. Provide a default value with ${'
            . $name . ':default} or define the variable in your environment.',
        );
    }

    /**
     * A cast prefix met a payload it cannot convert; $reason says why
     * without repeating the payload.
     */
    public static function typeCastFailed(string $type, string $reason): self
    {
        return new self(ConfigCode::TypeCastFailed, "Type cast to '{$type}' failed: {$reason}.");
    }

    public static function invalidEncryptionKey(): self
    {
        return new self(ConfigCode::InvalidEncryptionKey, 'Encryption key must be 32 bytes (256 bits). Provide raw 32 bytes or 64 hex characters.');
    }

    public static function decryptionFailed(string $reason): self
    {
        return new self(ConfigCode::DecryptionFailed, "Configuration value decryption failed: {$reason}.");
    }

    public static function encryptionFailed(string $reason): self
    {
        return new self(ConfigCode::EncryptionFailed, "Configuration value encryption failed: {$reason}.");
    }
}
