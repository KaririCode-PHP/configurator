<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Tests\Unit\Loader;

use KaririCode\Configurator\Exception\LoaderException;
use KaririCode\Configurator\Loader\JsonLoader;
use PHPUnit\Framework\TestCase;

final class JsonLoaderTest extends TestCase
{
    private JsonLoader $loader;
    private string $fixturesPath;

    protected function setUp(): void
    {
        $this->loader = new JsonLoader();
        $this->fixturesPath = dirname(__DIR__, 2) . '/Fixture/Config';
    }

    public function testLoadsValidJsonConfig(): void
    {
        $data = $this->loader->load($this->fixturesPath . '/services.json');

        self::assertIsArray($data);
        self::assertSame('smtp', self::at($data, 'services', 'mailer', 'transport'));
        self::assertSame(587, self::at($data, 'services', 'mailer', 'port'));
    }

    public function testThrowsForNonExistentFile(): void
    {
        $this->expectException(LoaderException::class);
        $this->expectExceptionCode(2811);

        $this->loader->load('/nonexistent/file.json');
    }

    public function testThrowsForInvalidJson(): void
    {
        $this->expectException(LoaderException::class);
        $this->expectExceptionCode(2820);

        $this->loader->load(dirname(__DIR__, 2) . '/Fixture/Invalid/invalid.json');
    }

    public function testSupportsJsonExtension(): void
    {
        self::assertTrue($this->loader->supports('json'));
        self::assertFalse($this->loader->supports('php'));
        self::assertFalse($this->loader->supports('yaml'));
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

    public function testADirectoryIsNotAConfigurationFile(): void
    {
        $this->expectException(LoaderException::class);
        $this->expectExceptionCode(2811);

        $this->loader->load($this->fixturesPath);
    }

    public function testDocumentsDecodeUpToTheDepthLimit(): void
    {
        $path = sys_get_temp_dir() . '/kariricode-config-depth-' . bin2hex(random_bytes(4)) . '.json';

        try {
            file_put_contents($path, '{"a": ' . str_repeat('[', 510) . str_repeat(']', 510) . '}');
            self::assertArrayHasKey('a', $this->loader->load($path));

            file_put_contents($path, '{"a": ' . str_repeat('[', 511) . str_repeat(']', 511) . '}');
            $this->expectException(LoaderException::class);
            $this->loader->load($path);
        } finally {
            unlink($path);
        }
    }
}
