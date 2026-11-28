<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Processor;

use KaririCode\Configurator\Exception\ConfigurationException;
use KaririCode\Contract\Configuration\ValueProcessorInterface;

/**
 * Decrypts values wrapped in the ENC() envelope during config loading.
 *
 * Syntax: "ENC(base64-encoded-ciphertext)"
 *
 * Uses AES-256-GCM by default (authenticated encryption). The encryption
 * key must be provided at construction time via a 256-bit (32-byte) key
 * or a hex-encoded key string.
 *
 * Ciphertext format: nonce (12 bytes) || ciphertext || tag (16 bytes),
 * all base64-encoded inside the ENC() envelope.
 *
 * Security considerations:
 *   - Key should come from environment variable or secrets manager, never hardcoded
 *   - GCM tag ensures integrity — tampered ciphertext throws
 *   - No key rotation support in this processor; use a secrets manager for rotation
 *
 * Ref: NIST SP 800-38D (GCM); Hashicorp Vault transit engine model;
 *      KaririCode\Dotenv V4.x AES-256-GCM convention.
 *
 * @package KaririCode\Configurator
 * @since   4.1.0 ARFA 1.3
 */
final readonly class EncryptedValueProcessor implements ValueProcessorInterface
{
    private const string CIPHER = 'aes-256-gcm';
    private const int NONCE_LENGTH = 12;
    private const int TAG_LENGTH = 16;
    private const string ENVELOPE_PREFIX = 'ENC(';
    private const string ENVELOPE_SUFFIX = ')';

    private string $key;

    /**
     * @param string $key 32-byte raw key or 64-char hex-encoded key
     */
    /**
     * @throws \InvalidArgumentException When the key is not 32 raw bytes or 64 hex characters
     */
    public function __construct(string $key)
    {
        $this->key = self::resolveKey($key);
    }

    /**
     * Normalizes a key to 32 raw bytes, accepting either a raw
     * 32-byte string or a 64-character hex-encoded string.
     *
     * @throws \InvalidArgumentException When the key is neither 32 raw
     *                                   bytes nor valid 64-char hex
     */
    private static function resolveKey(string $key): string
    {
        if (preg_match('/^[0-9a-fA-F]{64}$/', $key) === 1) {
            $decoded = hex2bin($key);

            // hex2bin returns false on failure; guaranteed valid here
            // since the input is even-length and all hex digits.
            $key = $decoded !== false ? $decoded : '';
        }

        if (strlen($key) !== 32) {
            throw new \InvalidArgumentException(
                'Encryption key must be 32 bytes (256 bits). '
                . 'Provide raw 32 bytes or 64 hex characters.',
            );
        }

        return $key;
    }

    public function supports(mixed $value): bool
    {
        return is_string($value)
            && str_starts_with($value, self::ENVELOPE_PREFIX)
            && str_ends_with($value, self::ENVELOPE_SUFFIX);
    }

    public function process(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $encoded = substr(
            $value,
            strlen(self::ENVELOPE_PREFIX),
            -strlen(self::ENVELOPE_SUFFIX),
        );

        $raw = base64_decode($encoded, true);

        if ($raw === false) {
            throw ConfigurationException::decryptionFailed('invalid base64 encoding');
        }

        // Minimum = nonce + tag. GCM ciphertext length equals plaintext
        // length, so a legitimately-encrypted empty string yields a
        // zero-byte ciphertext (payload = nonce + tag exactly).
        $minLength = self::NONCE_LENGTH + self::TAG_LENGTH;

        if (strlen($raw) < $minLength) {
            throw ConfigurationException::decryptionFailed('ciphertext too short');
        }

        $nonce = substr($raw, 0, self::NONCE_LENGTH);
        $tag = substr($raw, -self::TAG_LENGTH);
        $ciphertext = substr($raw, self::NONCE_LENGTH, -self::TAG_LENGTH);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
        );

        if ($plaintext === false) {
            throw ConfigurationException::decryptionFailed(
                'decryption failed — invalid key, corrupted data, or tampered ciphertext',
            );
        }

        return $plaintext;
    }

    // ── Utility ───────────────────────────────────────────────────────

    /**
     * Encrypts a plaintext value into the ENC() envelope format.
     *
     * Utility method for generating encrypted config values.
     * Not part of the loading pipeline — used by CLI tools and
     * deployment scripts.
     *
     * @throws \InvalidArgumentException When the key is not 32 raw bytes or 64 hex characters
     * @throws ConfigurationException    When OpenSSL fails to encrypt (code 1009)
     */
    public static function encrypt(string $plaintext, string $key): string
    {
        $resolvedKey = self::resolveKey($key);

        $nonce = random_bytes(self::NONCE_LENGTH);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $resolvedKey,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            self::TAG_LENGTH,
        );

        if ($ciphertext === false) {
            throw ConfigurationException::encryptionFailed('OpenSSL could not encrypt the value');
        }

        $payload = $nonce . $ciphertext . $tag;

        return self::ENVELOPE_PREFIX . base64_encode($payload) . self::ENVELOPE_SUFFIX;
    }
}
