<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Tests\Integration;

use KaririCode\Configurator\ConfigurationBuilder;
use KaririCode\Configurator\Environment\InMemoryEnvironmentResolver;
use KaririCode\Configurator\Environment\SystemEnvironmentResolver;
use KaririCode\Configurator\Processor\EncryptedValueProcessor;
use KaririCode\Configurator\Processor\EnvironmentProcessor;
use KaririCode\Configurator\Processor\TypeCastProcessor;
use KaririCode\Contract\Configuration\ConfigProvider;
use KaririCode\Contract\Configuration\ValueProcessor;
use KaririCode\Contract\Environment\EnvironmentResolver;
use PHPUnit\Framework\TestCase;

/**
 * Verifies ecosystem contract compliance and .env interpolation through
 * the contract's EnvironmentResolver.
 *
 * Tests that all implementations satisfy the ecosystem-level contracts
 * of kariricode/contract.
 */
final class EcosystemIntegrationTest extends TestCase
{
    // ── Contract Compliance ──────────────────────────────────────────────

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

    // ── withDotenv() ─────────────────────────────────────────────────────

    public function testWithDotenvInterpolatesFromTheResolver(): void
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

    public function testWithDotenvPrefersTheStoreToTheProcessEnvironment(): void
    {
        $key = 'KCODE_DOTENV_PRIORITY_' . bin2hex(random_bytes(4));
        $_ENV[$key] = 'from_system';

        try {
            $config = ConfigurationBuilder::create()
                ->withDotenv(new InMemoryEnvironmentResolver([$key => 'from_store']))
                ->withOverrides(['val' => '${' . $key . '}'])
                ->build();

            self::assertSame('from_store', $config->getString('val'));
        } finally {
            unset($_ENV[$key]);
        }
    }

    public function testWithDotenvFallsBackToTheProcessEnvironment(): void
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

    public function testWithDotenvWithoutFallbackIgnoresTheProcessEnvironment(): void
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

    public function testWithDotenvStrictModeThrowsOnUndefined(): void
    {
        $this->expectException(\KaririCode\Configurator\Exception\ConfigurationException::class);

        ConfigurationBuilder::create()
            ->withDotenv(new InMemoryEnvironmentResolver([]), strict: true, systemFallback: false)
            ->withOverrides(['key' => '${UNDEFINED_VAR}'])
            ->build();
    }

    public function testWithDotenvLenientModeResolvesUndefinedToEmpty(): void
    {
        $config = ConfigurationBuilder::create()
            ->withDotenv(new InMemoryEnvironmentResolver([]), strict: false, systemFallback: false)
            ->withOverrides(['key' => 'x${KCODE_UNDEFINED_VAR}y'])
            ->build();

        self::assertSame('xy', $config->getString('key'));
    }

    // ── Ecosystem Provider via Canonical Interface ───────────────────────

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

    // ── Ecosystem Processor via Canonical Interface ──────────────────────

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

    // ── Full Pipeline: Dotenv + TypeCast + Encrypted ─────────────────────

    public function testFullPipelineWithDotenv(): void
    {
        $encKey = str_repeat('k', 32);
        $encrypted = EncryptedValueProcessor::encrypt('s3cr3t', $encKey);

        $dotenv = new InMemoryEnvironmentResolver([
            'APP_PORT' => 'int:9090',
            'DB_SECRET' => $encrypted,
        ]);

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
