<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Processor;

use KaririCode\Configurator\Exception\ProcessorException;
use KaririCode\Contract\Configuration\ValueProcessor;

/**
 * Decrypts configuration values written as `ENC(base64)` when the
 * configuration is sealed, with AES-256-GCM: authenticated encryption,
 * so a tampered or truncated value fails instead of decrypting to
 * garbage.
 *
 * The payload is nonce (12 bytes), ciphertext and tag (16 bytes),
 * base64-encoded inside the envelope; encrypt() produces it for tools
 * and deployment scripts. The key is 32 raw bytes or 64 hex characters
 * and comes from the environment or a secrets manager, never from a
 * configuration file. Key rotation is outside this processor.
 *
 * Preconditions: ext-openssl is loaded; the key is the one the values
 * were encrypted with.
 *
 * Thread-safety: immutable; holds the key for its lifetime.
 *
 * Side effects: none; decryption happens in memory and failures name the
 * cause, never the value or the key.
 *
 * Example:
 *
 *     $processor = new EncryptedValueProcessor($_SERVER['CONFIG_KEY']);
 *     $password = $processor->process(EncryptedValueProcessor::encrypt('s3cr3t', $_SERVER['CONFIG_KEY']));
 *
 * References: NIST SP 800-38D (GCM), ADR-005, ADR-011; OWASP ASVS 5.0
 * V11 (cryptography), V13 (configuration secrets).
 *
 * @since 4.2.0
 */
final readonly class EncryptedValueProcessor implements ValueProcessor
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
     * @throws ProcessorException When the key is not 32 raw bytes or 64 hex characters
     */
    public function __construct(string $key)
    {
        $this->key = self::resolveKey($key);
    }

    /**
     * Normalizes a key to 32 raw bytes, accepting either a raw
     * 32-byte string or a 64-character hex-encoded string.
     *
     * @throws ProcessorException When the key is neither 32 raw bytes nor
     *                            valid 64-char hex
     */
    private static function resolveKey(string $key): string
    {
        if (preg_match('/^[0-9a-fA-F]{64}\z/', $key) === 1) {
            $decoded = hex2bin($key);

            // hex2bin returns false on failure; guaranteed valid here
            // since the input is even-length and all hex digits.
            $key = $decoded !== false ? $decoded : '';
        }

        if (strlen($key) !== 32) {
            throw ProcessorException::invalidEncryptionKey();
        }

        return $key;
    }

    #[\Override]
    public function supports(mixed $value): bool
    {
        return is_string($value)
            && str_starts_with($value, self::ENVELOPE_PREFIX)
            && str_ends_with($value, self::ENVELOPE_SUFFIX);
    }

    #[\Override]
    public function process(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $encoded = substr(
            string: $value,
            offset: strlen(self::ENVELOPE_PREFIX),
            length: -strlen(self::ENVELOPE_SUFFIX),
        );

        $raw = base64_decode($encoded, true);

        if ($raw === false) {
            throw ProcessorException::decryptionFailed('invalid base64 encoding');
        }

        // Minimum = nonce + tag. GCM ciphertext length equals plaintext
        // length, so a legitimately-encrypted empty string yields a
        // zero-byte ciphertext (payload = nonce + tag exactly).
        $minLength = self::NONCE_LENGTH + self::TAG_LENGTH;

        if (strlen($raw) < $minLength) {
            throw ProcessorException::decryptionFailed('ciphertext too short');
        }

        $nonce = substr(string: $raw, offset: 0, length: self::NONCE_LENGTH);
        $tag = substr($raw, -self::TAG_LENGTH);
        $ciphertext = substr(string: $raw, offset: self::NONCE_LENGTH, length: -self::TAG_LENGTH);

        $plaintext = openssl_decrypt(
            data: $ciphertext,
            cipher_algo: self::CIPHER,
            passphrase: $this->key,
            options: OPENSSL_RAW_DATA,
            iv: $nonce,
            tag: $tag,
        );

        if ($plaintext === false) {
            throw ProcessorException::decryptionFailed(
                'decryption failed — invalid key, corrupted data, or tampered ciphertext',
            );
        }

        return $plaintext;
    }

    // ── Utility ──────────────────────────────────────────────────────────

    /**
     * Encrypts a plaintext value into the ENC() envelope format.
     *
     * Utility method for generating encrypted config values.
     * Not part of the loading pipeline — used by CLI tools and
     * deployment scripts.
     *
     * @throws ProcessorException When the key is not 32 raw bytes or 64 hex characters, or OpenSSL fails to encrypt
     */
    public static function encrypt(string $plaintext, string $key): string
    {
        $resolvedKey = self::resolveKey($key);

        $nonce = random_bytes(self::NONCE_LENGTH);
        $tag = '';

        $ciphertext = openssl_encrypt(
            data: $plaintext,
            cipher_algo: self::CIPHER,
            passphrase: $resolvedKey,
            options: OPENSSL_RAW_DATA,
            iv: $nonce,
            tag: $tag,
            aad: '',
            tag_length: self::TAG_LENGTH,
        );

        if ($ciphertext === false) {
            throw ProcessorException::encryptionFailed('OpenSSL could not encrypt the value');
        }

        $payload = $nonce . $ciphertext . $tag;

        return self::ENVELOPE_PREFIX . base64_encode($payload) . self::ENVELOPE_SUFFIX;
    }
}
