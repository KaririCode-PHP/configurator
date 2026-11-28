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
        $this->fixturesPath = dirname(__DIR__, 2) . '/Fixture/config';
    }

    public function testLoadsValidJsonConfig(): void
    {
        $data = $this->loader->load($this->fixturesPath . '/services.json');

        self::assertIsArray($data);
        self::assertSame('smtp', $data['services']['mailer']['transport']);
        self::assertSame(587, $data['services']['mailer']['port']);
    }

    public function testThrowsForNonExistentFile(): void
    {
        $this->expectException(LoaderException::class);
        $this->expectExceptionCode(1100);

        $this->loader->load('/nonexistent/file.json');
    }

    public function testThrowsForInvalidJson(): void
    {
        $this->expectException(LoaderException::class);
        $this->expectExceptionCode(1102);

        $this->loader->load(dirname(__DIR__, 2) . '/Fixture/invalid/invalid.json');
    }

    public function testSupportsJsonExtension(): void
    {
        self::assertTrue($this->loader->supports('json'));
        self::assertFalse($this->loader->supports('php'));
        self::assertFalse($this->loader->supports('yaml'));
    }
}
