<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Tests\Integration;

use KaririCode\Configurator\Configuration;
use KaririCode\Configurator\Exception\AccessException;
use KaririCode\Configurator\Integration\ConfigurationProcessor;
use KaririCode\Configurator\Integration\Psr11Bridge;
use PHPUnit\Framework\TestCase;

final class ContainerAndPipelineBridgeTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/../Fixture/Config';

    public function testThePsr11FactoryBuildsASealedConfigurationWhenCalled(): void
    {
        $factory = Psr11Bridge::factory(self::FIXTURES);
        $config = $factory();

        self::assertInstanceOf(Configuration::class, $config);
        self::assertTrue($config->getBool('app.debug'));
        self::assertNotSame($config, $factory(), 'each call builds a new configuration');

        $this->expectException(AccessException::class);

        $config->merge(['late' => true]);
    }

    public function testThePsr11FactoryAppliesTheEnvironmentOverlay(): void
    {
        $config = Psr11Bridge::factory(self::FIXTURES, 'production')();

        self::assertFalse($config->getBool('app.debug'));
        self::assertSame('db.prod.example.com', $config->getString('database.host'));
        self::assertSame('TestApp', $config->getString('app.name'));
    }

    public function testThePsr11FactoryReadsTheDirectoryWhenCalled(): void
    {
        $directory = sys_get_temp_dir() . '/kariricode-config-lazy-' . bin2hex(random_bytes(4));
        $factory = Psr11Bridge::factory($directory);
        mkdir($directory);
        file_put_contents($directory . '/app.json', '{"app": {"created": "after the factory"}}');

        try {
            self::assertSame('after the factory', $factory()->getString('app.created'));
        } finally {
            unlink($directory . '/app.json');
            rmdir($directory);
        }
    }

    public function testTheProcessorReturnsASealedConfigurationAndIgnoresItsInput(): void
    {
        $config = new ConfigurationProcessor(self::FIXTURES)->process(['ignored' => true]);

        self::assertInstanceOf(Configuration::class, $config);
        self::assertTrue($config->getBool('app.debug'));
        self::assertFalse($config->has('ignored'));

        $this->expectException(AccessException::class);

        $config->merge(['late' => true]);
    }

    public function testTheProcessorAppliesTheEnvironmentAndTheExtraFilesInOrder(): void
    {
        $extra = sys_get_temp_dir() . '/kariricode-config-extra-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($extra, '{"cache": {"driver": "apcu"}, "extra": {"loaded": true}}');

        try {
            $config = new ConfigurationProcessor(self::FIXTURES, environment: 'production', additionalFiles: [$extra])->process(null);

            self::assertInstanceOf(Configuration::class, $config);
            self::assertFalse($config->getBool('app.debug'));
            self::assertSame('apcu', $config->getString('cache.driver'));
            self::assertSame(86400, $config->getInt('cache.ttl'));
            self::assertTrue($config->getBool('extra.loaded'));
        } finally {
            unlink($extra);
        }
    }
}
