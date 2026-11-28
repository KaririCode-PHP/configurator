<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Tests\Unit\Validator;

use KaririCode\Configurator\Exception\ValidationException;
use KaririCode\Configurator\Validator\SchemaValidator;
use PHPUnit\Framework\TestCase;

final class SchemaValidatorTest extends TestCase
{
    public function testPassesValidData(): void
    {
        $validator = new SchemaValidator([
            'app.name' => 'string',
            'app.debug' => 'bool',
            'app.port' => 'int',
        ]);

        // Should not throw
        $validator->validate([
            'app' => ['name' => 'TestApp', 'debug' => true, 'port' => 8080],
        ]);

        $this->addToAssertionCount(1);
    }

    public function testFailsOnMissingRequiredKey(): void
    {
        $validator = new SchemaValidator([
            'app.name' => 'string',
            'app.secret' => 'string',
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionCode(2850);

        $validator->validate([
            'app' => ['name' => 'TestApp'],
        ]);
    }

    public function testPassesOptionalMissingKey(): void
    {
        $validator = new SchemaValidator([
            'app.name' => 'string',
            'app.description' => '?string',
        ]);

        $validator->validate([
            'app' => ['name' => 'TestApp'],
        ]);

        $this->addToAssertionCount(1);
    }

    public function testFailsOnTypeMismatch(): void
    {
        $validator = new SchemaValidator([
            'app.port' => 'int',
        ]);

        $this->expectException(ValidationException::class);

        $validator->validate([
            'app' => ['port' => 'not-an-integer'],
        ]);
    }

    public function testValidatesMultipleTypes(): void
    {
        $validator = new SchemaValidator([
            'name' => 'string',
            'count' => 'int',
            'rate' => 'float',
            'enabled' => 'bool',
            'items' => 'array',
        ]);

        $validator->validate([
            'name' => 'Test',
            'count' => 42,
            'rate' => 1.5,
            'enabled' => true,
            'items' => ['a', 'b'],
        ]);

        $this->addToAssertionCount(1);
    }

    public function testCollectsMultipleViolations(): void
    {
        $validator = new SchemaValidator([
            'name' => 'string',
            'port' => 'int',
            'secret' => 'string',
        ]);

        try {
            $validator->validate([
                'name' => 123,
                'port' => 'text',
                // secret is missing
            ]);
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $violations = $e->violations;
            self::assertCount(3, $violations);
        }
    }

    public function testSupportsNumericType(): void
    {
        $validator = new SchemaValidator([
            'value' => 'numeric',
        ]);

        $validator->validate(['value' => '42.5']);
        $this->addToAssertionCount(1);
    }

    public function testSupportsScalarType(): void
    {
        $validator = new SchemaValidator([
            'a' => 'scalar',
            'b' => 'scalar',
            'c' => 'scalar',
        ]);

        $validator->validate(['a' => 'string', 'b' => 42, 'c' => true]);
        $this->addToAssertionCount(1);
    }

    public function testANullableTypeIsCheckedWithoutItsMarker(): void
    {
        try {
            new SchemaValidator(['app.port' => '?int'])->validate(['app' => ['port' => 'abc']]);
            self::fail('the type is wrong');
        } catch (ValidationException $exception) {
            self::assertSame(["Type mismatch at 'app.port': expected int, got string."], $exception->violations);
        }
    }

    public function testEveryViolationIsReportedAfterAnAbsentOptionalKey(): void
    {
        try {
            new SchemaValidator(['app.missing' => '?string', 'app.port' => 'int'])->validate(['app' => ['port' => 'abc']]);
            self::fail('the type is wrong');
        } catch (ValidationException $exception) {
            self::assertSame(["Type mismatch at 'app.port': expected int, got string."], $exception->violations);
        }
    }

    public function testAPresentNullKeySatisfiesARequiredEntry(): void
    {
        new SchemaValidator(['app.name' => 'string'])->validate(['app' => ['name' => null]]);

        $this->expectException(ValidationException::class);

        new SchemaValidator(['app.name.first' => 'string'])->validate(['app' => ['name' => 'scalar']]);
    }

    public function testTypeAliasesAndClassNames(): void
    {
        new SchemaValidator([
            'a.int' => 'integer',
            'a.float' => 'double',
            'a.bool' => 'boolean',
            'a.clock' => \DateTimeInterface::class,
        ])->validate(['a' => ['int' => 1, 'float' => 1.5, 'bool' => true, 'clock' => new \DateTimeImmutable()]]);

        try {
            new SchemaValidator(['a.clock' => \DateTimeInterface::class])->validate(['a' => ['clock' => 'yesterday']]);
            self::fail('a string is not a DateTimeInterface');
        } catch (ValidationException $exception) {
            self::assertSame(["Type mismatch at 'a.clock': expected DateTimeInterface, got string."], $exception->violations);
        }
    }

    public function testACompositeReportsEveryViolationOfEveryValidator(): void
    {
        $validator = new \KaririCode\Configurator\Validator\CompositeValidator(
            new SchemaValidator(['a' => 'string', 'b' => 'string']),
            new SchemaValidator(['c' => 'int']),
        );

        try {
            $validator->validate([]);
            self::fail('three keys are missing');
        } catch (ValidationException $exception) {
            self::assertSame(["Missing required key: 'a'.", "Missing required key: 'b'.", "Missing required key: 'c'."], $exception->violations);
        }

        $validator->validate(['a' => 'x', 'b' => 'y', 'c' => 1]);
    }
}
