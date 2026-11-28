<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Tests\Unit\Processor;

use KaririCode\Configurator\Exception\ConfigurationException;
use KaririCode\Configurator\Processor\TypeCastProcessor;
use PHPUnit\Framework\TestCase;

final class TypeCastProcessorTest extends TestCase
{
    private TypeCastProcessor $processor;

    protected function setUp(): void
    {
        $this->processor = new TypeCastProcessor();
    }

    // ── supports() ───────────────────────────────────────────────────────

    public function testSupportsKnownPrefixes(): void
    {
        self::assertTrue($this->processor->supports('int:8080'));
        self::assertTrue($this->processor->supports('float:3.14'));
        self::assertTrue($this->processor->supports('bool:true'));
        self::assertTrue($this->processor->supports('json:{"a":1}'));
        self::assertTrue($this->processor->supports('csv:a,b,c'));
        self::assertTrue($this->processor->supports('base64:dGVzdA=='));
        self::assertTrue($this->processor->supports('trim:  hello  '));
        self::assertTrue($this->processor->supports('lower:HELLO'));
        self::assertTrue($this->processor->supports('upper:hello'));
    }

    public function testDoesNotSupportUnknownPrefixes(): void
    {
        self::assertFalse($this->processor->supports('unknown:value'));
        self::assertFalse($this->processor->supports('localhost:3306'));
        self::assertFalse($this->processor->supports('no_colon'));
    }

    public function testDoesNotSupportNonStrings(): void
    {
        self::assertFalse($this->processor->supports(42));
        self::assertFalse($this->processor->supports(null));
    }

    // ── int ──────────────────────────────────────────────────────────────

    public function testCastsToInt(): void
    {
        self::assertSame(8080, $this->processor->process('int:8080'));
        self::assertSame(0, $this->processor->process('int:0'));
        self::assertSame(-1, $this->processor->process('int:-1'));
        self::assertSame(42, $this->processor->process('int:+42'));
        self::assertSame(PHP_INT_MAX, $this->processor->process('int:' . PHP_INT_MAX));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNumbers(): iterable
    {
        yield 'int of text' => ['int:abc'];
        yield 'int of nothing' => ['int:'];
        yield 'int with trailing text' => ['int:8080x'];
        yield 'int of a fraction' => ['int:1.5'];
        yield 'int beyond 64 bits' => ['int:9223372036854775808'];
        yield 'float of text' => ['float:abc'];
        yield 'float of nothing' => ['float:'];
        yield 'float with a comma' => ['float:1,5'];
    }

    /** Spec V4.3.3 §8.3: a cast that does not apply fails instead of becoming 0. */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidNumbers')]
    public function testNumericCastsRejectWhatIsNotANumber(string $value): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionCode(2831);

        $this->processor->process($value);
    }

    public function testAPrefixMustHaveAName(): void
    {
        $this->processor->registerCaster('', static fn(string $v): string => 'cast');

        self::assertFalse($this->processor->supports(':value'));
    }

    public function testBooleanWordsAreCaseInsensitiveAndEmptyIsFalse(): void
    {
        self::assertTrue($this->processor->process('bool:TRUE'));
        self::assertTrue($this->processor->process('bool:On'));
        self::assertFalse($this->processor->process('bool:'));
    }

    public function testJsonDecodesUpToItsDepthLimit(): void
    {
        self::assertIsArray($this->processor->process('json:' . str_repeat('[', 511) . str_repeat(']', 511)));

        $this->expectException(ConfigurationException::class);

        $this->processor->process('json:' . str_repeat('[', 512) . str_repeat(']', 512));
    }

    public function testFileReadsAndTrimsAReadableFile(): void
    {
        $path = sys_get_temp_dir() . '/kariricode-config-file-' . bin2hex(random_bytes(4));
        file_put_contents($path, "  token-value \n");

        try {
            self::assertSame('token-value', $this->processor->process('file:' . $path));
        } finally {
            unlink($path);
        }
    }

    public function testFileRejectsADirectoryAndAMissingPath(): void
    {
        foreach ([sys_get_temp_dir(), sys_get_temp_dir() . '/kariricode-missing-' . bin2hex(random_bytes(4))] as $path) {
            try {
                $this->processor->process('file:' . $path);
                self::fail('not a readable file: ' . $path);
            } catch (ConfigurationException $exception) {
                self::assertSame(2831, $exception->getCode());
            }
        }
    }

    // ── float ────────────────────────────────────────────────────────────

    public function testCastsToFloat(): void
    {
        self::assertSame(3.14, $this->processor->process('float:3.14'));
        self::assertSame(0.0, $this->processor->process('float:0'));
        self::assertSame(1000.0, $this->processor->process('float:1e3'));
        self::assertSame(-0.5, $this->processor->process('float:-0.5'));
    }

    // ── bool ─────────────────────────────────────────────────────────────

    public function testCastsToBoolTrue(): void
    {
        self::assertTrue($this->processor->process('bool:true'));
        self::assertTrue($this->processor->process('bool:1'));
        self::assertTrue($this->processor->process('bool:yes'));
        self::assertTrue($this->processor->process('bool:on'));
    }

    public function testCastsToBoolFalse(): void
    {
        self::assertFalse($this->processor->process('bool:false'));
        self::assertFalse($this->processor->process('bool:0'));
        self::assertFalse($this->processor->process('bool:no'));
        self::assertFalse($this->processor->process('bool:off'));
    }

    public function testBoolCastThrowsOnInvalidValue(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->processor->process('bool:maybe');
    }

    // ── json ─────────────────────────────────────────────────────────────

    public function testCastsJsonToArray(): void
    {
        self::assertSame(['a' => 1, 'b' => 2], $this->processor->process('json:{"a":1,"b":2}'));
    }

    public function testJsonCastThrowsOnInvalidJson(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->processor->process('json:{invalid}');
    }

    // ── csv ──────────────────────────────────────────────────────────────

    public function testCastsCsvToArray(): void
    {
        self::assertSame(['a', 'b', 'c'], $this->processor->process('csv:a,b,c'));
    }

    public function testCsvTrimsWhitespace(): void
    {
        self::assertSame(['a', 'b', 'c'], $this->processor->process('csv: a , b , c '));
    }

    public function testCsvEmptyReturnsEmptyArray(): void
    {
        self::assertSame([], $this->processor->process('csv:'));
    }

    // ── base64 ───────────────────────────────────────────────────────────

    public function testDecodesBase64(): void
    {
        self::assertSame('test', $this->processor->process('base64:dGVzdA=='));
    }

    public function testBase64ThrowsOnInvalidEncoding(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->processor->process('base64:!!!invalid!!!');
    }

    // ── String transforms ────────────────────────────────────────────────

    public function testTrimsValue(): void
    {
        self::assertSame('hello', $this->processor->process('trim:  hello  '));
    }

    public function testLowercasesValue(): void
    {
        self::assertSame('hello world', $this->processor->process('lower:HELLO WORLD'));
    }

    public function testUppercasesValue(): void
    {
        self::assertSame('HELLO WORLD', $this->processor->process('upper:hello world'));
    }

    // ── Custom caster ────────────────────────────────────────────────────

    public function testCustomCasterRegistration(): void
    {
        $this->processor->registerCaster(
            'hex',
            static fn(string $v): int => (int) hexdec($v),
        );

        self::assertTrue($this->processor->supports('hex:ff'));
        self::assertSame(255, $this->processor->process('hex:ff'));
    }

    // ── Pass-through ─────────────────────────────────────────────────────

    public function testNonStringPassesThrough(): void
    {
        self::assertSame(42, $this->processor->process(42));
    }

    public function testStringsWithoutAKnownPrefixPassThrough(): void
    {
        self::assertSame('plain', $this->processor->process('plain'));
        self::assertSame('https://example.org', $this->processor->process('https://example.org'));
    }
}
