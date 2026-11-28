<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Tests\Unit\Loader;

use KaririCode\Configurator\Exception\LoaderException;
use KaririCode\Configurator\Loader\YamlLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Small documents for each construct of the supported YAML 1.2 subset,
 * with the result YAML 1.2 (PyYAML on the differential corpus) gives,
 * and the failures the loader reports for what the subset excludes.
 */
final class YamlCorpusTest extends TestCase
{
    private string $path;

    #[\Override]
    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/kariricode-yaml-corpus-' . bin2hex(random_bytes(4)) . '.yaml';
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
        yield 'CRLF line endings' => ["a: 1\r\nb: two\r\n", ['a' => 1, 'b' => 'two']];
        yield 'CR line endings' => ["a: 1\rb: two\r", ['a' => 1, 'b' => 'two']];
        yield 'byte order mark' => ["\xEF\xBB\xBFa: 1\n", ['a' => 1]];
        yield 'markers with trailing spaces' => ["--- \na: 1\n... \n", ['a' => 1]];
        yield 'directive' => ["%YAML 1.2\n---\na: 1\n", ['a' => 1]];
        yield 'keep chomping at the end of the file' => ["text: |+\n  line\n", ['text' => "line\n"]];
        yield 'keep chomping before a key' => ["text: |+\n  line\n\n\nnext: 1\n", ['text' => "line\n\n\n", 'next' => 1]];
        yield 'strip chomping, folded' => ["text: >-\n  one\n  two\n", ['text' => 'one two']];
        yield 'folding keeps more-indented lines' => ["text: >\n  one\n    indented\n  two\n\n  three\n", ['text' => "one\n  indented\ntwo\nthree\n"]];
        yield 'indentation indicator' => ["text: |2\n    two spaces kept\n  base\n", ['text' => "  two spaces kept\nbase\n"]];
        yield 'indicators in either order' => ["a: |-2\n   x\nb: |2-\n   y\n", ['a' => ' x', 'b' => ' y']];
        yield 'empty block scalar' => ["text: |\nnext: 1\n", ['text' => '', 'next' => 1]];
        yield 'multi-line flow sequence with comments' => ["list: [a, # first\n  b,\n  # comment line\n  c]\n", ['list' => ['a', 'b', 'c']]];
        yield 'flow mapping' => ["map: {a: 1, 'b': \"two\", c}\n", ['map' => ['a' => 1, 'b' => 'two', 'c' => null]]];
        yield 'nested flow collections' => ["x: [[1, 2], {k: [3]}]\n", ['x' => [[1, 2], ['k' => [3]]]]];
        yield 'quoted and spaced keys' => ["\"quoted key\": 1\n'single': 2\nspaced : 3\n", ['quoted key' => 1, 'single' => 2, 'spaced' => 3]];
        yield 'quoted key with a colon' => ["\"a:b\": 1\n", ['a:b' => 1]];
        yield 'empty quoted strings' => ["a: \"\"\nb: ''\n", ['a' => '', 'b' => '']];
        yield 'escaped single quote' => ["a: 'it''s'\n", ['a' => "it's"]];
        yield 'double-quoted escapes' => ["a: \"tab\\tnl\\nq\\\"bs\\\\x\\x41u\\u00e9U\\U0001F600\"\n", ['a' => "tab\tnl\nq\"bs\\xAu\u{e9}U\u{1F600}"]];
        yield 'integers in every radix' => [
            "a: 0b101\nb: 0B11\nc: 0o17\nd: 0O7\ne: 0x1F\nf: -0x1f\ng: -0b11\nh: -0o7\ni: 042\nj: -17\n",
            ['a' => 5, 'b' => 3, 'c' => 15, 'd' => 7, 'e' => 31, 'f' => -31, 'g' => -3, 'h' => -7, 'i' => 42, 'j' => -17],
        ];
        yield 'floats' => ["k: .5\nl: 1.\nm: 1.5e3\nn: -2.5E-2\n", ['k' => 0.5, 'l' => 1.0, 'm' => 1500.0, 'n' => -0.025]];
        yield 'plain scalars that only look like numbers' => [
            "a: x0b101\nb: abc123\nc: v1.5\nd: 0x\ne: 1_000\nf: 0b2\ng: 7up\n",
            ['a' => 'x0b101', 'b' => 'abc123', 'c' => 'v1.5', 'd' => '0x', 'e' => '1_000', 'f' => '0b2', 'g' => '7up'],
        ];
        yield 'booleans and nulls' => [
            "a: True\nb: NO\nc: on\nd: Off\ne: yes\nf: null\ng: Null\nh: ~\ni:\n",
            ['a' => true, 'b' => false, 'c' => true, 'd' => false, 'e' => true, 'f' => null, 'g' => null, 'h' => null, 'i' => null],
        ];
        yield 'sequence of sequences' => ["x:\n  - - a\n    - b\n  - - c\n", ['x' => [['a', 'b'], ['c']]]];
        yield 'sequence at the parent key indentation' => ["x:\n- a\n- b\ny: 1\n", ['x' => ['a', 'b'], 'y' => 1]];
        yield 'sequence of mappings' => ["x:\n  - name: a\n    port: 1\n  - name: b\n", ['x' => [['name' => 'a', 'port' => 1], ['name' => 'b']]]];
        yield 'empty items' => ["x:\n  -\n    k: v\n  - ~\n", ['x' => [['k' => 'v'], null]]];
        yield 'top-level sequence' => ["- a\n- b\n", ['a', 'b']];
        yield 'multi-line plain scalar' => ["text: first\n  second\n  third\nnext: 1\n", ['text' => 'first second third', 'next' => 1]];
        yield 'hash signs that are not comments' => ["a: \"#not comment\" # comment\nb: 'x#y'\nc: x#y\n", ['a' => '#not comment', 'b' => 'x#y', 'c' => 'x#y']];
        yield 'colons inside a plain scalar' => ["url: http://example.com:8080/path\n", ['url' => 'http://example.com:8080/path']];
        yield 'asterisks that are not aliases' => ["a: \"*x\"\nb: 'x *y'\nc: x*y\n", ['a' => '*x', 'b' => 'x *y', 'c' => 'x*y']];
        yield 'comments everywhere' => ["# head\na: 1 # tail\n  # indented comment\nb: 2\n", ['a' => 1, 'b' => 2]];
        yield 'key with trailing spaces' => ["parent:   \n  child: 1\n", ['parent' => ['child' => 1]]];
    }

    /**
     * @param array<array-key, mixed> $expected
     */
    #[DataProvider('documents')]
    public function testParsesTheSupportedSubset(string $yaml, array $expected): void
    {
        self::assertSame($expected, $this->load($yaml));
    }

    public function testSpecialFloats(): void
    {
        $data = $this->load("a: .inf\nb: +.inf\nc: -.inf\nd: .INF\ne: .nan\n");

        self::assertSame([INF, INF, -INF, INF], [$data['a'], $data['b'], $data['c'], $data['d']]);
        self::assertIsFloat($data['e']);
        self::assertNan($data['e']);
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function failures(): iterable
    {
        yield 'anchor on line 3' => ["a: 1\nb: 2\nc: &x 3\n", 2822, 'at line 3: YAML anchors (&name) are not supported'];
        yield 'anchor ending a key line' => ["a: &anchor\n  b: 1\n", 2822, 'at line 1: YAML anchors'];
        yield 'anchor on an item' => ["x:\n  - &a 1\n", 2822, 'at line 2: YAML anchors'];
        yield 'alias on line 2' => ["a: 1\nb: *x\n", 2822, 'at line 2: YAML aliases (*name) are not supported'];
        yield 'alias as an item' => ["x:\n  - *a\n", 2822, 'at line 2: YAML aliases'];
        yield 'merge key' => ["<<: *base\n", 2822, 'at line 1: YAML merge keys (<<:) are not supported'];
        yield 'merge key with a space' => ["<< : *base\n", 2822, 'YAML merge keys'];
        yield 'type tag' => ["a: 1\nb: !!str 2\n", 2822, 'at line 2: YAML type tags (!!type) are not supported'];
        yield 'tab indentation' => ["a:\n\tb: 1\n", 2822, 'at line 2: Tab indentation is not allowed'];
        yield 'quoted scalar across lines' => ["a: 1\nb: \"open\n  more\"\n", 2822, 'at line 2: quoted scalars spanning several lines'];
        yield 'unterminated flow collection' => ["a: 1\nb: [x,\n  y\n", 2820, 'unterminated flow collection starting on line 2'];
        yield 'bad indentation' => ["a:\n    b: 1\n  c: 2\n", 2820, "bad indentation at the key 'c'"];
        yield 'unknown escape' => ["a: \"\\q\"\n", 2820, "invalid escape sequence '\\q'"];
        yield 'short hexadecimal escape' => ["a: \"\\x4\"\n", 2820, "invalid escape sequence '\\x': expected 2 hexadecimal digits"];
        yield 'short unicode escape' => ["a: \"\\u12G4\"\n", 2820, "invalid escape sequence '\\u': expected 4 hexadecimal digits"];
        yield 'surrogate escape' => ["a: \"\\uD800\"\n", 2820, 'invalid Unicode scalar value U+D800'];
        yield 'text after a flow collection' => ["a: [1, 2] x\n", 2820, 'unexpected text after a flow collection'];
        yield 'continuation after a flow collection' => ["a: [1]\n  b\n", 2820, 'cannot follow a flow collection or a quoted scalar'];
        yield 'key inside a plain scalar' => ["a: first\n  b: c\n", 2820, "the key 'b' cannot continue a multi-line plain scalar"];
        yield 'key where an item belongs' => ["x:\n  - a\n  b: 1\n", 2820, "expected a sequence item '- ' at indentation 2, got the key 'b'"];
        yield 'item where a key belongs' => ["a: 1\n- b\n", 2820, "expected 'key: value' at indentation 0, got a sequence item"];
    }

    #[DataProvider('failures')]
    public function testRejectsWhatTheSubsetExcludes(string $yaml, int $code, string $message): void
    {
        try {
            $this->load($yaml);
            self::fail('the document must be rejected');
        } catch (LoaderException $exception) {
            self::assertSame($code, $exception->getCode());
            self::assertStringContainsString($message, $exception->getMessage());
        }
    }

    /** @return array<array-key, mixed> */
    private function load(string $yaml): array
    {
        file_put_contents($this->path, $yaml);

        return new YamlLoader()->load($this->path);
    }
}
