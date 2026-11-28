<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Tests\Unit\MergeStrategy;

use KaririCode\Configurator\MergeStrategy\ReplaceMerge;
use PHPUnit\Framework\TestCase;

final class ReplaceMergeTest extends TestCase
{
    private ReplaceMerge $strategy;

    protected function setUp(): void
    {
        $this->strategy = new ReplaceMerge();
    }

    public function testReplacesTopLevelKeys(): void
    {
        $result = $this->strategy->merge(
            ['a' => 1, 'b' => 2],
            ['b' => 3, 'c' => 4],
        );

        self::assertSame(['a' => 1, 'b' => 3, 'c' => 4], $result);
    }

    public function testReplacesNestedArrayEntirely(): void
    {
        $result = $this->strategy->merge(
            ['db' => ['host' => 'localhost', 'port' => 5432]],
            ['db' => ['host' => 'prod.db.com']],
        );

        // port is lost — replace strategy does not descend
        self::assertSame(['host' => 'prod.db.com'], $result['db']);
    }

    public function testEmptySourcePreservesBase(): void
    {
        $result = $this->strategy->merge(['a' => 1], []);
        self::assertSame(['a' => 1], $result);
    }
}
