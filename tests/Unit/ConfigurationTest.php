<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Tests\Unit;

use KaririCode\Configurator\Configuration;
use KaririCode\Configurator\Exception\ConfigurationException;
use KaririCode\Configurator\MergeStrategy\RecursiveMerge;
use KaririCode\Configurator\MergeStrategy\ReplaceMerge;
use PHPUnit\Framework\TestCase;

final class ConfigurationTest extends TestCase
{
    private string $fixturesPath;

    protected function setUp(): void
    {
        $this->fixturesPath = dirname(__DIR__) . '/Fixture/Config';
    }

    // ── Loading ──────────────────────────────────────────────────────────

    public function testLoadPhpFile(): void
    {
        $config = new Configuration();
        $config->load($this->fixturesPath . '/app.php');

        self::assertSame('TestApp', $config->get('app.name'));
        self::assertSame(5432, $config->get('database.port'));
        self::assertTrue($config->get('app.debug'));
    }

    public function testLoadJsonFile(): void
    {
        $config = new Configuration();
        $config->load($this->fixturesPath . '/services.json');

        self::assertSame('smtp', $config->get('services.mailer.transport'));
        self::assertSame(587, $config->get('services.mailer.port'));
    }

    public function testLoadYamlFile(): void
    {
        $config = new Configuration();
        $config->load($this->fixturesPath . '/logging.yaml');

        self::assertSame('info', $config->get('logging.level'));
        self::assertTrue($config->get('security.rate_limit.enabled'));
        self::assertSame(60, $config->get('security.rate_limit.requests_per_minute'));
    }

    public function testLoadMultipleFiles(): void
    {
        $config = new Configuration();
        $config->load($this->fixturesPath . '/app.php');
        $config->load($this->fixturesPath . '/services.json');

        self::assertSame('TestApp', $config->get('app.name'));
        self::assertSame('smtp', $config->get('services.mailer.transport'));
    }

    // ── Merge ────────────────────────────────────────────────────────────

    public function testMergeOverridesExistingValues(): void
    {
        $config = new Configuration();
        $config->load($this->fixturesPath . '/app.php');
        $config->merge(['app' => ['debug' => false, 'custom' => 'value']]);

        self::assertFalse($config->get('app.debug'));
        self::assertSame('value', $config->get('app.custom'));
        // Non-overridden values preserved
        self::assertSame('TestApp', $config->get('app.name'));
    }

    public function testRecursiveMergePreservesNestedKeys(): void
    {
        $config = new Configuration(new RecursiveMerge());
        $config->load($this->fixturesPath . '/app.php');
        $config->merge(['database' => ['host' => 'db.prod.com']]);

        // Overridden
        self::assertSame('db.prod.com', $config->get('database.host'));
        // Preserved by recursive merge
        self::assertSame('pgsql', $config->get('database.driver'));
        self::assertSame(5432, $config->get('database.port'));
    }

    public function testReplaceMergeReplacesTopLevel(): void
    {
        $config = new Configuration(new ReplaceMerge());
        $config->merge(['database' => ['driver' => 'pgsql', 'host' => 'localhost']]);
        $config->merge(['database' => ['host' => 'db.prod.com']]);

        self::assertSame('db.prod.com', $config->get('database.host'));
        // driver is lost with replace merge
        self::assertNull($config->get('database.driver'));
    }

    // ── Seal (Immutability) ──────────────────────────────────────────────

    public function testSealPreventsLoad(): void
    {
        $config = new Configuration();
        $config->load($this->fixturesPath . '/app.php');
        $config->seal();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionCode(2801);
        $config->load($this->fixturesPath . '/services.json');
    }

    public function testSealPreventsMerge(): void
    {
        $config = new Configuration();
        $config->seal();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionCode(2801);
        $config->merge(['key' => 'value']);
    }

    // ── Typed Accessors ──────────────────────────────────────────────────

    public function testGetStringCoercesScalars(): void
    {
        $config = new Configuration();
        $config->merge(['port' => 8080, 'flag' => true, 'name' => 'test']);

        self::assertSame('8080', $config->getString('port'));
        self::assertSame('1', $config->getString('flag'));
        self::assertSame('test', $config->getString('name'));
    }

    public function testGetStringThrowsForArray(): void
    {
        $config = new Configuration();
        $config->merge(['items' => [1, 2, 3]]);

        $this->expectException(ConfigurationException::class);
        $config->getString('items');
    }

    public function testGetIntParsesNumericStrings(): void
    {
        $config = new Configuration();
        $config->merge(['port' => '8080', 'count' => 42]);

        self::assertSame(8080, $config->getInt('port'));
        self::assertSame(42, $config->getInt('count'));
    }

    public function testGetIntThrowsForNonNumeric(): void
    {
        $config = new Configuration();
        $config->merge(['name' => 'not-a-number']);

        $this->expectException(ConfigurationException::class);
        $config->getInt('name');
    }

    public function testGetFloatParsesNumericValues(): void
    {
        $config = new Configuration();
        $config->merge(['rate' => '1.5', 'integer' => 42, 'float' => 3.14]);

        self::assertSame(1.5, $config->getFloat('rate'));
        self::assertSame(42.0, $config->getFloat('integer'));
        self::assertSame(3.14, $config->getFloat('float'));
    }

    public function testGetBoolParsesStringValues(): void
    {
        $config = new Configuration();
        $config->merge([
            'a' => 'true',
            'b' => 'false',
            'c' => 'yes',
            'd' => 'no',
            'e' => '1',
            'f' => '0',
            'g' => 'on',
            'h' => 'off',
            'i' => true,
            'j' => 0,
        ]);

        self::assertTrue($config->getBool('a'));
        self::assertFalse($config->getBool('b'));
        self::assertTrue($config->getBool('c'));
        self::assertFalse($config->getBool('d'));
        self::assertTrue($config->getBool('e'));
        self::assertFalse($config->getBool('f'));
        self::assertTrue($config->getBool('g'));
        self::assertFalse($config->getBool('h'));
        self::assertTrue($config->getBool('i'));
        self::assertFalse($config->getBool('j'));
    }

    public function testGetBoolThrowsForInvalidString(): void
    {
        $config = new Configuration();
        $config->merge(['value' => 'maybe']);

        $this->expectException(ConfigurationException::class);
        $config->getBool('value');
    }

    public function testGetBoolRejectsAFloat(): void
    {
        $config = new Configuration();
        $config->merge(['ratio' => 0.5]);

        $this->expectException(\KaririCode\Configurator\Exception\AccessException::class);
        $this->expectExceptionCode(2800);
        $this->expectExceptionMessage('float');

        $config->getBool('ratio');
    }

    public function testGetArrayReturnsArray(): void
    {
        $config = new Configuration();
        $config->merge(['items' => ['a', 'b', 'c']]);

        self::assertSame(['a', 'b', 'c'], $config->getArray('items'));
    }

    public function testGetArrayThrowsForScalar(): void
    {
        $config = new Configuration();
        $config->merge(['name' => 'scalar']);

        $this->expectException(ConfigurationException::class);
        $config->getArray('name');
    }

    // ── Defaults ─────────────────────────────────────────────────────────

    public function testGetReturnsDefaultWhenMissing(): void
    {
        $config = new Configuration();

        self::assertSame('fallback', $config->get('missing.path', 'fallback'));
        self::assertSame('default', $config->getString('missing', 'default'));
        self::assertSame(99, $config->getInt('missing', 99));
        self::assertSame(1.1, $config->getFloat('missing', 1.1));
        self::assertTrue($config->getBool('missing', true));
        self::assertSame([], $config->getArray('missing'));
    }

    // ── Has ──────────────────────────────────────────────────────────────

    public function testHasReturnsTrueForExistingPath(): void
    {
        $config = new Configuration();
        $config->merge(['app' => ['name' => 'Test', 'debug' => false]]);

        self::assertTrue($config->has('app'));
        self::assertTrue($config->has('app.name'));
        self::assertTrue($config->has('app.debug'));
    }

    public function testHasReturnsFalseForMissingPath(): void
    {
        $config = new Configuration();

        self::assertFalse($config->has('nonexistent'));
        self::assertFalse($config->has('app.deep.path'));
    }

    // ── Property Hooks ───────────────────────────────────────────────────

    public function testCountPropertyHook(): void
    {
        $config = new Configuration();
        self::assertSame(0, $config->count);

        $config->merge(['a' => 1, 'b' => 2, 'c' => 3]);
        self::assertSame(3, $config->count);
    }

    public function testIsEmptyPropertyHook(): void
    {
        $config = new Configuration();
        self::assertTrue($config->isEmpty);

        $config->merge(['key' => 'value']);
        self::assertFalse($config->isEmpty);
    }

    public function testKeysPropertyHook(): void
    {
        $config = new Configuration();
        $config->merge(['alpha' => 1, 'beta' => 2]);

        self::assertSame(['alpha', 'beta'], $config->keys);
    }

    // ── All ──────────────────────────────────────────────────────────────

    public function testAllReturnsCompleteData(): void
    {
        $config = new Configuration();
        $config->merge(['a' => 1, 'b' => ['c' => 2]]);

        self::assertSame(['a' => 1, 'b' => ['c' => 2]], $config->all());
    }

    // ── Fluent API ───────────────────────────────────────────────────────

    public function testFluentLoadChaining(): void
    {
        $config = new Configuration();
        $result = $config
            ->load($this->fixturesPath . '/app.php')
            ->load($this->fixturesPath . '/services.json')
            ->seal();

        self::assertSame($config, $result);
        self::assertSame('TestApp', $config->get('app.name'));
        self::assertSame('smtp', $config->get('services.mailer.transport'));
    }

    // ── Unsupported Format ───────────────────────────────────────────────

    public function testLoadThrowsForUnsupportedExtension(): void
    {
        $config = new Configuration();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionCode(2810);
        $config->load('/some/file.xml');
    }

    public function testIniAndTomlAreSupportedByDefault(): void
    {
        $dir = sys_get_temp_dir() . '/kariricode_formats_' . bin2hex(random_bytes(4));
        mkdir($dir, 0o755, true);
        file_put_contents($dir . '/app.ini', "[app]\nname = from_ini\n");
        file_put_contents($dir . '/db.toml', "[db]\nport = 5432\n");

        try {
            $config = new Configuration();
            $config->load($dir . '/app.ini');
            $config->load($dir . '/db.toml');

            self::assertSame('from_ini', $config->get('app.name'));
            self::assertSame(5432, $config->get('db.port'));
        } finally {
            array_map('unlink', self::filesIn($dir . '/*'));
            rmdir($dir);
        }
    }

    /** @return list<string> */
    private static function filesIn(string $pattern): array
    {
        $files = glob($pattern);

        return $files === false ? [] : $files;
    }

    // ── Defaults and coercions ───────────────────────────────────────────

    public function testAStoredValueWinsOverTheDefault(): void
    {
        $config = new Configuration();
        $config->merge(['app' => ['name' => 'demo']])->seal();

        self::assertSame('demo', $config->get('app.name', 'fallback'));
    }

    public function testTypedReadsOfAMissingPathReturnTheirDefaults(): void
    {
        $config = new Configuration();
        $config->seal();

        self::assertSame('', $config->getString('missing'));
        self::assertSame(0, $config->getInt('missing'));
        self::assertSame(0.0, $config->getFloat('missing'));
        self::assertFalse($config->getBool('missing'));
        self::assertSame([], $config->getArray('missing'));
        self::assertSame(['a', 'b'], $config->getArray('missing', ['a', 'b']));
    }

    public function testNumericReadsCoerceNumbersAndNumericStrings(): void
    {
        $config = new Configuration();
        $config->merge(['n' => ['int' => 7, 'float' => 2.5, 'text' => '42', 'exp' => '1e3']])->seal();

        self::assertSame(7, $config->getInt('n.int'));
        self::assertSame(2, $config->getInt('n.float'));
        self::assertSame(42, $config->getInt('n.text'));
        self::assertSame(7.0, $config->getFloat('n.int'));
        self::assertSame(2.5, $config->getFloat('n.float'));
        self::assertSame(1000.0, $config->getFloat('n.exp'));
    }

    public function testBooleanWordsAreCaseInsensitiveAndTheEmptyStringIsFalse(): void
    {
        $config = new Configuration();
        $config->merge(['f' => ['upper' => 'TRUE', 'mixed' => 'Off', 'empty' => '', 'int' => 2, 'zero' => 0]])->seal();

        self::assertTrue($config->getBool('f.upper'));
        self::assertFalse($config->getBool('f.mixed'));
        self::assertFalse($config->getBool('f.empty'));
        self::assertTrue($config->getBool('f.int'));
        self::assertFalse($config->getBool('f.zero'));
    }

    public function testExtensionsAreMatchedWithoutRegardToCase(): void
    {
        $directory = sys_get_temp_dir() . '/kariricode-config-case-' . bin2hex(random_bytes(4));
        mkdir($directory);
        file_put_contents($directory . '/APP.JSON', '{"app": {"name": "upper"}}');

        try {
            $config = new Configuration();
            $config->registerLoader(new \KaririCode\Configurator\Loader\JsonLoader(), 'CONF');
            file_put_contents($directory . '/extra.conf', '{"extra": true}');

            $config->load($directory . '/APP.JSON')->load($directory . '/extra.conf')->seal();

            self::assertSame('upper', $config->getString('app.name'));
            self::assertTrue($config->getBool('extra'));
        } finally {
            array_map(unlink(...), [$directory . '/APP.JSON', $directory . '/extra.conf']);
            rmdir($directory);
        }
    }

    public function testAFloatReadOfTextFails(): void
    {
        $config = new Configuration();
        $config->merge(['rate' => 'fast'])->seal();

        $this->expectException(\KaririCode\Configurator\Exception\AccessException::class);
        $this->expectExceptionCode(2800);

        $config->getFloat('rate');
    }
}
