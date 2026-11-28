<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Tests\Unit\Loader;

use KaririCode\Configurator\Exception\LoaderException;
use KaririCode\Configurator\Loader\YamlLoader;
use PHPUnit\Framework\TestCase;

final class YamlLoaderTest extends TestCase
{
    private YamlLoader $loader;
    private string $fixturesPath;

    protected function setUp(): void
    {
        $this->loader = new YamlLoader();
        $this->fixturesPath = dirname(__DIR__, 2) . '/Fixture/config';
    }

    // ── Basic Loading ─────────────────────────────────────────────────

    public function testLoadsValidYamlConfig(): void
    {
        $data = $this->loader->load($this->fixturesPath . '/logging.yaml');

        self::assertIsArray($data);
        self::assertSame('info', $data['logging']['level']);
        self::assertSame('json', $data['logging']['format']);
    }

    public function testThrowsForNonExistentFile(): void
    {
        $this->expectException(LoaderException::class);
        $this->expectExceptionCode(1100);

        $this->loader->load('/nonexistent/file.yaml');
    }

    public function testSupportsYamlExtensions(): void
    {
        self::assertTrue($this->loader->supports('yaml'));
        self::assertTrue($this->loader->supports('yml'));
        self::assertFalse($this->loader->supports('json'));
        self::assertFalse($this->loader->supports('php'));
    }

    // ── Nested Structures ─────────────────────────────────────────────

    public function testParsesNestedMappings(): void
    {
        $data = $this->loader->load($this->fixturesPath . '/logging.yaml');

        self::assertSame('/var/log/app.log', $data['logging']['channels']['file']['path']);
        self::assertSame(14, $data['logging']['channels']['file']['max_files']);
        self::assertTrue($data['logging']['channels']['stdout']['enabled']);
    }

    // ── Sequences ─────────────────────────────────────────────────────

    public function testParsesBlockSequences(): void
    {
        $data = $this->loader->load($this->fixturesPath . '/logging.yaml');

        self::assertSame(
            ['https://example.com', 'https://api.example.com'],
            $data['security']['cors']['allowed_origins'],
        );
    }

    // ── Type Casting ──────────────────────────────────────────────────

    public function testCastsNullValues(): void
    {
        $data = $this->loader->load($this->fixturesPath . '/logging.yaml');

        self::assertNull($data['types']['null_value']);
    }

    public function testCastsBooleanValues(): void
    {
        $data = $this->loader->load($this->fixturesPath . '/logging.yaml');

        self::assertTrue($data['types']['bool_true']);
        self::assertFalse($data['types']['bool_false']);
    }

    public function testCastsIntegerValues(): void
    {
        $data = $this->loader->load($this->fixturesPath . '/logging.yaml');

        self::assertSame(42, $data['types']['integer']);
        self::assertSame(-17, $data['types']['negative']);
    }

    public function testCastsFloatValues(): void
    {
        $data = $this->loader->load($this->fixturesPath . '/logging.yaml');

        self::assertSame(3.14, $data['types']['float_val']);
    }

    public function testCastsHexValues(): void
    {
        $data = $this->loader->load($this->fixturesPath . '/logging.yaml');

        self::assertSame(255, $data['types']['hex']);
    }

    public function testCastsInfinity(): void
    {
        $data = $this->loader->load($this->fixturesPath . '/logging.yaml');

        self::assertSame(INF, $data['types']['infinity']);
    }

    public function testPreservesQuotedStrings(): void
    {
        $data = $this->loader->load($this->fixturesPath . '/logging.yaml');

        self::assertSame('not a bool: true', $data['types']['quoted']);
    }

    // ── Block Scalars ─────────────────────────────────────────────────

    public function testParsesLiteralBlockScalar(): void
    {
        $data = $this->loader->load($this->fixturesPath . '/logging.yaml');

        self::assertStringContainsString("This is a multi-line\n", $data['description']);
        self::assertStringContainsString("preserved with newlines", $data['description']);
    }

    public function testParsesFoldedBlockScalar(): void
    {
        $data = $this->loader->load($this->fixturesPath . '/logging.yaml');

        // Lines are folded into spaces; clip chomping keeps one final break.
        self::assertStringContainsString('This text will be', $data['summary']);
        self::assertStringNotContainsString("\n", rtrim($data['summary'], "\n"));
        self::assertStringEndsWith("\n", $data['summary']);
    }

    // ── Flow Collections ──────────────────────────────────────────────

    public function testParsesFlowSequence(): void
    {
        $data = $this->loader->load($this->fixturesPath . '/logging.yaml');

        self::assertSame(['alpha', 'beta', 'gamma'], $data['flow_seq']);
    }

    public function testParsesFlowMapping(): void
    {
        $data = $this->loader->load($this->fixturesPath . '/logging.yaml');

        self::assertSame('test', $data['flow_map']['name']);
        self::assertSame(1, $data['flow_map']['version']);
    }

    // ── Edge Cases ────────────────────────────────────────────────────

    public function testEmptyFileReturnsEmptyArray(): void
    {
        $yamlFile = sys_get_temp_dir() . '/yaml_' . bin2hex(random_bytes(4)) . '.yaml';
        file_put_contents($yamlFile, '');

        try {
            $data = $this->loader->load($yamlFile);
            self::assertSame([], $data);
        } finally {
            unlink($yamlFile);
        }
    }

    public function testCommentsAreIgnored(): void
    {
        $tempFile = sys_get_temp_dir() . '/yaml_' . bin2hex(random_bytes(4)) . '.yaml';
        file_put_contents($tempFile, "# Full line comment\nkey: value # inline comment\n");

        try {
            $data = $this->loader->load($tempFile);
            self::assertSame('value', $data['key']);
        } finally {
            unlink($tempFile);
        }
    }

    public function testDocumentMarkersAreIgnored(): void
    {
        $tempFile = sys_get_temp_dir() . '/yaml_' . bin2hex(random_bytes(4)) . '.yaml';
        file_put_contents($tempFile, "---\nkey: value\n...\n");

        try {
            $data = $this->loader->load($tempFile);
            self::assertSame('value', $data['key']);
        } finally {
            unlink($tempFile);
        }
    }
}
