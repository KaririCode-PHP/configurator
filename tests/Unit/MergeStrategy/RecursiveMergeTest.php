<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Tests\Unit\MergeStrategy;

use KaririCode\Configurator\MergeStrategy\RecursiveMerge;
use PHPUnit\Framework\TestCase;

final class RecursiveMergeTest extends TestCase
{
    private RecursiveMerge $strategy;

    protected function setUp(): void
    {
        $this->strategy = new RecursiveMerge();
    }

    public function testMergesTopLevelKeys(): void
    {
        $result = $this->strategy->merge(
            ['a' => 1, 'b' => 2],
            ['b' => 3, 'c' => 4],
        );

        self::assertSame(['a' => 1, 'b' => 3, 'c' => 4], $result);
    }

    public function testDeepMergesNestedArrays(): void
    {
        $result = $this->strategy->merge(
            ['db' => ['host' => 'localhost', 'port' => 5432]],
            ['db' => ['host' => 'prod.db.com']],
        );

        self::assertSame('prod.db.com', self::at($result, 'db', 'host'));
        self::assertSame(5432, self::at($result, 'db', 'port'));
    }

    public function testReplacesSequentialArrays(): void
    {
        $result = $this->strategy->merge(
            ['middleware' => ['auth', 'cors']],
            ['middleware' => ['auth', 'cors', 'rate-limiter']],
        );

        self::assertSame(['auth', 'cors', 'rate-limiter'], $result['middleware']);
    }

    public function testScalarOverwritesArray(): void
    {
        $result = $this->strategy->merge(
            ['key' => ['nested' => 'value']],
            ['key' => 'scalar'],
        );

        self::assertSame('scalar', $result['key']);
    }

    public function testArrayOverwritesScalar(): void
    {
        $result = $this->strategy->merge(
            ['key' => 'scalar'],
            ['key' => ['nested' => 'value']],
        );

        self::assertSame(['nested' => 'value'], $result['key']);
    }

    public function testAnEmptyArrayReplacesANestedMap(): void
    {
        $result = $this->strategy->merge(
            ['cache' => ['driver' => 'redis', 'ttl' => 60]],
            ['cache' => []],
        );

        self::assertSame(['cache' => []], $result);
    }

    public function testEmptyBaseReturnSource(): void
    {
        $result = $this->strategy->merge([], ['key' => 'value']);
        self::assertSame(['key' => 'value'], $result);
    }

    public function testEmptySourceReturnBase(): void
    {
        $result = $this->strategy->merge(['key' => 'value'], []);
        self::assertSame(['key' => 'value'], $result);
    }

    public function testThreeLevelsDeep(): void
    {
        $result = $this->strategy->merge(
            ['a' => ['b' => ['c' => 1, 'd' => 2]]],
            ['a' => ['b' => ['c' => 99]]],
        );

        self::assertSame(99, self::at($result, 'a', 'b', 'c'));
        self::assertSame(2, self::at($result, 'a', 'b', 'd'));
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
