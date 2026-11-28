<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Tests\Integration;

use KaririCode\Configurator\ConfigurationBuilder;
use KaririCode\Configurator\Contract\ConfigProvider;
use KaririCode\Configurator\Contract\EnvironmentResolver;
use KaririCode\Configurator\Contract\ValueProcessor;
use KaririCode\Configurator\Environment\InMemoryEnvironmentResolver;
use KaririCode\Configurator\Environment\SystemEnvironmentResolver;
use KaririCode\Configurator\Integration\DotenvBridge;
use KaririCode\Configurator\Processor\EncryptedValueProcessor;
use KaririCode\Configurator\Processor\EnvironmentProcessor;
use KaririCode\Configurator\Processor\TypeCastProcessor;
use KaririCode\Contract\Configuration\ConfigProviderInterface;
use KaririCode\Contract\Configuration\ValueProcessorInterface;
use KaririCode\Contract\Environment\EnvironmentResolverInterface;
use PHPUnit\Framework\TestCase;

/**
 * Verifies ecosystem contract compliance and Dotenv integration.
 *
 * Tests that all implementations satisfy both the ecosystem-level
 * contracts (kariricode/contract) and the backward-compatible
 * local aliases (kariricode/configurator Contract/).
 */
final class EcosystemIntegrationTest extends TestCase
{
    // ── Contract Compliance ───────────────────────────────────────────

    public function testSystemResolverImplementsEcosystemContract(): void
    {
        $resolver = new SystemEnvironmentResolver();

        $this->assertInstanceOf(EnvironmentResolverInterface::class, $resolver);
    }

    public function testInMemoryResolverImplementsEcosystemContract(): void
    {
        $resolver = new InMemoryEnvironmentResolver();

        $this->assertInstanceOf(EnvironmentResolverInterface::class, $resolver);
    }

    public function testCompositeResolverImplementsEcosystemContract(): void
    {
        $resolver = new \KaririCode\Configurator\Environment\CompositeEnvironmentResolver();

        $this->assertInstanceOf(EnvironmentResolverInterface::class, $resolver);
    }

    public function testEnvironmentProcessorImplementsEcosystemContract(): void
    {
        $processor = new EnvironmentProcessor();

        $this->assertInstanceOf(ValueProcessorInterface::class, $processor);
    }

    public function testTypeCastProcessorImplementsEcosystemContract(): void
    {
        $processor = new TypeCastProcessor();

        $this->assertInstanceOf(ValueProcessorInterface::class, $processor);
    }

    public function testEncryptedValueProcessorImplementsEcosystemContract(): void
    {
        $key = str_repeat('a', 32);
        $processor = new EncryptedValueProcessor($key);

        $this->assertInstanceOf(ValueProcessorInterface::class, $processor);
    }

    // ── Backward Compatibility — Local Aliases ────────────────────────

    public function testLocalEnvironmentResolverExtendsEcosystem(): void
    {
        $this->assertTrue(
            is_subclass_of(EnvironmentResolver::class, EnvironmentResolverInterface::class),
        );
    }

    public function testLocalValueProcessorExtendsEcosystem(): void
    {
        $this->assertTrue(
            is_subclass_of(ValueProcessor::class, ValueProcessorInterface::class),
        );
    }

    public function testLocalConfigProviderExtendsEcosystem(): void
    {
        $this->assertTrue(
            is_subclass_of(ConfigProvider::class, ConfigProviderInterface::class),
        );
    }

    // ── withDotenv() Integration ──────────────────────────────────────

    public function testWithDotenvAcceptsObjectWithAllMethod(): void
    {
        $dotenv = new class {
            public function all(): array
            {
                return [
                    'APP_NAME' => 'KaririCode',
                    'APP_PORT' => '8080',
                ];
            }
        };

        $config = ConfigurationBuilder::create()
            ->withDotenv($dotenv)
            ->withOverrides([
                'app' => [
                    'name' => '${APP_NAME}',
                    'port' => '${APP_PORT}',
                ],
            ])
            ->build();

        $this->assertSame('KaririCode', $config->getString('app.name'));
        $this->assertSame('8080', $config->getString('app.port'));
    }

    public function testWithDotenvAcceptsEnvironmentResolverDirectly(): void
    {
        $resolver = new InMemoryEnvironmentResolver([
            'DB_HOST' => 'db.kariricode.local',
        ]);

        $config = ConfigurationBuilder::create()
            ->withDotenv($resolver)
            ->withOverrides([
                'database' => ['host' => '${DB_HOST}'],
            ])
            ->build();

        $this->assertSame('db.kariricode.local', $config->getString('database.host'));
    }

    public function testWithDotenvResolverSystemFallbackDisabled(): void
    {
        $key = 'KCODE_RESOLVER_NOFALLBACK_' . bin2hex(random_bytes(4));
        $_ENV[$key] = 'should_not_resolve';

        try {
            $resolver = new InMemoryEnvironmentResolver([]);

            $this->expectException(\KaririCode\Configurator\Exception\ConfigurationException::class);

            ConfigurationBuilder::create()
                ->withDotenv($resolver, strict: true, systemFallback: false)
                ->withOverrides(['val' => '${' . $key . '}'])
                ->build();
        } finally {
            unset($_ENV[$key]);
        }
    }

    public function testWithDotenvResolverSystemFallbackEnabled(): void
    {
        $key = 'KCODE_RESOLVER_FALLBACK_' . bin2hex(random_bytes(4));
        $_ENV[$key] = 'from_system';

        try {
            $resolver = new InMemoryEnvironmentResolver([]);

            $config = ConfigurationBuilder::create()
                ->withDotenv($resolver, strict: true, systemFallback: true)
                ->withOverrides(['val' => '${' . $key . '}'])
                ->build();

            $this->assertSame('from_system', $config->getString('val'));
        } finally {
            unset($_ENV[$key]);
        }
    }

    public function testWithDotenvRejectsInvalidObject(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('all() method');

        ConfigurationBuilder::create()->withDotenv(new \stdClass());
    }

    public function testWithDotenvStrictModeThrowsOnUndefined(): void
    {
        $dotenv = new class {
            public function all(): array
            {
                return [];
            }
        };

        $this->expectException(\KaririCode\Configurator\Exception\ConfigurationException::class);

        ConfigurationBuilder::create()
            ->withDotenv($dotenv, strict: true)
            ->withOverrides(['key' => '${UNDEFINED_VAR}'])
            ->build();
    }

    public function testWithDotenvSystemFallback(): void
    {
        $key = 'KCODE_DOTENV_BRIDGE_TEST_' . bin2hex(random_bytes(4));
        $_ENV[$key] = 'from_system';

        try {
            $dotenv = new class {
                public function all(): array
                {
                    return [];
                }
            };

            $config = ConfigurationBuilder::create()
                ->withDotenv($dotenv, strict: true, systemFallback: true)
                ->withOverrides(['val' => '${' . $key . '}'])
                ->build();

            $this->assertSame('from_system', $config->getString('val'));
        } finally {
            unset($_ENV[$key]);
        }
    }

    public function testWithDotenvSystemFallbackDisabled(): void
    {
        $key = 'KCODE_DOTENV_NO_FALLBACK_' . bin2hex(random_bytes(4));
        $_ENV[$key] = 'should_not_resolve';

        try {
            $dotenv = new class {
                public function all(): array
                {
                    return [];
                }
            };

            $this->expectException(\KaririCode\Configurator\Exception\ConfigurationException::class);

            ConfigurationBuilder::create()
                ->withDotenv($dotenv, strict: true, systemFallback: false)
                ->withOverrides(['val' => '${' . $key . '}'])
                ->build();
        } finally {
            unset($_ENV[$key]);
        }
    }

    // ── DotenvBridge Convenience ──────────────────────────────────────

    public function testDotenvBridgeCreatesConfiguredBuilder(): void
    {
        $dotenv = new class {
            public function all(): array
            {
                return ['BRIDGE_VAR' => 'bridged'];
            }
        };

        $config = DotenvBridge::configure($dotenv)
            ->withOverrides(['test' => '${BRIDGE_VAR}'])
            ->build();

        $this->assertSame('bridged', $config->getString('test'));
    }

    // ── Ecosystem Provider via Canonical Interface ────────────────────

    public function testBuilderAcceptsEcosystemConfigProvider(): void
    {
        // Provider implementing the ECOSYSTEM interface directly
        // (not the local alias) — must still work with builder
        $provider = new class implements ConfigProviderInterface {
            public function namespace(): string
            {
                return 'queue';
            }

            public function defaults(): array
            {
                return ['driver' => 'sync', 'workers' => 4];
            }

            public function schema(): array
            {
                return ['driver' => 'string', 'workers' => 'int'];
            }
        };

        $config = ConfigurationBuilder::create()
            ->withProvider($provider)
            ->build();

        $this->assertSame('sync', $config->getString('queue.driver'));
        $this->assertSame(4, $config->getInt('queue.workers'));
    }

    // ── Ecosystem Processor via Canonical Interface ───────────────────

    public function testBuilderAcceptsEcosystemValueProcessor(): void
    {
        // Custom processor implementing the ECOSYSTEM interface directly
        $uppercaseProcessor = new class implements ValueProcessorInterface {
            public function supports(mixed $value): bool
            {
                return is_string($value) && str_starts_with($value, 'upper:');
            }

            public function process(mixed $value): mixed
            {
                return strtoupper(substr($value, 6));
            }
        };

        $config = ConfigurationBuilder::create()
            ->withValueProcessor($uppercaseProcessor)
            ->withOverrides(['greeting' => 'upper:hello world'])
            ->build();

        $this->assertSame('HELLO WORLD', $config->getString('greeting'));
    }

    // ── Full Pipeline: Dotenv + TypeCast + Encrypted ──────────────────

    public function testFullPipelineWithDotenv(): void
    {
        $encKey = str_repeat('k', 32);
        $encrypted = EncryptedValueProcessor::encrypt('s3cr3t', $encKey);

        $dotenv = new class ($encrypted) {
            public function __construct(private readonly string $encVal)
            {
            }

            public function all(): array
            {
                return [
                    'APP_PORT' => 'int:9090',
                    'DB_SECRET' => $this->encVal,
                ];
            }
        };

        $config = ConfigurationBuilder::create()
            ->withDotenv($dotenv)
            ->withValueProcessor(new TypeCastProcessor())
            ->withValueProcessor(new EncryptedValueProcessor($encKey))
            ->withOverrides([
                'server' => ['port' => '${APP_PORT}'],
                'database' => ['password' => '${DB_SECRET}'],
            ])
            ->build();

        // Env → TypeCast pipeline: "${APP_PORT}" → "int:9090" → 9090
        $this->assertSame(9090, $config->getInt('server.port'));

        // Env → Decrypt pipeline: "${DB_SECRET}" → "ENC(...)" → "s3cr3t"
        $this->assertSame('s3cr3t', $config->getString('database.password'));
    }
}
