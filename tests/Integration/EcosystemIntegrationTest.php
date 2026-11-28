<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Tests\Integration;

use KaririCode\Configurator\ConfigurationBuilder;
use KaririCode\Configurator\Environment\InMemoryEnvironmentResolver;
use KaririCode\Configurator\Environment\SystemEnvironmentResolver;
use KaririCode\Configurator\Integration\DotenvBridge;
use KaririCode\Configurator\Processor\EncryptedValueProcessor;
use KaririCode\Configurator\Processor\EnvironmentProcessor;
use KaririCode\Configurator\Processor\TypeCastProcessor;
use KaririCode\Contract\Configuration\ConfigProvider;
use KaririCode\Contract\Configuration\ValueProcessor;
use KaririCode\Contract\Environment\EnvironmentResolver;
use PHPUnit\Framework\TestCase;

/**
 * Verifies ecosystem contract compliance and Dotenv integration.
 *
 * Tests that all implementations satisfy the ecosystem-level contracts
 * of kariricode/contract.
 */
final class EcosystemIntegrationTest extends TestCase
{
    // ── Contract Compliance ───────────────────────────────────────────

    /**
     * @return iterable<string, array{class-string, class-string}>
     */
    public static function contractImplementations(): iterable
    {
        yield 'system resolver' => [SystemEnvironmentResolver::class, EnvironmentResolver::class];
        yield 'in-memory resolver' => [InMemoryEnvironmentResolver::class, EnvironmentResolver::class];
        yield 'composite resolver' => [\KaririCode\Configurator\Environment\CompositeEnvironmentResolver::class, EnvironmentResolver::class];
        yield 'environment processor' => [EnvironmentProcessor::class, ValueProcessor::class];
        yield 'type-cast processor' => [TypeCastProcessor::class, ValueProcessor::class];
        yield 'encrypted value processor' => [EncryptedValueProcessor::class, ValueProcessor::class];
    }

    /**
     * @param class-string $implementation
     * @param class-string $contract
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('contractImplementations')]
    public function testImplementationsDeclareTheEcosystemContracts(string $implementation, string $contract): void
    {
        self::assertTrue(is_subclass_of($implementation, $contract));
    }

    // ── withDotenv() Integration ──────────────────────────────────────

    public function testWithDotenvAcceptsObjectWithAllMethod(): void
    {
        $dotenv = new class {
            /** @return array<string, string> */
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

        self::assertSame('KaririCode', $config->getString('app.name'));
        self::assertSame('8080', $config->getString('app.port'));
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

        self::assertSame('db.kariricode.local', $config->getString('database.host'));
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

            self::assertSame('from_system', $config->getString('val'));
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
            /** @return array<string, string> */
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
                /** @return array<string, string> */
                public function all(): array
                {
                    return [];
                }
            };

            $config = ConfigurationBuilder::create()
                ->withDotenv($dotenv, strict: true, systemFallback: true)
                ->withOverrides(['val' => '${' . $key . '}'])
                ->build();

            self::assertSame('from_system', $config->getString('val'));
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
                /** @return array<string, string> */
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
            /** @return array<string, string> */
            public function all(): array
            {
                return ['BRIDGE_VAR' => 'bridged'];
            }
        };

        $config = DotenvBridge::configure($dotenv)
            ->withOverrides(['test' => '${BRIDGE_VAR}'])
            ->build();

        self::assertSame('bridged', $config->getString('test'));
    }

    // ── Ecosystem Provider via Canonical Interface ────────────────────

    public function testBuilderAcceptsEcosystemConfigProvider(): void
    {
        // Provider implementing the ECOSYSTEM interface directly
        // (not the local alias) — must still work with builder
        $provider = new class implements ConfigProvider {
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

        self::assertSame('sync', $config->getString('queue.driver'));
        self::assertSame(4, $config->getInt('queue.workers'));
    }

    // ── Ecosystem Processor via Canonical Interface ───────────────────

    public function testBuilderAcceptsEcosystemValueProcessor(): void
    {
        // Custom processor implementing the ECOSYSTEM interface directly
        $uppercaseProcessor = new class implements ValueProcessor {
            public function supports(mixed $value): bool
            {
                return is_string($value) && str_starts_with($value, 'upper:');
            }

            public function process(mixed $value): mixed
            {
                return is_string($value) ? strtoupper(substr($value, 6)) : $value;
            }
        };

        $config = ConfigurationBuilder::create()
            ->withValueProcessor($uppercaseProcessor)
            ->withOverrides(['greeting' => 'upper:hello world'])
            ->build();

        self::assertSame('HELLO WORLD', $config->getString('greeting'));
    }

    // ── Full Pipeline: Dotenv + TypeCast + Encrypted ──────────────────

    public function testFullPipelineWithDotenv(): void
    {
        $encKey = str_repeat('k', 32);
        $encrypted = EncryptedValueProcessor::encrypt('s3cr3t', $encKey);

        $dotenv = new class ($encrypted) {
            public function __construct(private readonly string $encVal) {}

            /** @return array<string, string> */
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
        self::assertSame(9090, $config->getInt('server.port'));

        // Env → Decrypt pipeline: "${DB_SECRET}" → "ENC(...)" → "s3cr3t"
        self::assertSame('s3cr3t', $config->getString('database.password'));
    }
}
