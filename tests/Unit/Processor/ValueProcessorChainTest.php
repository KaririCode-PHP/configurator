<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Tests\Unit\Processor;

use KaririCode\Configurator\Environment\InMemoryEnvironmentResolver;
use KaririCode\Configurator\Processor\EnvironmentProcessor;
use KaririCode\Configurator\Processor\TypeCastProcessor;
use KaririCode\Configurator\Processor\ValueProcessorChain;
use PHPUnit\Framework\TestCase;

final class ValueProcessorChainTest extends TestCase
{
    public function testEmptyChainReturnsDataUnchanged(): void
    {
        $chain = new ValueProcessorChain();
        $data = ['key' => 'value', 'nested' => ['a' => 1]];

        self::assertSame($data, $chain->processArray($data));
    }

    public function testIsEmptyReflectsState(): void
    {
        $chain = new ValueProcessorChain();
        self::assertTrue($chain->isEmpty());

        $chain->add(new TypeCastProcessor());
        self::assertFalse($chain->isEmpty());
    }

    public function testProcessesNestedArrayRecursively(): void
    {
        $resolver = new InMemoryEnvironmentResolver(['DB_HOST' => 'prod-db']);
        $chain = new ValueProcessorChain();
        $chain->add(new EnvironmentProcessor($resolver));

        $data = [
            'database' => [
                'host' => '${DB_HOST}',
                'port' => 5432,
                'options' => [
                    'host_override' => '${DB_HOST}',
                ],
            ],
        ];

        $result = $chain->processArray($data);

        self::assertSame('prod-db', self::at($result, 'database', 'host'));
        self::assertSame(5432, self::at($result, 'database', 'port'));
        self::assertSame('prod-db', self::at($result, 'database', 'options', 'host_override'));
    }

    public function testChainedProcessors(): void
    {
        // Env resolves ${VAR} to "int:8080", then TypeCast converts to int
        $resolver = new InMemoryEnvironmentResolver(['PORT' => 'int:8080']);

        $chain = new ValueProcessorChain();
        $chain->add(new EnvironmentProcessor($resolver));
        $chain->add(new TypeCastProcessor());

        $result = $chain->processValue('${PORT}');

        self::assertSame(8080, $result);
    }

    public function testProcessValueSkipsUnsupportedProcessors(): void
    {
        $chain = new ValueProcessorChain();
        $chain->add(new TypeCastProcessor());

        // No ${} pattern, no type: prefix → passes through unchanged
        self::assertSame('plain-value', $chain->processValue('plain-value'));
        self::assertSame(42, $chain->processValue(42));
    }

    public function testPreservesNonScalarValues(): void
    {
        $chain = new ValueProcessorChain();
        $chain->add(new TypeCastProcessor());

        $data = [
            'items' => ['a', 'b', 'c'],
            'nested' => ['key' => 'value'],
            'number' => 42,
            'cast_me' => 'int:99',
        ];

        $result = $chain->processArray($data);

        self::assertSame(['a', 'b', 'c'], $result['items']);
        self::assertSame(['key' => 'value'], $result['nested']);
        self::assertSame(42, $result['number']);
        self::assertSame(99, $result['cast_me']);
    }

    /** A nested value of a loaded array, read without assuming its shape. */
    private static function at(mixed $data, string|int ...$path): mixed
    {
        foreach ($path as $key) {
            self::assertIsArray($data);
            $data = $data[$key] ?? null;
        }

        return $data;
    }
}
