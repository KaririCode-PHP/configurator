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
        return array_replace($base, $source, $nested);
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
