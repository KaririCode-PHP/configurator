<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Tests\Unit;

use KaririCode\Configurator\Configuration;
use KaririCode\Configurator\ConfigurationBuilder;
use KaririCode\Configurator\Exception\ConfigurationException;
use KaririCode\Configurator\MergeStrategy\ReplaceMerge;
use PHPUnit\Framework\TestCase;

final class ConfigurationBuilderTest extends TestCase
{
    private string $fixturesPath;

    protected function setUp(): void
    {
        $this->fixturesPath = dirname(__DIR__) . '/Fixture/Config';
    }

    public function testBuildsFromDirectory(): void
    {
        $config = ConfigurationBuilder::create()
            ->withDirectory($this->fixturesPath)
            ->build();

        // PHP files loaded
        self::assertSame('TestApp', $config->get('app.name'));
        // JSON files loaded
        self::assertSame('smtp', $config->get('services.mailer.transport'));
    }

    public function testBuildsFromSingleFile(): void
    {
        $config = ConfigurationBuilder::create()
            ->withFile($this->fixturesPath . '/app.php')
            ->build();

        self::assertSame('TestApp', $config->get('app.name'));
        self::assertNull($config->get('services'));
    }

    public function testAppliesEnvironmentOverlay(): void
    {
        $config = ConfigurationBuilder::create()
            ->withDirectory($this->fixturesPath)
            ->withEnvironment('production')
            ->build();

        // Production overlay changes debug to false
        self::assertFalse($config->get('app.debug'));
        // Production overlay changes database host
        self::assertSame('db.prod.example.com', $config->get('database.host'));
    }

    public function testOverlaysDoNotLoadWithoutAnEnvironment(): void
    {
        $config = ConfigurationBuilder::create()
            ->withDirectory($this->fixturesPath)
            ->build();

        self::assertTrue($config->get('app.debug'));
        self::assertSame('localhost', $config->get('database.host'));
    }

    public function testOverlaysOfAnotherEnvironmentDoNotLoad(): void
    {
        $config = ConfigurationBuilder::create()
            ->withDirectory($this->fixturesPath)
            ->withEnvironment('staging')
            ->build();

        self::assertTrue($config->get('app.debug'));
        self::assertSame('file', $config->get('cache.driver'));
    }

    public function testAGlobPatternLoadsEveryFileItMatches(): void
    {
        $config = ConfigurationBuilder::create()
            ->withDirectory($this->fixturesPath . '/app*.php')
            ->build();

        self::assertFalse($config->get('app.debug'), 'app.production.php sorts after app.php');
        self::assertNull($config->get('services'));
    }

    public function testAppliesOverrides(): void
    {
        $config = ConfigurationBuilder::create()
            ->withFile($this->fixturesPath . '/app.php')
            ->withOverrides(['app' => ['name' => 'OverriddenApp']])
            ->build();

        self::assertSame('OverriddenApp', $config->get('app.name'));
    }

    public function testBuiltConfigIsSealedByDefault(): void
    {
        $config = ConfigurationBuilder::create()
            ->withFile($this->fixturesPath . '/app.php')
            ->build();

        $this->expectException(ConfigurationException::class);
        $config->merge(['new' => 'data']);
    }

    public function testUnsealedBuildAllowsMutation(): void
    {
        $config = ConfigurationBuilder::create()
            ->withFile($this->fixturesPath . '/app.php')
            ->sealed(false)
            ->build();

        $config->merge(['extra' => 'value']);
        self::assertSame('value', $config->get('extra'));
    }

    public function testCustomMergeStrategy(): void
    {
        $config = ConfigurationBuilder::create()
            ->withMergeStrategy(new ReplaceMerge())
            ->withFile($this->fixturesPath . '/app.php')
            ->build();

        self::assertSame('TestApp', $config->get('app.name'));
    }

    public function testSchemaValidation(): void
    {
        $config = ConfigurationBuilder::create()
            ->withFile($this->fixturesPath . '/app.php')
            ->withSchema([
                'app.name' => 'string',
                'app.debug' => 'bool',
                'app.port' => 'int',
            ])
            ->build();

        self::assertSame('TestApp', $config->get('app.name'));
    }
}
