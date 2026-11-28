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

    // ── Providers, validators and loaders ────────────────────────────────

    public function testAProviderSchemaIsEnforced(): void
    {
        $this->expectException(\KaririCode\Configurator\Exception\ValidationException::class);

        ConfigurationBuilder::create()->withProvider(self::provider())->build();
    }

    public function testAProviderSchemaMergesWithTheApplicationSchema(): void
    {
        try {
            ConfigurationBuilder::create()
                ->withProvider(self::provider())
                ->withSchema(['app.name' => 'string'])
                ->build();
            self::fail('both schemas apply');
        } catch (\KaririCode\Configurator\Exception\ValidationException $exception) {
            self::assertSame(["Missing required key: 'mailer.dsn'.", "Missing required key: 'app.name'."], $exception->violations);
        }
    }

    public function testTheApplicationSchemaWinsOnTheSamePath(): void
    {
        $config = ConfigurationBuilder::create()
            ->withProvider(self::provider())
            ->withSchema(['mailer.dsn' => '?string'])
            ->build();

        self::assertSame('smtp', $config->getString('mailer.transport'));
    }

    public function testAProviderSchemaRunsNextToACustomValidator(): void
    {
        $custom = new class implements \KaririCode\Configurator\Contract\ConfigValidator {
            #[\Override]
            public function validate(array $data): void
            {
                throw \KaririCode\Configurator\Exception\ValidationException::requiredMissing('tls.policy');
            }
        };

        try {
            ConfigurationBuilder::create()->withProvider(self::provider())->withValidator($custom)->build();
            self::fail('both validators apply');
        } catch (\KaririCode\Configurator\Exception\ValidationException $exception) {
            self::assertSame(["Missing required key: 'mailer.dsn'.", 'Missing required key: tls.policy'], $exception->violations);
        }
    }

    public function testACustomLoaderIsRegisteredForItsExtensions(): void
    {
        $directory = sys_get_temp_dir() . '/kariricode-config-loader-' . bin2hex(random_bytes(4));
        mkdir($directory);
        file_put_contents($directory . '/settings.conf', 'ignored');

        try {
            $config = ConfigurationBuilder::create()
                ->withLoader(new class implements \KaririCode\Configurator\Contract\Loader {
                    #[\Override]
                    public function load(string $path): array
                    {
                        return ['from' => basename($path)];
                    }

                    #[\Override]
                    public function supports(string $extension): bool
                    {
                        return $extension === 'conf';
                    }
                }, 'conf')
                ->withFile($directory . '/settings.conf')
                ->build();

            self::assertSame('settings.conf', $config->getString('from'));
        } finally {
            unlink($directory . '/settings.conf');
            rmdir($directory);
        }
    }

    public function testEnvInterpolationIsStrictByDefault(): void
    {
        $this->expectException(\KaririCode\Configurator\Exception\ProcessorException::class);

        ConfigurationBuilder::create()
            ->withEnvInterpolation(new \KaririCode\Configurator\Environment\InMemoryEnvironmentResolver())
            ->withOverrides(['value' => '${KCODE_SURELY_UNDEFINED_' . bin2hex(random_bytes(4)) . '}'])
            ->build();
    }

    public function testWithDotenvIsStrictAndFallsBackToTheProcessByDefault(): void
    {
        $key = 'KCODE_DOTENV_DEFAULTS_' . bin2hex(random_bytes(4));
        $_ENV[$key] = 'from_process';

        try {
            $config = ConfigurationBuilder::create()
                ->withDotenv(new \KaririCode\Configurator\Environment\InMemoryEnvironmentResolver())
                ->withOverrides(['value' => '${' . $key . '}'])
                ->build();

            self::assertSame('from_process', $config->getString('value'));
        } finally {
            unset($_ENV[$key]);
        }

        $this->expectException(\KaririCode\Configurator\Exception\ProcessorException::class);

        ConfigurationBuilder::create()
            ->withDotenv(new \KaririCode\Configurator\Environment\InMemoryEnvironmentResolver())
            ->withOverrides(['value' => '${' . $key . '}'])
            ->build();
    }

    public function testFilesOfADirectoryLoadInNameOrderAcrossExtensions(): void
    {
        $directory = sys_get_temp_dir() . '/kariricode-config-order-' . bin2hex(random_bytes(4));
        mkdir($directory);
        file_put_contents($directory . '/a.json', '{"winner": "a.json"}');
        file_put_contents($directory . '/b.php', "<?php return ['winner' => 'b.php'];");

        try {
            $config = ConfigurationBuilder::create()->withDirectory($directory)->build();

            self::assertSame('b.php', $config->getString('winner'));
        } finally {
            array_map(unlink(...), [$directory . '/a.json', $directory . '/b.php']);
            rmdir($directory);
        }
    }

    public function testADirectoryAfterAGlobPatternStillLoads(): void
    {
        $config = ConfigurationBuilder::create()
            ->withDirectory($this->fixturesPath . '/app.php*')
            ->withDirectory($this->fixturesPath)
            ->build();

        self::assertSame('smtp', $config->get('services.mailer.transport'));
    }

    public function testAMissingDirectoryIsSkipped(): void
    {
        self::assertSame([], ConfigurationBuilder::create()->withDirectory($this->fixturesPath . '/does-not-exist')->build()->all());

        $config = ConfigurationBuilder::create()
            ->withDirectory($this->fixturesPath . '/does-not-exist')
            ->withDirectory($this->fixturesPath)
            ->build();

        self::assertSame('smtp', $config->get('services.mailer.transport'));
    }

    private static function provider(): \KaririCode\Contract\Configuration\ConfigProvider
    {
        return new class implements \KaririCode\Contract\Configuration\ConfigProvider {
            #[\Override]
            public function namespace(): string
            {
                return 'mailer';
            }

            #[\Override]
            public function defaults(): array
            {
                return ['transport' => 'smtp'];
            }

            #[\Override]
            public function schema(): array
            {
                return ['dsn' => 'string', 'transport' => 'string'];
            }
        };
    }

    public function testACustomValidatorAloneKeepsItsOwnException(): void
    {
        $custom = new class implements \KaririCode\Configurator\Contract\ConfigValidator {
            #[\Override]
            public function validate(array $data): void
            {
                throw \KaririCode\Configurator\Exception\ValidationException::requiredMissing('tls.policy');
            }
        };

        $this->expectException(\KaririCode\Configurator\Exception\ValidationException::class);
        $this->expectExceptionCode(2851);

        ConfigurationBuilder::create()->withValidator($custom)->build();
    }
}
