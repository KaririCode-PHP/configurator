<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Tests\Conformance;

use KaririCode\Configurator\Exception\ConfigCode;
use KaririCode\Exception\ErrorCodeTable;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ConfigCode against the ecosystem registry of record, CODE-BLOCKS.md of
 * kariricode/exception, read from the installed package (ADR-011).
 */
#[CoversNothing]
final class CodeBlockRegistryTest extends TestCase
{
    private const string REGISTRY = __DIR__ . '/../../vendor/kariricode/exception/CODE-BLOCKS.md';

    private const int FIRST = 2800;

    private const int LAST = 2899;

    #[Test]
    public function configCodeIsAValidCodeTable(): void
    {
        $table = new ErrorCodeTable(ConfigCode::class);

        self::assertSame('CFG', $table->prefix);
        self::assertSame([self::FIRST, self::LAST], [$table->block->first, $table->block->last]);
    }

    /**
     * The installed registry may predate the claim (QD-V433 open items):
     * the block is then free, and once listed it belongs to this package.
     */
    #[Test]
    public function theRegistryLeavesTheBlockToThisPackage(): void
    {
        $registry = file(self::REGISTRY, FILE_IGNORE_NEW_LINES);
        self::assertIsArray($registry);
        $rows = 0;

        foreach ($registry as $line) {
            if (preg_match('/\A\| (\d{4})–(\d{4}) \| ([A-Z0-9—]+) \| ([^|]+?) \| (released|claimed|proposed|reserved) \|/u', $line, $row) !== 1) {
                continue;
            }

            ++$rows;
            $overlaps = (int) $row[1] <= self::LAST && (int) $row[2] >= self::FIRST;
            $ours = $row[4] === 'kariricode/configurator';

            if ($ours) {
                self::assertSame([(string) self::FIRST, (string) self::LAST, 'CFG'], [$row[1], $row[2], $row[3]], $line);
            }

            self::assertFalse($overlaps && !$ours, 'block taken by ' . $row[4]);
            self::assertFalse(!$ours && $row[3] === 'CFG', 'prefix CFG taken by ' . $row[4]);
        }

        self::assertGreaterThan(30, $rows, 'the registry table was read');
    }

    #[Test]
    public function codesFollowTheDocumentedLayout(): void
    {
        $ranges = [
            'access' => [2800, 2809],
            'formats and files' => [2810, 2819],
            'parsing' => [2820, 2829],
            'interpolation and casts' => [2830, 2839],
            'encryption' => [2840, 2849],
            'schema validation' => [2850, 2859],
            'merging' => [2860, 2869],
        ];

        foreach (ConfigCode::cases() as $case) {
            $inRange = array_filter($ranges, static fn(array $range): bool => $case->value >= $range[0] && $case->value <= $range[1]);
            self::assertCount(1, $inRange, $case->name . ' sits in a documented range');
        }
    }
}
