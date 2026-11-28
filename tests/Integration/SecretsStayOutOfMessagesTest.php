<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Tests\Integration;

use KaririCode\Configurator\Configuration;
use KaririCode\Configurator\Exception\ConfigurationException;
use KaririCode\Configurator\Loader\TomlLoader;
use KaririCode\Configurator\Loader\YamlLoader;
use KaririCode\Configurator\Processor\TypeCastProcessor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A configuration value never reaches an exception message (ADR-011):
 * messages name files, keys, lines and types, so a secret on a broken
 * line or in a value of the wrong type stays out of the logs.
 */
final class SecretsStayOutOfMessagesTest extends TestCase
{
    private const string SECRET = 's3cr3t-Value-91f2';

    private string $directory;

    #[\Override]
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/kariricode-config-secrets-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $files = glob($this->directory . '/*');
        array_map(unlink(...), $files === false ? [] : $files);
        rmdir($this->directory);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function brokenFiles(): iterable
    {
        yield 'YAML key at a bad indentation' => ['bad.yaml', "database:\n  host: localhost\n password: " . self::SECRET . "\n", "the key 'password'"];
        yield 'YAML line without a key' => ['bare.yaml', "app:\n  name: demo\n" . self::SECRET . "\n", 'neither a key nor a sequence item'];
        yield 'YAML flow collection with trailing text' => ['flow.yaml', 'tokens: [a, b] ' . self::SECRET . "\n", 'unexpected text after a flow collection'];
        yield 'TOML unquoted string' => ['bare.toml', "[database]\npassword = " . self::SECRET . "\n", 'invalid value (strings must be quoted) on line 2'];
        yield 'TOML string with trailing text' => ['tail.toml', 'password = "' . self::SECRET . '" x' . "\n", 'on line 1'];
    }

    #[DataProvider('brokenFiles')]
    public function testParseErrorsDescribeTheLineWithoutItsValue(string $name, string $content, string $expected): void
    {
        $path = $this->directory . '/' . $name;
        file_put_contents($path, $content);
        $loader = str_ends_with($name, '.toml') ? new TomlLoader() : new YamlLoader();

        try {
            $loader->load($path);
            self::fail('the file is invalid');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString($expected, $exception->getMessage());
            self::assertStringContainsString($path, $exception->getMessage());
            self::assertStringNotContainsString(self::SECRET, $exception->getMessage());
        }
    }

    public function testFailedCastsDoNotRepeatTheirPayload(): void
    {
        $processor = new TypeCastProcessor();

        foreach (['bool:' . self::SECRET, 'json:{"password": "' . self::SECRET . '"'] as $value) {
            try {
                $processor->process($value);
                self::fail('the cast must fail');
            } catch (ConfigurationException $exception) {
                self::assertStringNotContainsString(self::SECRET, $exception->getMessage());
            }
        }
    }

    public function testKeysAndValuesStayOutOfStackTraces(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');

        try {
            new \KaririCode\Configurator\Processor\EncryptedValueProcessor(self::SECRET);
            self::fail('the key is too short');
        } catch (ConfigurationException $exception) {
            self::assertStringNotContainsString(self::SECRET, $exception->getTraceAsString());
            self::assertStringNotContainsString(self::SECRET, print_r($exception->getTrace(), true));
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '1' : $previous);
        }
    }

    public function testTypedAccessorsDescribeTheRejectedString(): void
    {
        $config = new Configuration();
        $config->merge(['feature' => ['enabled' => self::SECRET]])->seal();

        try {
            $config->getBool('feature.enabled');
            self::fail('the value is not a boolean');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString('"feature.enabled"', $exception->getMessage());
            self::assertStringNotContainsString(self::SECRET, $exception->getMessage());
        }
    }
}
