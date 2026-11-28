<?php

declare(strict_types=1);

namespace KaririCode\Configurator\MergeStrategy;

use KaririCode\Configurator\Contract\MergeStrategy;

/**
 * Deep-merges two configuration arrays recursively.
 *
 * When both base and source contain arrays at the same key,
 * the merge descends into the nested arrays. Scalar values
 * in the source always overwrite the base. Sequential arrays
 * (integer-keyed) are replaced entirely rather than appended
 * to prevent duplication across environment overlays.
 *
 * This is the default strategy and suits the common pattern of
 * loading a base config then overlaying environment-specific values.
 *
 * @package KaririCode\Configurator
 * @since   4.0.0 ARFA 1.3
 */
final readonly class RecursiveMerge implements MergeStrategy
{
    public function merge(array $base, array $source): array
    {
        $result = $base;

        foreach ($source as $key => $value) {
            if (is_int($key)) {
                // Sequential array — replace, don't merge
                $result[$key] = $value;

                continue;
            }

            if (
                is_array($value)
                && isset($result[$key])
                && is_array($result[$key])
                && $this->isAssociative($value)
                && $this->isAssociative($result[$key])
            ) {
                $result[$key] = $this->merge($result[$key], $value);

                continue;
            }

            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * Checks if an array has string keys (associative).
     *
     * @param array<mixed> $array
     */
    private function isAssociative(array $array): bool
    {
        if ($array === []) {
            return false;
        }

        return !array_is_list($array);
    }
}
