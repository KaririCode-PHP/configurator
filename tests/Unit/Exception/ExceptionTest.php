<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Tests\Unit\Exception;

use KaririCode\Configurator\Exception\ConfigurationException;
use KaririCode\Configurator\Exception\LoaderException;
use KaririCode\Configurator\Exception\MergeException;
use KaririCode\Configurator\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

final class ExceptionTest extends TestCase
{
    // ── ConfigurationException ────────────────────────────────────────

    public function testPathNotFound(): void
    {
        $e = ConfigurationException::pathNotFound('app.missing');

        self::assertSame(1000, $e->getCode());
        self::assertStringContainsString('app.missing', $e->getMessage());
    }

    public function testTypeMismatch(): void
    {
        $e = ConfigurationException::typeMismatch('app.port', 'int', 'string');

        self::assertSame(1001, $e->getCode());
        self::assertStringContainsString('app.port', $e->getMessage());
        self::assertStringContainsString('int', $e->getMessage());
        self::assertStringContainsString('string', $e->getMessage());
    }

    public function testImmutableViolation(): void
    {
        $e = ConfigurationException::immutableViolation();

        self::assertSame(1002, $e->getCode());
    }

    public function testUnsupportedFormat(): void
    {
        $e = ConfigurationException::unsupportedFormat('toml');

        self::assertSame(1003, $e->getCode());
        self::assertStringContainsString('toml', $e->getMessage());
    }

    // ── LoaderException ───────────────────────────────────────────────

    public function testLoaderExceptionExtendsBase(): void
    {
        $e = LoaderException::fileNotFound('/missing.php');

        self::assertSame(ConfigurationException::class, get_parent_class($e));
        self::assertSame(1100, $e->getCode());
    }

    public function testParseError(): void
    {
        $e = LoaderException::parseError('/bad.json', 'Syntax error');

        self::assertSame(1102, $e->getCode());
        self::assertStringContainsString('/bad.json', $e->getMessage());
        self::assertStringContainsString('Syntax error', $e->getMessage());
    }

    public function testPathTraversal(): void
    {
        $e = LoaderException::pathTraversal('../../etc/passwd');

        self::assertSame(1104, $e->getCode());
    }

    // ── ValidationException ───────────────────────────────────────────

    public function testSchemaViolationsCarriesList(): void
    {
        $violations = ['Missing key: app.name', 'Type mismatch at app.port'];
        $e = ValidationException::schemaViolations($violations);

        self::assertSame(1200, $e->getCode());
        self::assertSame($violations, $e->getViolations());
        self::assertStringContainsString('2 violation(s)', $e->getMessage());
    }

    public function testRequiredMissing(): void
    {
        $e = ValidationException::requiredMissing('db.password');

        self::assertSame(1201, $e->getCode());
        self::assertCount(1, $e->getViolations());
    }

    // ── MergeException ────────────────────────────────────────────────

    public function testConflict(): void
    {
        $e = MergeException::conflict('cache.driver', 'string', 'array');

        self::assertSame(1300, $e->getCode());
        self::assertStringContainsString('cache.driver', $e->getMessage());
    }

    public function testTypeConstraint(): void
    {
        $e = ValidationException::typeConstraint('app.port', 'int', 'string');

        self::assertSame(1202, $e->getCode());
        self::assertSame(['Type constraint: app.port expected int, got string'], $e->getViolations());
    }

    public function testEncryptionFailed(): void
    {
        $e = ConfigurationException::encryptionFailed('OpenSSL could not encrypt the value');

        self::assertSame(1009, $e->getCode());
    }
}
