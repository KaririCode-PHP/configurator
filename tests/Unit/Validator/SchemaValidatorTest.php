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
        $this->expectExceptionCode(1200);

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
            $violations = $e->getViolations();
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
}
