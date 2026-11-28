<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Tests\Integration;

use KaririCode\Configurator\ConfigurationBuilder;
use KaririCode\Contract\Configuration\ConfigProvider;
use KaririCode\Configurator\Environment\InMemoryEnvironmentResolver;
use PHPUnit\Framework\TestCase;

final class ConfigProviderTest extends TestCase
{
    public function testProviderDefaultsMergedBeforeUserConfig(): void
    {
        $provider = new class implements ConfigProvider {
            public function namespace(): string
            {
                return 'cache';
            }

            public function defaults(): array
            {
                return ['driver' => 'file', 'ttl' => 3600, 'prefix' => 'kc_'];
            }

            public function schema(): array
            {
                return ['driver' => 'string', 'ttl' => 'int'];
            }
        };

        $config = ConfigurationBuilder::create()
            ->withProvider($provider)
            ->withOverrides(['cache' => ['driver' => 'redis']])
            ->build();

        // User override takes precedence
        self::assertSame('redis', $config->getString('cache.driver'));
        // Provider default preserved where user didn't override
        self::assertSame(3600, $config->getInt('cache.ttl'));
        self::assertSame('kc_', $config->getString('cache.prefix'));
    }

    public function testMultipleProvidersComposed(): void
    {
        $cacheProvider = new class implements ConfigProvider {
            public function namespace(): string
            {
                return 'cache';
            }

            public function defaults(): array
            {
                return ['driver' => 'file', 'ttl' => 3600];
            }

            public function schema(): array
            {
                return ['driver' => 'string'];
            }
        };

        $loggingProvider = new class implements ConfigProvider {
            public function namespace(): string
            {
                return 'logging';
            }

            public function defaults(): array
            {
                return ['level' => 'info', 'channel' => 'stderr'];
            }

            public function schema(): array
            {
                return ['level' => 'string'];
            }
        };

        $config = ConfigurationBuilder::create()
            ->withProvider($cacheProvider)
            ->withProvider($loggingProvider)
            ->build();

        self::assertSame('file', $config->getString('cache.driver'));
        self::assertSame('info', $config->getString('logging.level'));
        self::assertSame('stderr', $config->getString('logging.channel'));
    }

    public function testProviderSchemaValidation(): void
    {
        $provider = new class implements ConfigProvider {
            public function namespace(): string
            {
                return 'app';
            }

            public function defaults(): array
            {
                return ['name' => 'KaririCode', 'debug' => false];
            }

            public function schema(): array
            {
                return ['name' => 'string', 'debug' => 'bool'];
            }
        };

        // Should not throw — defaults satisfy schema
        $config = ConfigurationBuilder::create()
            ->withProvider($provider)
            ->build();

        self::assertSame('KaririCode', $config->getString('app.name'));
    }

    public function testEnvInterpolationInProviderOverrides(): void
    {
        $resolver = new InMemoryEnvironmentResolver([
            'CACHE_DRIVER' => 'redis',
            'CACHE_TTL' => '7200',
        ]);

        $provider = new class implements ConfigProvider {
            public function namespace(): string
            {
                return 'cache';
            }

            public function defaults(): array
            {
                return ['driver' => 'file', 'ttl' => 3600];
            }

            public function schema(): array
            {
                return [];
            }
        };

        $config = ConfigurationBuilder::create()
            ->withProvider($provider)
            ->withEnvInterpolation($resolver)
            ->withOverrides([
                'cache' => [
                    'driver' => '${CACHE_DRIVER}',
                    'ttl' => '${CACHE_TTL}',
                ],
            ])
            ->build();

        self::assertSame('redis', $config->getString('cache.driver'));
        self::assertSame('7200', $config->getString('cache.ttl'));
    }
}
