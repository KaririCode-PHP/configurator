<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Tests\Integration;

use KaririCode\Configurator\Configuration;
use KaririCode\Configurator\ConfigurationBuilder;
use KaririCode\Configurator\Contract\Loader;
use KaririCode\Configurator\Exception\ConfigurationException;
use KaririCode\Configurator\Validator\SchemaValidator;
use PHPUnit\Framework\TestCase;

final class ConfigurationPipelineTest extends TestCase
{
    private string $fixturesPath;

    protected function setUp(): void
    {
        $this->fixturesPath = dirname(__DIR__) . '/Fixture/Config';
    }

    // ── Multi-Format Loading ──────────────────────────────────────────

    public function testLoadsPhpJsonYamlAndMerges(): void
    {
        $config = new Configuration();
        $config->load($this->fixturesPath . '/app.php');
        $config->load($this->fixturesPath . '/services.json');
        $config->load($this->fixturesPath . '/logging.yaml');
        $config->seal();

        // From PHP
        self::assertSame('TestApp', $config->getString('app.name'));
        self::assertTrue($config->getBool('app.debug'));
        self::assertSame(8080, $config->getInt('app.port'));

        // From JSON
        self::assertSame('smtp', $config->getString('services.mailer.transport'));
        self::assertSame(587, $config->getInt('services.mailer.port'));

        // From YAML
        self::assertSame('info', $config->getString('logging.level'));
        self::assertTrue($config->getBool('security.rate_limit.enabled'));
        self::assertSame(60, $config->getInt('security.rate_limit.requests_per_minute'));
    }

    // ── Environment Cascade ───────────────────────────────────────────

    public function testEnvironmentCascadeOverrides(): void
    {
        $config = ConfigurationBuilder::create()
            ->withDirectory($this->fixturesPath)
            ->withEnvironment('production')
            ->build();

        // Base values from app.php
        self::assertSame('TestApp', $config->getString('app.name'));
        self::assertSame(8080, $config->getInt('app.port'));

        // Overridden by app.production.php
        self::assertFalse($config->getBool('app.debug'));
        self::assertSame('db.prod.example.com', $config->getString('database.host'));
        self::assertSame('redis', $config->getString('cache.driver'));
    }

    // ── Schema Validation Pipeline ────────────────────────────────────

    public function testSchemaValidationAtSealTime(): void
    {
        $validator = new SchemaValidator([
            'app.name' => 'string',
            'app.debug' => 'bool',
            'database.host' => 'string',
            'database.port' => 'int',
        ]);

        $config = new Configuration(validator: $validator);
        $config->load($this->fixturesPath . '/app.php');
        $config->seal();

        self::assertSame('TestApp', $config->getString('app.name'));
    }

    // ── Immutability After Seal ───────────────────────────────────────

    public function testSealedConfigIsReadOnly(): void
    {
        $config = ConfigurationBuilder::create()
            ->withFile($this->fixturesPath . '/app.php')
            ->build();

        // Reads work
        self::assertSame('TestApp', $config->getString('app.name'));

        // Writes throw
        $this->expectException(ConfigurationException::class);
        $config->merge(['new' => 'data']);
    }

    // ── Custom Loader Registration ────────────────────────────────────

    public function testCustomLoaderForNewFormat(): void
    {
        $iniLoader = new class implements Loader {
            public function load(string $path): array
            {
                $data = parse_ini_file($path, true);

                return $data !== false ? $data : [];
            }

            public function supports(string $extension): bool
            {
                return $extension === 'ini';
            }
        };

        // Create a temp .ini file
        $tempFile = sys_get_temp_dir() . '/ini_' . bin2hex(random_bytes(4)) . '.ini';
        file_put_contents($tempFile, "[section]\nkey=value\ncount=42\n");

        try {
            $config = new Configuration();
            $config->registerLoader($iniLoader, 'ini');
            $config->load($tempFile);

            self::assertSame('value', $config->get('section.key'));
            self::assertSame('42', $config->get('section.count'));
        } finally {
            unlink($tempFile);
        }
    }

    // ── Programmatic Overrides ────────────────────────────────────────

    public function testProgrammaticOverridesApplyLast(): void
    {
        $config = ConfigurationBuilder::create()
            ->withFile($this->fixturesPath . '/app.php')
            ->withOverrides([
                'app' => ['name' => 'CLI-Override', 'extra' => 'cli-only'],
            ])
            ->build();

        self::assertSame('CLI-Override', $config->getString('app.name'));
        self::assertSame('cli-only', $config->getString('app.extra'));
        // Non-overridden preserved
        self::assertTrue($config->getBool('app.debug'));
    }

    // ── Deep Nesting ──────────────────────────────────────────────────

    public function testDeepDotNotationAccess(): void
    {
        $config = new Configuration();
        $config->merge([
            'level1' => [
                'level2' => [
                    'level3' => [
                        'level4' => [
                            'value' => 'deep',
                        ],
                    ],
                ],
            ],
        ]);

        self::assertSame('deep', $config->get('level1.level2.level3.level4.value'));
        self::assertTrue($config->has('level1.level2.level3.level4.value'));
        self::assertFalse($config->has('level1.level2.level3.level4.missing'));
    }

    // ── Property Hooks After Full Pipeline ────────────────────────────

    public function testPropertyHooksAfterMultiFilePipeline(): void
    {
        $config = new Configuration();
        $config->load($this->fixturesPath . '/app.php');
        $config->load($this->fixturesPath . '/services.json');

        self::assertFalse($config->isEmpty);
        self::assertGreaterThan(0, $config->count);
        self::assertContains('app', $config->keys);
        self::assertContains('services', $config->keys);
    }
}
