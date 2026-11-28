<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Tests\Unit\Processor;

use KaririCode\Configurator\Exception\ConfigurationException;
use KaririCode\Configurator\Processor\EncryptedValueProcessor;
use PHPUnit\Framework\TestCase;

final class EncryptedValueProcessorTest extends TestCase
{
    private string $rawKey;
    private string $hexKey;

    protected function setUp(): void
    {
        $this->rawKey = random_bytes(32);
        $this->hexKey = bin2hex($this->rawKey);
    }

    // ── supports() ────────────────────────────────────────────────────

    public function testSupportsEncEnvelope(): void
    {
        $processor = new EncryptedValueProcessor($this->rawKey);

        self::assertTrue($processor->supports('ENC(dGVzdA==)'));
        self::assertTrue($processor->supports('ENC(abc123)'));
    }

    public function testDoesNotSupportPlainStrings(): void
    {
        $processor = new EncryptedValueProcessor($this->rawKey);

        self::assertFalse($processor->supports('plain_text'));
        self::assertFalse($processor->supports('ENC('));
        self::assertFalse($processor->supports('ENCRYPTED(data)'));
        self::assertFalse($processor->supports(42));
    }

    // ── Round-trip ────────────────────────────────────────────────────

    public function testEncryptDecryptRoundTrip(): void
    {
        $processor = new EncryptedValueProcessor($this->rawKey);
        $plaintext = 's3cr3t_p@ssw0rd!';

        $encrypted = EncryptedValueProcessor::encrypt($plaintext, $this->rawKey);
        self::assertStringStartsWith('ENC(', $encrypted);
        self::assertStringEndsWith(')', $encrypted);

        $decrypted = $processor->process($encrypted);
        self::assertSame($plaintext, $decrypted);
    }

    public function testEmptyPlaintextRoundTrip(): void
    {
        // Regression: GCM produces 0-byte ciphertext for empty input,
        // so the payload is exactly nonce + tag. Must still decrypt.
        $processor = new EncryptedValueProcessor($this->rawKey);

        $encrypted = EncryptedValueProcessor::encrypt('', $this->rawKey);
        self::assertSame('', $processor->process($encrypted));
    }

    public function testUnicodePlaintextRoundTrip(): void
    {
        $processor = new EncryptedValueProcessor($this->rawKey);
        $plaintext = 'senha_çãõ_日本語_🔐';

        $encrypted = EncryptedValueProcessor::encrypt($plaintext, $this->rawKey);
        self::assertSame($plaintext, $processor->process($encrypted));
    }

    public function testRoundTripWithHexKey(): void
    {
        $processor = new EncryptedValueProcessor($this->hexKey);
        $plaintext = 'database_password';

        $encrypted = EncryptedValueProcessor::encrypt($plaintext, $this->hexKey);
        $decrypted = $processor->process($encrypted);

        self::assertSame($plaintext, $decrypted);
    }

    public function testDifferentPlaintextsProduceDifferentCiphertext(): void
    {
        $enc1 = EncryptedValueProcessor::encrypt('password1', $this->rawKey);
        $enc2 = EncryptedValueProcessor::encrypt('password2', $this->rawKey);

        self::assertNotSame($enc1, $enc2);
    }

    public function testSamePlaintextProducesDifferentCiphertext(): void
    {
        // Different nonces should produce different ciphertext
        $enc1 = EncryptedValueProcessor::encrypt('same', $this->rawKey);
        $enc2 = EncryptedValueProcessor::encrypt('same', $this->rawKey);

        self::assertNotSame($enc1, $enc2);
    }

    // ── Error cases ───────────────────────────────────────────────────

    public function testInvalidKeyLengthThrows(): void
    {
        $this->expectException(\KaririCode\Configurator\Exception\ProcessorException::class);
        $this->expectExceptionCode(2840);

        new EncryptedValueProcessor('too_short');
    }

    public function testInvalidBase64Throws(): void
    {
        $processor = new EncryptedValueProcessor($this->rawKey);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('invalid base64');

        $processor->process('ENC(!!!not-base64!!!)');
    }

    public function testTamperedCiphertextThrows(): void
    {
        $processor = new EncryptedValueProcessor($this->rawKey);
        $encrypted = EncryptedValueProcessor::encrypt('test', $this->rawKey);

        // Tamper with the base64 payload
        $tampered = substr($encrypted, 0, -2) . 'XX)';

        $this->expectException(ConfigurationException::class);

        $processor->process($tampered);
    }

    public function testWrongKeyCannotDecrypt(): void
    {
        $processor = new EncryptedValueProcessor(random_bytes(32));
        $encrypted = EncryptedValueProcessor::encrypt('test', $this->rawKey);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('decryption failed');

        $processor->process($encrypted);
    }

    public function testCiphertextTooShortThrows(): void
    {
        $processor = new EncryptedValueProcessor($this->rawKey);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('too short');

        $processor->process('ENC(' . base64_encode('short') . ')');
    }

    // ── Non-string pass-through ───────────────────────────────────────

    public function testNonStringPassesThrough(): void
    {
        $processor = new EncryptedValueProcessor($this->rawKey);

        self::assertSame(42, $processor->process(42));
    }
}
