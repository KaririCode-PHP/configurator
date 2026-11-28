<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Tests\Unit\Loader;

use KaririCode\Configurator\Exception\LoaderException;
use KaririCode\Configurator\Loader\TomlLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Small documents for each construct of the supported TOML 1.0 subset,
 * with the result TOML 1.0 (tomllib on the differential corpus) gives,
 * and the failures the loader reports with their line.
 */
final class TomlCorpusTest extends TestCase
{
    private string $path;

    #[\Override]
    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/kariricode-toml-corpus-' . bin2hex(random_bytes(4)) . '.toml';
    }

    #[\Override]
    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    /**
     * @return iterable<string, array{string, array<array-key, mixed>}>
     */
    public static function documents(): iterable
    {
        yield 'CRLF line endings' => ["a = 1\r\nb = \"two\"\r\n", ['a' => 1, 'b' => 'two']];
        yield 'no spaces around the equals sign' => ["a=1\nb=\"x\"\n", ['a' => 1, 'b' => 'x']];
        yield 'comments and indentation' => ["  a = 1   # one\n# whole line\n[t]  # table\n  b = 2\n", ['a' => 1, 't' => ['b' => 2]]];
        yield 'quoted keys' => ["\"a.b\" = 1\n'lit' = 2\na.\"b.c\".d = 3\n\"x\".y = 4\n", ['a.b' => 1, 'lit' => 2, 'a' => ['b.c' => ['d' => 3]], 'x' => ['y' => 4]]];
        yield 'booleans' => ["t = true\nf = false\n", ['t' => true, 'f' => false]];
        yield 'radixes and underscores' => [
            "h = 0xdead_beef\no = 0o7_5\nb = 0b1_0\nd = 1_000\nf = 1_000.5\ne = 1e1_0\n",
            ['h' => 0xDEADBEEF, 'o' => 61, 'b' => 2, 'd' => 1000, 'f' => 1000.5, 'e' => 1.0e10],
        ];
        yield 'arrays' => [
            "a = [ ]\nb = [1, 2,]\nc = [[1, 2], {x = 1}]\nd = [\n  1, # c\n  2\n]\n",
            ['a' => [], 'b' => [1, 2], 'c' => [[1, 2], ['x' => 1]], 'd' => [1, 2]],
        ];
        yield 'inline tables' => ["t = {a = 1, b = \"x\"}\ne = {}\n", ['t' => ['a' => 1, 'b' => 'x'], 'e' => []]];
        yield 'arrays of arrays' => ["a = [[1], [2]]\n", ['a' => [[1], [2]]]];
        yield 'byte order mark' => ["\xEF\xBB\xBFa = 1\n", ['a' => 1]];
        yield 'no spaces in arrays and inline tables' => ["n = [1,2,3]\nt = {a=12,b=\"x\"}\n", ['n' => [1, 2, 3], 't' => ['a' => 12, 'b' => 'x']]];
        yield 'array of inline tables' => ["a = [{x = 1}, {y = 2}]\n", ['a' => [['x' => 1], ['y' => 2]]]];
        yield 'quotes and brackets inside strings' => ["a = [\"x\\\"]\", 1]\np = 'C:\\' # path\n", ['a' => ['x"]', 1], 'p' => 'C:\\']];
    }

    /**
     * @param array<array-key, mixed> $expected
     */
    #[DataProvider('documents')]
    public function testParsesTheSupportedSubset(string $toml, array $expected): void
    {
        self::assertSame($expected, $this->load($toml));
    }

    public function testSpecialFloats(): void
    {
        $data = $this->load("a = inf\nb = +inf\nc = -inf\nd = nan\ne = +nan\nf = -nan\n");

        self::assertSame([INF, INF, -INF], [$data['a'], $data['b'], $data['c']]);

        foreach (['d', 'e', 'f'] as $key) {
            self::assertIsFloat($data[$key]);
            self::assertNan($data[$key]);
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function failures(): iterable
    {
        yield 'invalid bare key' => ["  a\$b = 1\n", "invalid key 'a\$b' on line 1"];
        yield 'unterminated quoted key' => ["\"abc = 1\n", "expected 'key = value' on line 1"];
        yield 'number prefix inside text' => ["a = x0x1F\n", 'invalid value (strings must be quoted) on line 1'];
        yield 'octal prefix inside text' => ["a = x0o7\n", 'invalid value (strings must be quoted) on line 1'];
        yield 'dotted key that starts with an invalid character' => ["?.a = 1\n", "invalid key '?.a' on line 1"];
        yield 'text after an array' => ["a = [1, 2] x\n", 'unexpected text after a closing bracket on line 1'];
        yield 'text after an inline table' => ["t = {a = 1} x\n", 'unexpected text after a closing bracket on line 1'];
        yield 'two arrays' => ["a = [1] [2]\n", 'unexpected text after a closing bracket on line 1'];
        yield 'empty item' => ["a = [,]\n", "unexpected ',' (empty item) on line 1"];
        yield 'trailing comma in an inline table' => ["t = {a = 1,}\n", "unexpected ',' (empty item) on line 1"];
        yield 'unterminated array' => ["a = [1,\n2\n", 'unterminated array on line 1'];
        yield 'missing value' => ["a =\n", 'missing value on line 1'];
        yield 'array closed by a brace' => ["a = [1, 2}\n", "mismatched closing bracket '}' on line 1"];
        yield 'inline table closed by a square bracket' => ["t = {x = 1]\n", "mismatched closing bracket ']' on line 1"];
        yield 'crossed nested brackets' => ["a = [{x = 1]}\n", "mismatched closing bracket ']' on line 1"];
        yield 'unterminated inline table' => ["t = {x = 1\n", 'unbalanced brackets on line 1'];
        yield 'inline table across lines inside an array' => ["a = [\n  {x = 1,\n  y = 2}\n]\n", 'inline tables must be on a single line on line 1'];
        yield 'inline table item without an equals sign' => ["t = {a}\n", "expected 'key = value' in an inline table on line 1"];
        yield 'malformed table header' => ["[a\nb = 1\n", "malformed table header '[a' on line 1"];
        yield 'raw control character in a string' => ["a = \"x\x01y\"\n", 'control characters must be escaped in strings on line 1'];
        yield 'short unicode escape' => ["a = \"\\u12\"\n", "invalid escape sequence '\\u': expected 4 hexadecimal digits on line 1"];
    }

    #[DataProvider('failures')]
    public function testRejectsInvalidDocumentsWithTheLine(string $toml, string $message): void
    {
        try {
            $this->load($toml);
            self::fail('the document must be rejected');
        } catch (LoaderException $exception) {
            self::assertSame(2820, $exception->getCode());
            self::assertStringContainsString($message, $exception->getMessage());
        }
    }

    public function testADirectoryIsNotAConfigurationFile(): void
    {
        $this->expectException(LoaderException::class);
        $this->expectExceptionCode(2811);

        new TomlLoader()->load(sys_get_temp_dir());
    }

    /** @return array<array-key, mixed> */
    private function load(string $toml): array
    {
        file_put_contents($this->path, $toml);

        return new TomlLoader()->load($this->path);
    }
}
