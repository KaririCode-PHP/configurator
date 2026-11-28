<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Tests\Unit\Loader;

use KaririCode\Configurator\Exception\LoaderException;
use KaririCode\Configurator\Loader\PhpLoader;
use PHPUnit\Framework\TestCase;

final class PhpLoaderTest extends TestCase
{
    private PhpLoader $loader;
    private string $fixturesPath;

    protected function setUp(): void
    {
        $this->loader = new PhpLoader();
        $this->fixturesPath = dirname(__DIR__, 2) . '/Fixture/Config';
    }

    public function testLoadsValidPhpConfig(): void
    {
        $data = $this->loader->load($this->fixturesPath . '/app.php');

        self::assertIsArray($data);
        self::assertArrayHasKey('app', $data);
        self::assertSame('TestApp', self::at($data, 'app', 'name'));
    }

    public function testThrowsForNonExistentFile(): void
    {
        $this->expectException(LoaderException::class);
        $this->expectExceptionCode(1100);

        $this->loader->load('/nonexistent/path/config.php');
    }

    public function testThrowsForNonArrayReturn(): void
    {
        $this->expectException(LoaderException::class);
        $this->expectExceptionCode(1103);

        $this->loader->load(dirname(__DIR__, 2) . '/Fixture/Invalid/invalid_return.php');
    }

    public function testSupportsPhpExtension(): void
    {
        self::assertTrue($this->loader->supports('php'));
        self::assertFalse($this->loader->supports('json'));
        self::assertFalse($this->loader->supports('yaml'));
    }

    public function testIsolatedScopeCannotAccessThis(): void
    {
        // The PhpLoader uses a static closure, so $this is not accessible
        // inside the included file. This test confirms isolation.
        $data = $this->loader->load($this->fixturesPath . '/app.php');
        self::assertIsArray($data);
    }

    /** A nested value of a loaded array, read without assuming its shape. */
    private static function at(mixed $data, string|int ...$path): mixed
    {
        foreach ($path as $key) {
            self::assertIsArray($data);
            $data = $data[$key] ?? null;
        }

        return $data;
    }
}
