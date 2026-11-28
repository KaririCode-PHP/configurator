<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Contract;

use KaririCode\Configurator\Exception\MergeException;

/**
 * Strategy for merging two configuration arrays.
 *
 * Defines how overlapping keys are resolved when multiple
 * configuration sources are loaded sequentially. Two built-in
 * strategies: recursive deep-merge and flat replace (ADR-001).
 *
 * @package KaririCode\Configurator
 * @since   4.0.0 ARFA 1.3
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
