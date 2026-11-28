<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Integration;

use KaririCode\Configurator\Configuration;
use KaririCode\Configurator\Contract\MergeStrategy;
use KaririCode\Configurator\MergeStrategy\RecursiveMerge;
use Psr\SimpleCache\CacheInterface;

/**
 * Wraps a Configuration with PSR-16 caching.
 *
 * On first access, loads and merges the raw file data and stores
 * it in the cache. On subsequent accesses, restores the data from
 * cache bypassing file I/O and YAML parsing entirely.
 *
 * The cache key is derived from the ordered file list and the merge
 * strategy: later files override earlier ones and the strategy shapes
 * the result, so a different order, a different file set, or a
 * different strategy each get their own entry. File contents are not
 * part of the key — edits are picked up when the TTL expires or after
 * invalidate().
 *
 * Scope: this bridge does NOT run the value-processor chain, so
 * ${ENV} interpolation, type-cast prefixes, and ENC() decryption
 * are not applied here. Use ConfigurationBuilder when those are
 * needed; this bridge is for pre-resolved, cache-friendly data.
 *
 * Requires: kariricode/cache (PSR-16 CacheInterface)
 *
 * @package KaririCode\Configurator
 * @since   4.0.0 ARFA 1.3
 */
final readonly class CacheBridge
{
    private const string CACHE_PREFIX = 'kariricode.config.';

    public function __construct(
        private CacheInterface $cache,
        private int $ttl = 3600,
    ) {
    }

    /**
     * Loads configuration with cache-through semantics.
     *
     * @param list<string> $files     Configuration files to load
     * @param MergeStrategy|null $merge  Optional merge strategy override
     *
     * @return Configuration Sealed, immutable configuration
     */
    public function load(
        array $files,
        ?MergeStrategy $merge = null,
    ): Configuration {
        $cacheKey = $this->buildCacheKey($files, $merge);
        $cached = $this->cache->get($cacheKey);

        if (is_array($cached)) {
            return $this->buildFromCached($cached, $merge);
        }

        $config = $this->buildFromFiles($files, $merge);
        $this->cache->set($cacheKey, $config->all(), $this->ttl);

        return $config;
    }

    /**
     * Removes the cached entry for exactly this file list and strategy.
     *
     * @param list<string> $files
     */
    public function invalidate(array $files, ?MergeStrategy $merge = null): void
    {
        $this->cache->delete($this->buildCacheKey($files, $merge));
    }

    // ── Internal ──────────────────────────────────────────────────────

    /**
     * @param list<string> $files
     */
    private function buildFromFiles(array $files, ?MergeStrategy $merge): Configuration
    {
        $config = new Configuration($merge ?? new RecursiveMerge());

        foreach ($files as $file) {
            $config->load($file);
        }

        $config->seal();

        return $config;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function buildFromCached(array $data, ?MergeStrategy $merge): Configuration
    {
        $config = new Configuration($merge ?? new RecursiveMerge());
        $config->merge($data);
        $config->seal();

        return $config;
    }

    /**
     * NUL cannot occur in a path, so joining on it is unambiguous; the
     * digest keeps the key within PSR-16's reserved-character rules.
     *
     * @param list<string> $files
     */
    private function buildCacheKey(array $files, ?MergeStrategy $merge): string
    {
        $strategy = $merge === null ? RecursiveMerge::class : $merge::class;

        return self::CACHE_PREFIX . hash('xxh128', $strategy . "\0" . implode("\0", $files));
    }
}
