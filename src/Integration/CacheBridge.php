<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Integration;

use KaririCode\Configurator\Configuration;
use KaririCode\Configurator\Contract\MergeStrategy;
use KaririCode\Configurator\MergeStrategy\RecursiveMerge;
use Psr\SimpleCache\CacheInterface;

/**
 * Serves a configuration from a PSR-16 cache, so a worker that boots
 * often skips file I/O and parsing: the first load() reads and merges
 * the files and stores the raw tree; later calls rebuild the
 * configuration from the cached tree.
 *
 * The key derives from the ordered file list and the merge strategy, so
 * a different order, file set or strategy gets its own entry. File
 * contents are not part of the key: edits show after the TTL expires or
 * after invalidate(). The bridge does not run the value processors, so
 * `${VAR}`, cast prefixes and `ENC(...)` stay as written and no
 * decrypted value ever reaches the cache; use ConfigurationBuilder when
 * they are needed.
 *
 * Preconditions: the files exist and are readable on a cache miss; the
 * cache stores PHP arrays.
 *
 * Thread-safety: immutable; as safe as the cache it wraps.
 *
 * Side effects: a miss reads the files and writes the cache entry;
 * invalidate() deletes it.
 *
 * Example:
 *
 *     $bridge = new CacheBridge($psr16Cache, ttl: 300);
 *     $config = $bridge->load(['/app/config/app.php', '/app/config/app.production.php']);
 *
 * References: ADR-001, ADR-003; PSR-16. ARFA 1.3 P3: cheaper boots for
 * long-running workers.
 *
 * @since 4.2.0
 */
final readonly class CacheBridge
{
    private const string CACHE_PREFIX = 'kariricode.config.';

    public function __construct(
        private CacheInterface $cache,
        private int $ttl = 3600,
    ) {}

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
        /** @var mixed $cached PSR-16 get() returns mixed; checked below. */
        $cached = $this->cache->get($cacheKey);

        if (is_array($cached)) {
            return $this->buildFromCached($cached, $merge);
        }

        $config = $this->buildFromFiles($files, $merge);
        $this->cache->set(key: $cacheKey, value: $config->all(), ttl: $this->ttl);

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

    // ── Internal ─────────────────────────────────────────────────────────

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
