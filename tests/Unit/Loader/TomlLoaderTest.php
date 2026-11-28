<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Tests\Unit\Loader;

use KaririCode\Configurator\Exception\ConfigurationException;
use KaririCode\Configurator\Exception\LoaderException;
use KaririCode\Configurator\Loader\TomlLoader;
use PHPUnit\Framework\TestCase;

final class TomlLoaderTest extends TestCase
{
    private TomlLoader $loader;
    private string $fixtureDir;

    protected function setUp(): void
    {
        $this->loader = new TomlLoader();
        $this->fixtureDir = sys_get_temp_dir() . '/kariricode_toml_' . bin2hex(random_bytes(4));
        mkdir($this->fixtureDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', self::filesIn($this->fixtureDir . '/*'));
        rmdir($this->fixtureDir);
    }

    // ── Extension Support ─────────────────────────────────────────────

    public function testSupportsTomlExtension(): void
    {
        self::assertTrue($this->loader->supports('toml'));
        self::assertFalse($this->loader->supports('ini'));
    }

    // ── Scalars ───────────────────────────────────────────────────────

    public function testStringIntFloatBool(): void
    {
        $result = $this->load("name = \"KaririCode\"\nport = 8080\nratio = 3.14\ndebug = true\n");

        self::assertSame('KaririCode', $result['name']);
        self::assertSame(8080, $result['port']);
        self::assertSame(3.14, $result['ratio']);
        self::assertTrue($result['debug']);
    }

    public function testIntegerRadixes(): void
    {
        $result = $this->load("h = 0xff\no = 0o17\nb = 0b1010\nsep = 1_000_000\n");

        self::assertSame(255, $result['h']);
        self::assertSame(15, $result['o']);
        self::assertSame(10, $result['b']);
        self::assertSame(1000000, $result['sep']);
    }

    public function testLiteralStringHasNoEscapes(): void
    {
        $result = $this->load("path = 'C:\\Users\\raw'\n");

        self::assertSame('C:\\Users\\raw', $result['path']);
    }

    public function testBasicStringEscapes(): void
    {
        $result = $this->load('msg = "line1\nline2\ttab"' . "\n");

        self::assertSame("line1\nline2\ttab", $result['msg']);
    }

    public function testUnicodeEscape(): void
    {
        $result = $this->load('emoji = "caf\u00e9 \u20ac \U0001F510"' . "\n");

        self::assertSame('café € 🔐', $result['emoji']);
    }

    public function testInfinityAndNan(): void
    {
        $result = $this->load("a = inf\nb = -inf\nc = nan\n");

        self::assertSame(INF, $result['a']);
        self::assertSame(-INF, $result['b']);
        self::assertNan($result['c']);
    }

    // ── Tables and Keys ───────────────────────────────────────────────

    public function testTable(): void
    {
        $result = $this->load("[database]\nhost = \"localhost\"\nport = 5432\n");

        self::assertSame('localhost', self::at($result, 'database', 'host'));
        self::assertSame(5432, self::at($result, 'database', 'port'));
    }

    public function testDottedTable(): void
    {
        $result = $this->load("[a.b.c]\nvalue = 1\n");

        self::assertSame(1, self::at($result, 'a', 'b', 'c', 'value'));
    }

    public function testDottedKeys(): void
    {
        $result = $this->load("server.host = \"web1\"\nserver.port = 80\n");

        self::assertSame('web1', self::at($result, 'server', 'host'));
        self::assertSame(80, self::at($result, 'server', 'port'));
    }

    public function testTwoTables(): void
    {
        $result = $this->load("[app]\nname = \"x\"\n\n[db]\nname = \"y\"\n");

        self::assertSame('x', self::at($result, 'app', 'name'));
        self::assertSame('y', self::at($result, 'db', 'name'));
    }

    // ── Arrays and Inline Tables ──────────────────────────────────────

    public function testArrays(): void
    {
        $result = $this->load("ports = [80, 443, 8080]\nhosts = [\"a\", \"b\"]\n");

        self::assertSame([80, 443, 8080], $result['ports']);
        self::assertSame(['a', 'b'], $result['hosts']);
    }

    public function testNestedArray(): void
    {
        $result = $this->load("matrix = [[1, 2], [3, 4]]\n");

        self::assertSame([[1, 2], [3, 4]], $result['matrix']);
    }

    public function testMultiLineArray(): void
    {
        $result = $this->load("items = [\n  1,\n  2,\n  3,\n]\n");

        self::assertSame([1, 2, 3], $result['items']);
    }

    public function testInlineTable(): void
    {
        $result = $this->load("point = { x = 1, y = 2 }\n");

        self::assertSame(['x' => 1, 'y' => 2], $result['point']);
    }

    public function testEmptyCollections(): void
    {
        $result = $this->load("a = []\nb = {}\n");

        self::assertSame([], $result['a']);
        self::assertSame([], $result['b']);
    }

    // ── Comments ──────────────────────────────────────────────────────

    public function testCommentsStripped(): void
    {
        $result = $this->load("# full line\nkey = \"val\"  # trailing\n");

        self::assertSame('val', $result['key']);
    }

    public function testHashInsideStringPreserved(): void
    {
        $result = $this->load("url = \"http://example.com#frag\"\n");

        self::assertSame('http://example.com#frag', $result['url']);
    }

    // ── Unsupported Feature Detection ─────────────────────────────────

    public function testDetectsArrayOfTables(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('arrays of tables');

        $this->load("[[products]]\nname = \"x\"\n");
    }

    public function testDetectsMultiLineString(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('multi-line strings');

        $this->load("desc = \"\"\"\nmany\nlines\n\"\"\"\n");
    }

    public function testDetectsDateTime(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('date/time');

        $this->load("created = 1979-05-27T07:32:00Z\n");
    }

    // ── Errors ────────────────────────────────────────────────────────

    public function testThrowsForNonExistentFile(): void
    {
        $this->expectException(LoaderException::class);

        $this->loader->load($this->fixtureDir . '/missing.toml');
    }

    // ── Utility ───────────────────────────────────────────────────────

    // ── Strictness Regressions (V4.2 deep review) ─────────────────────

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidDocuments(): iterable
    {
        yield 'unquoted string' => ["mode = production\n"];
        yield 'typo in number' => ["port = 80a\n"];
        yield 'leading zero' => ["x = 0755\n"];
        yield 'double underscore' => ["x = 1__0\n"];
        yield 'signed binary' => ["x = -0b1\n"];
        yield 'uppercase inf' => ["x = INF\n"];
        yield 'invalid escape' => ["s = \"\\q\"\n"];
        yield 'surrogate escape' => ["s = \"\\uD800\"\n"];
        yield 'empty key segment' => ["a..b = 1\n"];
        yield 'duplicate key' => ["a = 1\na = 2\n"];
        yield 'duplicate table' => ["[a]\nx = 1\n[a]\ny = 2\n"];
        yield 'extending a value' => ["a = 1\na.b = 2\n"];
        yield 'header over dotted table' => ["[fruit]\napple.color = \"red\"\n[fruit.apple]\n"];
        yield 'inline table trailing comma' => ["pt = { x = 1, }\n"];
        yield 'missing array comma' => ["a = [1 2]\n"];
        yield 'unterminated string' => ["s = \"abc\n"];
        yield 'integer overflow' => ["x = 9223372036854775808\n"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidDocuments')]
    public function testRejectsInvalidDocument(string $toml): void
    {
        $this->expectException(LoaderException::class);
        $this->expectExceptionCode(1102);

        $this->load($toml);
    }

    public function testEscapedBackslashBeforeClosingQuote(): void
    {
        $data = $this->load("paths = [\"C:\\\\\", \"D:\\\\\"]\np = \"C:\\\\\" # win\n");

        self::assertSame(['C:\\', 'D:\\'], $data['paths']);
        self::assertSame('C:\\', $data['p']);
    }

    public function testQuotedEmptyAndDottedKeySegments(): void
    {
        self::assertSame(['' => 1, 'a' => ['b.c' => 2]], $this->load("\"\" = 1\na.\"b.c\" = 2\n"));
    }

    public function testSubTableOfDottedTableIsAllowed(): void
    {
        $data = $this->load("[fruit]\napple.color = \"red\"\n[fruit.apple.texture]\nsmooth = true\n");

        self::assertSame(['color' => 'red', 'texture' => ['smooth' => true]], self::at($data, 'fruit', 'apple'));
    }

    public function testErrorReportsRealPathAndLineAfterMultiLineArray(): void
    {
        $path = $this->fixtureDir . '/diag.toml';
        file_put_contents($path, "items = [\n  1,\n  2,\n]\nport = 80a\n");

        try {
            $this->loader->load($path);
            self::fail('Expected LoaderException');
        } catch (LoaderException $exception) {
            self::assertStringContainsString($path, $exception->getMessage());
            self::assertStringContainsString('on line 5', $exception->getMessage());
        }
    }

    public function testUnsupportedFeatureKeepsItsErrorCode(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionCode(1008);

        $this->load("[[products]]\nname = \"x\"\n");
    }

    /** @return array<mixed> */
    private function load(string $content): array
    {
        $path = $this->fixtureDir . '/config.toml';
        file_put_contents($path, $content);

        return $this->loader->load($path);
    }

    /** A nested value of a loaded array, read without assuming its shape. */
    private static function at(mixed $data, string|int ...$path): mixed
    {
        foreach ($path as $key) {
            self::assertIsArray($data);
            $data = $data[$key] ?? null;
        }

        return $data;
    }

    /** @return list<string> */
    private static function filesIn(string $pattern): array
    {
        $files = glob($pattern);

        return $files === false ? [] : $files;
    }
}
