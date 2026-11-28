<?php

declare(strict_types=1);

namespace KaririCode\Configurator\MergeStrategy;

use KaririCode\Configurator\Contract\MergeStrategy;

/**
 * Merges a configuration source into the loaded tree key by key,
 * descending into maps present in both; the source wins every other
 * conflict, and lists are replaced whole, so an environment overlay
 * never duplicates entries.
 *
 * It is the default strategy and suits a base file followed by
 * environment overlays (ADR-001).
 *
 * Preconditions: none; any two arrays merge.
 *
 * Thread-safety: stateless; one instance serves every caller.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     $merged = new RecursiveMerge()->merge(['db' => ['host' => 'a', 'port' => 5432]], ['db' => ['host' => 'b']]);
 *     // ['db' => ['host' => 'b', 'port' => 5432]]
 *
 * References: ADR-001. ARFA 1.3 P2: a pure combination of two trees.
 *
 * @since 4.2.0
 */
final readonly class RecursiveMerge implements MergeStrategy
{
    #[\Override]
    public function merge(array $base, array $source): array
    {
        $nested = [];

        /** @var mixed $value configuration values are untyped; only maps merge */
        foreach ($source as $key => $value) {
            if (
                is_string($key)
                && is_array($value)
                && isset($base[$key])
                && is_array($base[$key])
                && $this->isAssociative($value)
                && $this->isAssociative($base[$key])
            ) {
                $nested[$key] = $this->merge($base[$key], $value);
            }
        }

        // Integer keys and non-map values replace; maps merged above win.
        return array_replace(array_replace($base, $source), $nested);
    }

    /**
     * Whether an array is a map: an empty array is a list, so an empty
     * value replaces a map instead of merging into it.
     *
     * @param array<mixed> $array
     */
    private function isAssociative(array $array): bool
    {
        return !array_is_list($array);
    }
}
