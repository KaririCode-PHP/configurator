<?php

declare(strict_types=1);

namespace KaririCode\Configurator\MergeStrategy;

use KaririCode\Configurator\Contract\MergeStrategy;

/**
 * Flat replacement merge — source completely overwrites base
 * at the top level. Nested structures are NOT recursively merged.
 *
 * Useful when each configuration source is self-contained and
 * partial overrides are not desired.
 *
 * @package KaririCode\Configurator
 * @since   4.0.0 ARFA 1.3
 */
final readonly class ReplaceMerge implements MergeStrategy
{
    #[\Override]
    public function merge(array $base, array $source): array
    {
        return array_replace($base, $source);
    }
}
