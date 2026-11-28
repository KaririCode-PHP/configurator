<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Tests\Conformance;

use KaririCode\Configurator\Exception\ConfigurationException;
use KaririCode\Configurator\Exception\LoaderException;
use KaririCode\Configurator\Loader\YamlLoader;
use PHPUnit\Framework\TestCase;

/**
 * Conformance tests for the YAML config-subset parser.
 *
 * Covers edge cases, unsupported feature detection, and boundary
 * conditions that the basic unit tests do not exercise.
 */
final class YamlEdgeCasesTest extends TestCase
{
    private YamlLoader $loader;
    private string $fixtureDir;

    protected function setUp(): void
    {
        $this->loader = new YamlLoader();
        $this->fixtureDir = sys_get_temp_dir() . '/kariricode_yaml_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->fixtureDir)) {
            return;
        }

        array_map('unlink', glob($this->fixtureDir . '/*') ?: []);
        rmdir($this->fixtureDir);
    }

    // ── Unsupported Feature Detection ─────────────────────────────────

    public function testDetectsAnchor(): void
    {
        $this->writeFixture('anchor.yaml', <<<'YAML'
            defaults: &defaults
              timeout: 30
            YAML);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('anchors');

        $this->loader->load($this->fixtureDir . '/anchor.yaml');
    }

    public function testDetectsAlias(): void
    {
        $this->writeFixture('alias.yaml', <<<'YAML'
            production:
              settings: *defaults
            YAML);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('aliases');

        $this->loader->load($this->fixtureDir . '/alias.yaml');
    }

    public function testDetectsMergeKey(): void
    {
        $this->writeFixture('merge.yaml', <<<'YAML'
            base:
              host: localhost
            production:
              <<: *base
              port: 443
            YAML);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('merge keys');

        $this->loader->load($this->fixtureDir . '/merge.yaml');
    }

    public function testDetectsTypeTag(): void
    {
        $this->writeFixture('tag.yaml', <<<'YAML'
            port: !!int "8080"
            YAML);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('type tags');

        $this->loader->load($this->fixtureDir . '/tag.yaml');
    }

    public function testDetectsTabIndentation(): void
    {
        $this->writeFixture('tabs.yaml', "database:\n\thost: localhost\n");

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Tab indentation');

        $this->loader->load($this->fixtureDir . '/tabs.yaml');
    }

    // ── Valid Edge Cases ──────────────────────────────────────────────

    public function testEmptyFileReturnsEmptyArray(): void
    {
        $this->writeFixture('empty.yaml', '');
        $this->assertSame([], $this->loader->load($this->fixtureDir . '/empty.yaml'));
    }

    public function testCommentOnlyFileReturnsEmptyArray(): void
    {
        $this->writeFixture('comments.yaml', "# This is a comment\n# Another comment\n");
        $this->assertSame([], $this->loader->load($this->fixtureDir . '/comments.yaml'));
    }

    public function testDocumentMarkersAreSkipped(): void
    {
        $this->writeFixture('markers.yaml', "---\nkey: value\n...\n");
        $this->assertSame(['key' => 'value'], $this->loader->load($this->fixtureDir . '/markers.yaml'));
    }

    public function testUtf8BomIsStripped(): void
    {
        $this->writeFixture('bom.yaml', "\xEF\xBB\xBFkey: value\n");
        $this->assertSame(['key' => 'value'], $this->loader->load($this->fixtureDir . '/bom.yaml'));
    }

    public function testDeepNesting(): void
    {
        $yaml = <<<'YAML'
            level1:
              level2:
                level3:
                  level4:
                    value: deep
            YAML;

        $this->writeFixture('deep.yaml', $yaml);
        $result = $this->loader->load($this->fixtureDir . '/deep.yaml');

        $this->assertSame('deep', $result['level1']['level2']['level3']['level4']['value']);
    }

    public function testMixedScalarTypes(): void
    {
        $yaml = <<<'YAML'
            string: hello
            int: 42
            float: 3.14
            bool_true: true
            bool_false: false
            null_tilde: ~
            null_word: null
            hex: 0xff
            YAML;

        $this->writeFixture('scalars.yaml', $yaml);
        $result = $this->loader->load($this->fixtureDir . '/scalars.yaml');

        $this->assertSame('hello', $result['string']);
        $this->assertSame(42, $result['int']);
        $this->assertSame(3.14, $result['float']);
        $this->assertTrue($result['bool_true']);
        $this->assertFalse($result['bool_false']);
        $this->assertNull($result['null_tilde']);
        $this->assertNull($result['null_word']);
        $this->assertSame(255, $result['hex']);
    }

    public function testQuotedStringsPreservedAsStrings(): void
    {
        $yaml = <<<'YAML'
            quoted_true: "true"
            quoted_int: "42"
            quoted_null: "null"
            single_quoted: 'hello world'
            YAML;

        $this->writeFixture('quoted.yaml', $yaml);
        $result = $this->loader->load($this->fixtureDir . '/quoted.yaml');

        $this->assertSame('true', $result['quoted_true']);
        $this->assertSame('42', $result['quoted_int']);
        $this->assertSame('null', $result['quoted_null']);
        $this->assertSame('hello world', $result['single_quoted']);
    }

    public function testFlowSequence(): void
    {
        $yaml = "items: [a, b, c, 1, true]\n";
        $this->writeFixture('flow_seq.yaml', $yaml);
        $result = $this->loader->load($this->fixtureDir . '/flow_seq.yaml');

        $this->assertSame(['a', 'b', 'c', 1, true], $result['items']);
    }

    public function testFlowMapping(): void
    {
        $yaml = "config: {host: localhost, port: 3306}\n";
        $this->writeFixture('flow_map.yaml', $yaml);
        $result = $this->loader->load($this->fixtureDir . '/flow_map.yaml');

        $this->assertSame(['host' => 'localhost', 'port' => 3306], $result['config']);
    }

    public function testBlockSequence(): void
    {
        $yaml = <<<'YAML'
            middleware:
              - auth
              - cors
              - logging
            YAML;

        $this->writeFixture('block_seq.yaml', $yaml);
        $result = $this->loader->load($this->fixtureDir . '/block_seq.yaml');

        $this->assertSame(['auth', 'cors', 'logging'], $result['middleware']);
    }

    public function testLiteralBlockScalar(): void
    {
        $yaml = "description: |\n  Line one\n  Line two\n  Line three\n";
        $this->writeFixture('literal.yaml', $yaml);
        $result = $this->loader->load($this->fixtureDir . '/literal.yaml');

        // Clip chomping (YAML 1.2, section 8.1.1.2): one final line break.
        $this->assertSame("Line one\nLine two\nLine three\n", $result['description']);
    }

    public function testFoldedBlockScalar(): void
    {
        $yaml = "description: >\n  This is a long\n  description that\n  wraps.\n";
        $this->writeFixture('folded.yaml', $yaml);
        $result = $this->loader->load($this->fixtureDir . '/folded.yaml');

        $this->assertSame("This is a long description that wraps.\n", $result['description']);
    }

    public function testInlineCommentStripping(): void
    {
        $yaml = "key: value # this is a comment\nother: 42 # another\n";
        $this->writeFixture('inline_comments.yaml', $yaml);
        $result = $this->loader->load($this->fixtureDir . '/inline_comments.yaml');

        $this->assertSame('value', $result['key']);
        $this->assertSame(42, $result['other']);
    }

    public function testQuotedStringWithHash(): void
    {
        $yaml = "color: \"#ff0000\"\n";
        $this->writeFixture('hash_in_string.yaml', $yaml);
        $result = $this->loader->load($this->fixtureDir . '/hash_in_string.yaml');

        $this->assertSame('#ff0000', $result['color']);
    }

    public function testColonInQuotedValue(): void
    {
        $yaml = "url: \"https://example.com:8080\"\n";
        $this->writeFixture('colon_quoted.yaml', $yaml);
        $result = $this->loader->load($this->fixtureDir . '/colon_quoted.yaml');

        $this->assertSame('https://example.com:8080', $result['url']);
    }

    public function testNestedSequenceOfMappings(): void
    {
        $yaml = <<<'YAML'
            servers:
              -
                host: server1
                port: 80
              -
                host: server2
                port: 443
            YAML;

        $this->writeFixture('seq_of_maps.yaml', $yaml);
        $result = $this->loader->load($this->fixtureDir . '/seq_of_maps.yaml');

        $this->assertCount(2, $result['servers']);
        $this->assertSame('server1', $result['servers'][0]['host']);
        $this->assertSame(443, $result['servers'][1]['port']);
    }

    public function testSpecialFloatValues(): void
    {
        $yaml = <<<'YAML'
            pos_inf: .inf
            neg_inf: -.inf
            not_a_number: .nan
            YAML;

        $this->writeFixture('special_floats.yaml', $yaml);
        $result = $this->loader->load($this->fixtureDir . '/special_floats.yaml');

        $this->assertSame(INF, $result['pos_inf']);
        $this->assertSame(-INF, $result['neg_inf']);
        $this->assertNan($result['not_a_number']);
    }

    public function testBinaryAndOctalIntegers(): void
    {
        // Regression: intval(.., 0) does not recognize 0b/0o prefixes and
        // silently returns 0. These must convert to their real values.
        $yaml = <<<'YAML'
            binary: 0b1010
            octal: 0o17
            neg_binary: -0b101
            hex: 0xff
            leading_zero: 0755
            YAML;

        $this->writeFixture('radix_ints.yaml', $yaml);
        $result = $this->loader->load($this->fixtureDir . '/radix_ints.yaml');

        $this->assertSame(10, $result['binary']);
        $this->assertSame(15, $result['octal']);
        $this->assertSame(-5, $result['neg_binary']);
        $this->assertSame(255, $result['hex']);
        // YAML 1.2: a leading zero is decimal, not octal.
        $this->assertSame(755, $result['leading_zero']);
    }

    public function testEmptyFlowCollections(): void
    {
        $yaml = "empty_list: []\nempty_map: {}\n";
        $this->writeFixture('empty_flow.yaml', $yaml);
        $result = $this->loader->load($this->fixtureDir . '/empty_flow.yaml');

        $this->assertSame([], $result['empty_list']);
        $this->assertSame([], $result['empty_map']);
    }

    public function testNestedFlowCollections(): void
    {
        $yaml = "matrix: [[1, 2], [3, 4]]\n";
        $this->writeFixture('nested_flow.yaml', $yaml);
        $result = $this->loader->load($this->fixtureDir . '/nested_flow.yaml');

        $this->assertSame([[1, 2], [3, 4]], $result['matrix']);
    }

    public function testAsteriskInQuotedStringDoesNotTriggerAliasDetection(): void
    {
        $yaml = "pattern: \"*.log\"\n";
        $this->writeFixture('glob_pattern.yaml', $yaml);
        $result = $this->loader->load($this->fixtureDir . '/glob_pattern.yaml');

        $this->assertSame('*.log', $result['pattern']);
    }

    public function testEnvVarPlaceholderSurvivesParsing(): void
    {
        $yaml = <<<'YAML'
            database:
              host: "${DB_HOST:localhost}"
              port: "${DB_PORT:5432}"
            YAML;

        $this->writeFixture('env_placeholders.yaml', $yaml);
        $result = $this->loader->load($this->fixtureDir . '/env_placeholders.yaml');

        $this->assertSame('${DB_HOST:localhost}', $result['database']['host']);
        $this->assertSame('${DB_PORT:5432}', $result['database']['port']);
    }

    // ── Utility ───────────────────────────────────────────────────────

    // ── Structure Regressions (V4.2 deep review) ──────────────────────

    public function testSequenceOfMappingsWithInlineFirstKey(): void
    {
        $result = $this->parseYaml("servers:\n  - host: a\n    port: 1\n  - host: b\n    port: 2\n");

        $this->assertSame([['host' => 'a', 'port' => 1], ['host' => 'b', 'port' => 2]], $result['servers']);
    }

    public function testSequenceAtParentKeyIndentation(): void
    {
        $result = $this->parseYaml("middleware:\n- auth\n- cors\nnext: 1\n");

        $this->assertSame(['middleware' => ['auth', 'cors'], 'next' => 1], $result);
    }

    public function testNestedSequenceInsideMappingItem(): void
    {
        $result = $this->parseYaml("jobs:\n  - name: build\n    steps:\n      - checkout\n      - test\n");

        $this->assertSame([['name' => 'build', 'steps' => ['checkout', 'test']]], $result['jobs']);
    }

    public function testSequenceOfSequencesOnDashLine(): void
    {
        $result = $this->parseYaml("m:\n  - - 1\n    - 2\n  - - 3\n");

        $this->assertSame([[1, 2], [3]], $result['m']);
    }

    public function testTopLevelSequence(): void
    {
        $this->assertSame(['a', ['b' => 1, 'c' => 2]], $this->parseYaml("- a\n- b: 1\n  c: 2\n"));
    }

    public function testIndentedCommentIsNotAKey(): void
    {
        $this->assertSame(['a' => ['b' => 1]], $this->parseYaml("a:\n  # note: not a key\n  b: 1\n"));
    }

    // ── Block Scalar Regressions ──────────────────────────────────────

    public function testLiteralBlockKeepsBlankLinesAndHashContent(): void
    {
        $result = $this->parseYaml("script: |\n  #!/bin/bash\n\n  echo hi\nnext: x\n");

        $this->assertSame("#!/bin/bash\n\necho hi\n", $result['script']);
        $this->assertSame('x', $result['next']);
    }

    public function testStripChompingIndicators(): void
    {
        $result = $this->parseYaml("a: |-\n  keep\n  this\nb: >-\n  one\n  two\n");

        $this->assertSame("keep\nthis", $result['a']);
        $this->assertSame('one two', $result['b']);
    }

    public function testKeepChompingIndicator(): void
    {
        $this->assertSame("x\n\n", $this->parseYaml("a: |+\n  x\n\nb: 1\n")['a']);
    }

    public function testExplicitIndentationIndicator(): void
    {
        $this->assertSame("  indented\nbase\n", $this->parseYaml("a: |2\n    indented\n  base\n")['a']);
    }

    public function testFoldedBlockKeepsParagraphs(): void
    {
        $this->assertSame("p1 l1 p1 l2\np2\n", $this->parseYaml("a: >\n  p1 l1\n  p1 l2\n\n  p2\n")['a']);
    }

    // ── Scalar and Flow Regressions ───────────────────────────────────

    public function testEscapedBackslashBeforeClosingQuote(): void
    {
        $result = $this->parseYaml("paths: [\"C:\\\\\", \"D:\\\\\"]\np: \"C:\\\\\" # win\n");

        $this->assertSame(['C:\\', 'D:\\'], $result['paths']);
        $this->assertSame('C:\\', $result['p']);
    }

    public function testDoubleQuotedUnicodeEscapes(): void
    {
        $this->assertSame('café A 🔐', $this->parseYaml('a: "caf\\u00e9 \\x41 \\U0001F510"' . "\n")['a']);
    }

    public function testApostropheInPlainScalarDoesNotHideComment(): void
    {
        $this->assertSame("it's fine", $this->parseYaml("msg: it's fine # c\n")['msg']);
    }

    public function testMultiLineFlowCollection(): void
    {
        $this->assertSame([80, 443], $this->parseYaml("ports: [\n  80,\n  443, # https\n]\n")['ports']);
    }

    public function testMultiLinePlainScalarIsFolded(): void
    {
        $result = $this->parseYaml("desc: this is a long\n  description text\nnext: 1\n");

        $this->assertSame('this is a long description text', $result['desc']);
    }

    // ── Failing Loudly ────────────────────────────────────────────────

    public function testOrphanIndentationIsRejected(): void
    {
        $this->expectException(LoaderException::class);
        $this->expectExceptionCode(1102);

        $this->parseYaml("a: 1\n  b: 2\n");
    }

    public function testMultiLineQuotedScalarIsUnsupported(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionCode(1007);

        $this->parseYaml("a: \"first\n  second\"\n");
    }

    public function testUnsupportedFeatureKeepsItsErrorCode(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionCode(1007);

        $this->parseYaml("defaults: &d\n  t: 1\n");
    }

    /**
     * @return array<array-key, mixed>
     */
    private function parseYaml(string $yaml): array
    {
        $this->writeFixture('case.yaml', $yaml);

        return $this->loader->load($this->fixtureDir . '/case.yaml');
    }

    private function writeFixture(string $filename, string $content): void
    {
        if (!is_dir($this->fixtureDir)) {
            mkdir($this->fixtureDir, 0755, true);
        }

        file_put_contents($this->fixtureDir . '/' . $filename, $content);
    }
}
