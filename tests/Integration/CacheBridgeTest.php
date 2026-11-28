<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Tests\Integration;

use KaririCode\Configurator\Integration\CacheBridge;
use KaririCode\Configurator\MergeStrategy\ReplaceMerge;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;

final class CacheBridgeTest extends TestCase
{
    private string $dir;
    private CacheInterface $cache;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/kariricode_cache_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0o755, true);
        file_put_contents($this->dir . '/a.php', "<?php return ['v' => 'a', 'db' => ['host' => 'a', 'port' => 1]];");
        file_put_contents($this->dir . '/b.php', "<?php return ['v' => 'b', 'db' => ['host' => 'b']];");
        $this->cache = new InMemoryCache();
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    public function testSecondLoadIsServedFromCache(): void
    {
        $bridge = new CacheBridge($this->cache);
        $files = [$this->dir . '/a.php'];

        self::assertSame('a', $bridge->load($files)->get('v'));

        unlink($this->dir . '/a.php');

        self::assertSame('a', $bridge->load($files)->get('v'));
    }

    public function testFileOrderIsPartOfTheCacheKey(): void
    {
        $bridge = new CacheBridge($this->cache);
        $a = $this->dir . '/a.php';
        $b = $this->dir . '/b.php';

        self::assertSame('b', $bridge->load([$a, $b])->get('v'));
        self::assertSame('a', $bridge->load([$b, $a])->get('v'));
    }

    public function testMergeStrategyIsPartOfTheCacheKey(): void
    {
        $bridge = new CacheBridge($this->cache);
        $files = [$this->dir . '/a.php', $this->dir . '/b.php'];

        self::assertSame(1, $bridge->load($files)->get('db.port'));
        self::assertNull($bridge->load($files, new ReplaceMerge())->get('db.port'));
    }

    public function testInvalidateForcesReload(): void
    {
        $bridge = new CacheBridge($this->cache);
        $files = [$this->dir . '/a.php'];
        $bridge->load($files);

        file_put_contents($files[0], "<?php return ['v' => 'changed'];");
        $bridge->invalidate($files);

        self::assertSame('changed', $bridge->load($files)->get('v'));
    }
}

/**
 * Minimal PSR-16 cache for tests.
 */
final class InMemoryCache implements CacheInterface
{
    /** @var array<string, mixed> */
    private array $items = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->items[$key] ?? $default;
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $this->items[$key] = $value;

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->items[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->items = [];

        return true;
    }

    /**
     * @param iterable<string> $keys
     *
     * @return iterable<string, mixed>
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];

        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }

        return $result;
    }

    /**
     * @param iterable<string, mixed> $values
     */
    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }

        return true;
    }

    /**
     * @param iterable<string> $keys
     */
    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->items);
    }
}
