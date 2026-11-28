<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Tests\Unit\Exception;

use KaririCode\Configurator\Exception\AccessException;
use KaririCode\Configurator\Exception\ConfigCode;
use KaririCode\Configurator\Exception\ConfigurationException;
use KaririCode\Configurator\Exception\LoaderException;
use KaririCode\Configurator\Exception\MergeException;
use KaririCode\Configurator\Exception\ProcessorException;
use KaririCode\Configurator\Exception\ValidationException;
use KaririCode\Contract\Exception\Configuration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExceptionTest extends TestCase
{
    /**
     * @return iterable<string, array{\Closure(): ConfigurationException, ConfigCode, string}>
     */
    public static function exceptions(): iterable
    {
        yield 'type mismatch' => [static fn(): AccessException => AccessException::typeMismatch('app.port', 'int', 'array'), ConfigCode::TypeMismatch, '[CFG_2800] Type mismatch at "app.port": expected int, got array.'];
        yield 'immutable violation' => [static fn(): AccessException => AccessException::immutableViolation(), ConfigCode::ImmutableViolation, '[CFG_2801] Configuration is immutable after loading completes.'];
        yield 'unsupported format' => [static fn(): LoaderException => LoaderException::unsupportedFormat('xml'), ConfigCode::UnsupportedFormat, '[CFG_2810] No loader registered for extension: "xml".'];
        yield 'file not found' => [static fn(): LoaderException => LoaderException::fileNotFound('/app/config.php'), ConfigCode::FileNotFound, '[CFG_2811] Configuration file not found: "/app/config.php".'];
        yield 'file unreadable' => [static fn(): LoaderException => LoaderException::fileUnreadable('/app/config.php'), ConfigCode::FileUnreadable, '[CFG_2812] Configuration file is not readable: "/app/config.php".'];
        yield 'path traversal' => [static fn(): LoaderException => LoaderException::pathTraversal('../../etc/passwd'), ConfigCode::PathTraversal, '[CFG_2813] Path traversal detected: "../../etc/passwd".'];
        yield 'invalid syntax' => [static fn(): LoaderException => LoaderException::invalidSyntax('bad indentation'), ConfigCode::ParseError, '[CFG_2820] bad indentation'];
        yield 'parse error' => [static fn(): LoaderException => LoaderException::parseError('/app/config.yaml', 'bad indentation'), ConfigCode::ParseError, '[CFG_2820] Failed to parse "/app/config.yaml": bad indentation.'];
        yield 'invalid return type' => [static fn(): LoaderException => LoaderException::invalidReturnType('/app/config.php', 'string'), ConfigCode::InvalidReturnType, '[CFG_2821] Configuration file "/app/config.php" must hold an array, got string.'];
        yield 'unsupported YAML feature' => [static fn(): LoaderException => LoaderException::unsupportedYamlFeature('YAML anchors (&name) are not supported', 3), ConfigCode::UnsupportedYamlFeature, '[CFG_2822] Unsupported YAML feature at line 3: YAML anchors (&name) are not supported. Use PHP format for advanced YAML features, or remove the unsupported syntax.'];
        yield 'unsupported TOML feature' => [static fn(): LoaderException => LoaderException::unsupportedTomlFeature('TOML arrays of tables ([[table]]) are not supported', 7), ConfigCode::UnsupportedTomlFeature, '[CFG_2823] Unsupported TOML feature at line 7: TOML arrays of tables ([[table]]) are not supported. Use PHP format for advanced TOML features, or remove the unsupported syntax.'];
        yield 'undefined variable' => [static fn(): ProcessorException => ProcessorException::undefinedVariable('DB_HOST'), ConfigCode::UndefinedVariable, '[CFG_2830] Undefined environment variable: "DB_HOST". Provide a default value with ${DB_HOST:default} or define the variable in your environment.'];
        yield 'type cast failed' => [static fn(): ProcessorException => ProcessorException::typeCastFailed('base64', 'invalid encoding'), ConfigCode::TypeCastFailed, "[CFG_2831] Type cast to 'base64' failed: invalid encoding."];
        yield 'invalid encryption key' => [static fn(): ProcessorException => ProcessorException::invalidEncryptionKey(), ConfigCode::InvalidEncryptionKey, '[CFG_2840] Encryption key must be 32 bytes (256 bits). Provide raw 32 bytes or 64 hex characters.'];
        yield 'decryption failed' => [static fn(): ProcessorException => ProcessorException::decryptionFailed('ciphertext too short'), ConfigCode::DecryptionFailed, '[CFG_2841] Configuration value decryption failed: ciphertext too short.'];
        yield 'encryption failed' => [static fn(): ProcessorException => ProcessorException::encryptionFailed('OpenSSL could not encrypt the value'), ConfigCode::EncryptionFailed, '[CFG_2842] Configuration value encryption failed: OpenSSL could not encrypt the value.'];
        yield 'schema violations' => [static fn(): ValidationException => ValidationException::schemaViolations(["Missing required key: 'app.name'."]), ConfigCode::SchemaViolations, "[CFG_2850] Configuration validation failed (1 violation(s)): Missing required key: 'app.name'.."];
        yield 'required missing' => [static fn(): ValidationException => ValidationException::requiredMissing('app.name'), ConfigCode::RequiredMissing, '[CFG_2851] Required configuration key missing: "app.name".'];
        yield 'type constraint' => [static fn(): ValidationException => ValidationException::typeConstraint('app.port', 'int', 'string'), ConfigCode::TypeConstraint, '[CFG_2852] Type constraint failed at "app.port": expected int, got string.'];
        yield 'merge conflict' => [static fn(): MergeException => MergeException::conflict('database.options', 'string', 'array'), ConfigCode::MergeConflict, '[CFG_2860] Merge conflict at "database.options": cannot merge string with array.'];
    }

    /**
     * @param \Closure(): ConfigurationException $build
     */
    #[DataProvider('exceptions')]
    public function testEveryFactoryCarriesItsCodeAndMessage(\Closure $build, ConfigCode $code, string $message): void
    {
        $exception = $build();

        self::assertSame($code, $exception->errorCode);
        self::assertSame($code->value, $exception->getCode());
        self::assertSame($message, $exception->getMessage());
        self::assertInstanceOf(Configuration::class, $exception);
    }

    public function testEveryCodeHasAFactory(): void
    {
        $covered = array_map(static fn(array $case): ConfigCode => $case[1], iterator_to_array(self::exceptions(), false));

        self::assertSame([], array_values(array_diff(
            array_map(static fn(ConfigCode $code): string => $code->name, ConfigCode::cases()),
            array_map(static fn(ConfigCode $code): string => $code->name, $covered),
        )));
    }

    public function testCodesComeFromTheRegisteredBlock(): void
    {
        self::assertSame('CFG', ConfigCode::prefix());
        self::assertSame(2800, ConfigCode::block()->first);
        self::assertSame(2899, ConfigCode::block()->last);
    }

    public function testParseFailuresKeepTheirReason(): void
    {
        $inner = LoaderException::invalidSyntax('bad indentation on line 4');
        $outer = LoaderException::parseError('/app/config.yaml', $inner->reason, $inner);

        self::assertSame('bad indentation on line 4', $inner->reason);
        self::assertSame('bad indentation on line 4', $outer->reason);
        self::assertSame($inner, $outer->getPrevious());
        self::assertSame('', LoaderException::fileNotFound('/app/config.php')->reason);
    }

    public function testValidationExceptionListsEveryViolation(): void
    {
        $violations = ['first', 'second', 'third', 'fourth', 'fifth', 'sixth'];
        $exception = ValidationException::schemaViolations($violations);

        self::assertSame($violations, $exception->violations);
        self::assertSame('[CFG_2850] Configuration validation failed (6 violation(s)): first; second; third; fourth; fifth.', $exception->getMessage());
        self::assertSame(['Missing required key: app.name'], ValidationException::requiredMissing('app.name')->violations);
        self::assertSame(['Type constraint: app.port expected int, got string'], ValidationException::typeConstraint('app.port', 'int', 'string')->violations);
    }

    public function testIdentifiersAreQuotedWithControlCharactersNeutralised(): void
    {
        $message = AccessException::typeMismatch("app\nport", 'int', 'array')->getMessage();

        self::assertStringNotContainsString("\n", $message);
        self::assertStringContainsString('"app\\nport"', $message);
    }
}
