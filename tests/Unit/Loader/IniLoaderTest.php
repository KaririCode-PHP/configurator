<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Tests\Unit\Loader;

use KaririCode\Configurator\Exception\LoaderException;
use KaririCode\Configurator\Loader\IniLoader;
use PHPUnit\Framework\TestCase;

final class IniLoaderTest extends TestCase
{
    private IniLoader $loader;
    private string $fixtureDir;

    protected function setUp(): void
    {
        $this->loader = new IniLoader();
        $this->fixtureDir = sys_get_temp_dir() . '/kariricode_ini_' . bin2hex(random_bytes(4));
        mkdir($this->fixtureDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->fixtureDir . '/*') ?: []);
        rmdir($this->fixtureDir);
    }

    // ── Extension Support ─────────────────────────────────────────────

    public function testSupportsIniExtension(): void
    {
        self::assertTrue($this->loader->supports('ini'));
        self::assertFalse($this->loader->supports('toml'));
        self::assertFalse($this->loader->supports('json'));
    }

    // ── Sections and Typed Scanning ───────────────────────────────────

    public function testSectionsBecomeNestedArrays(): void
    {
        $result = $this->load("[database]\nhost = localhost\nport = 5432\n");

        self::assertSame('localhost', $result['database']['host']);
        self::assertSame(5432, $result['database']['port']);
    }

    public function testTypedScanningCastsScalars(): void
    {
        $result = $this->load(
            "[app]\ndebug = true\nworkers = 4\nratio = 1.5\nname = kariri\n",
        );

        self::assertTrue($result['app']['debug']);
        self::assertSame(4, $result['app']['workers']);
        self::assertSame(1.5, $result['app']['ratio']);
        self::assertSame('kariri', $result['app']['name']);
    }

    public function testBooleanKeywordsAreTyped(): void
    {
        $result = $this->load("[flags]\na = yes\nb = no\nc = on\nd = off\n");

        self::assertTrue($result['flags']['a']);
        self::assertFalse($result['flags']['b']);
        self::assertTrue($result['flags']['c']);
        self::assertFalse($result['flags']['d']);
    }

    // ── Dotted-Key Expansion ──────────────────────────────────────────

    public function testTopLevelDottedKeysExpand(): void
    {
        $result = $this->load("database.host = localhost\ndatabase.port = 5432\n");

        self::assertSame('localhost', $result['database']['host']);
        self::assertSame(5432, $result['database']['port']);
    }

    public function testDottedKeysInsideSectionExpand(): void
    {
        $result = $this->load("[cache]\nredis.host = 127.0.0.1\nredis.db = 2\n");

        self::assertSame('127.0.0.1', $result['cache']['redis']['host']);
        self::assertSame(2, $result['cache']['redis']['db']);
    }

    public function testOverlappingDottedKeysMergeNotOverwrite(): void
    {
        $result = $this->load("service.host = web1\nservice.port = 80\nservice.tls = true\n");

        self::assertSame('web1', $result['service']['host']);
        self::assertSame(80, $result['service']['port']);
        self::assertTrue($result['service']['tls']);
    }

    public function testDeeplyNestedDottedKey(): void
    {
        $result = $this->load("a.b.c.d = deep\n");

        self::assertSame('deep', $result['a']['b']['c']['d']);
    }

    // ── Errors ────────────────────────────────────────────────────────

    public function testThrowsForNonExistentFile(): void
    {
        $this->expectException(LoaderException::class);

        $this->loader->load($this->fixtureDir . '/missing.ini');
    }

    public function testThrowsForMalformedIni(): void
    {
        $this->expectException(LoaderException::class);

        // An unterminated section header is a parse error.
        $this->load("[unterminated\nkey = value\n");
    }

    // ── Utility ───────────────────────────────────────────────────────

    private function load(string $content): array
    {
        $path = $this->fixtureDir . '/config.ini';
        file_put_contents($path, $content);

        return $this->loader->load($path);
    }
}
