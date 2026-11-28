<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Contract;

use KaririCode\Configurator\Exception\MergeException;

/**
 * Decides how a later configuration source combines with what is already
 * loaded when keys overlap; RecursiveMerge (the default) and
 * ReplaceMerge are built in (ADR-001).
 *
 * Preconditions: merge() returns a new array and leaves both arguments
 * unchanged; it may reject a combination with MergeException.
 *
 * Thread-safety: implementations are stateless.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     $config = ConfigurationBuilder::create()->withMergeStrategy(new ReplaceMerge())->build();
 *
 * References: ADR-001, ADR-011. ARFA 1.3 P2: a pure combination of two
 * trees.
 *
 * @since 4.2.0
 */
interface MergeStrategy
{
    /**
     * Merges source array into the base array.
     *
     * @param array<array-key, mixed> $base   Existing configuration
     * @param array<array-key, mixed> $source New values to merge
     *
     * @return array<array-key, mixed> Merged result
     *
     * @throws MergeException When the strategy rejects the merge (CFG_2860);
     *                        the built-in strategies never do
     */
    public function merge(array $base, array $source): array;
}
