<?php

declare(strict_types=1);

namespace KaririCode\Configurator\MergeStrategy;

use KaririCode\Configurator\Contract\MergeStrategy;

/**
 * Merges a configuration source into the loaded tree at the top level
 * only: a key the source defines replaces the loaded value whole, nested
 * maps included, for sources that are complete on their own.
 *
 * Preconditions: none; any two arrays merge.
 *
 * Thread-safety: stateless; one instance serves every caller.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     $merged = new ReplaceMerge()->merge(['db' => ['host' => 'a', 'port' => 5432]], ['db' => ['host' => 'b']]);
 *     // ['db' => ['host' => 'b']]
 *
 * References: ADR-001. ARFA 1.3 P2: a pure combination of two trees.
 *
 * @since 4.2.0
 */
final readonly class ReplaceMerge implements MergeStrategy
{
    #[\Override]
    public function merge(array $base, array $source): array
    {
        return array_replace($base, $source);
    }
}
