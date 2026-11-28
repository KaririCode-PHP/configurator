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

        $this->assertSame($data, $chain->processArray($data));
    }

    public function testIsEmptyReflectsState(): void
    {
        $chain = new ValueProcessorChain();
        $this->assertTrue($chain->isEmpty());

        $chain->add(new TypeCastProcessor());
        $this->assertFalse($chain->isEmpty());
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

        $this->assertSame('prod-db', $result['database']['host']);
        $this->assertSame(5432, $result['database']['port']);
        $this->assertSame('prod-db', $result['database']['options']['host_override']);
    }

    public function testChainedProcessors(): void
    {
        // Env resolves ${VAR} to "int:8080", then TypeCast converts to int
        $resolver = new InMemoryEnvironmentResolver(['PORT' => 'int:8080']);

        $chain = new ValueProcessorChain();
        $chain->add(new EnvironmentProcessor($resolver));
        $chain->add(new TypeCastProcessor());

        $result = $chain->processValue('${PORT}');

        $this->assertSame(8080, $result);
    }

    public function testProcessValueSkipsUnsupportedProcessors(): void
    {
        $chain = new ValueProcessorChain();
        $chain->add(new TypeCastProcessor());

        // No ${} pattern, no type: prefix → passes through unchanged
        $this->assertSame('plain-value', $chain->processValue('plain-value'));
        $this->assertSame(42, $chain->processValue(42));
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

        $this->assertSame(['a', 'b', 'c'], $result['items']);
        $this->assertSame(['key' => 'value'], $result['nested']);
        $this->assertSame(42, $result['number']);
        $this->assertSame(99, $result['cast_me']);
    }
}
