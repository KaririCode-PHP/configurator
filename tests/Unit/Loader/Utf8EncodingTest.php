<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Tests\Unit\Loader;

use KaririCode\Configurator\Exception\LoaderException;
use KaririCode\Configurator\Loader\Utf8Encoding;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Utf8EncodingTest extends TestCase
{
    /**
     * The first and last code point of each UTF-8 length, around the
     * surrogates, and values whose continuation bytes use all six bits.
     *
     * @return iterable<string, array{int, string}>
     */
    public static function scalars(): iterable
    {
        yield 'U+0000' => [0x0, "\x00"];
        yield 'U+007F' => [0x7F, "\x7F"];
        yield 'U+0080' => [0x80, "\xC2\x80"];
        yield 'U+07FF' => [0x7FF, "\xDF\xBF"];
        yield 'U+0800' => [0x800, "\xE0\xA0\x80"];
        yield 'U+D7FF' => [0xD7FF, "\xED\x9F\xBF"];
        yield 'U+E000' => [0xE000, "\xEE\x80\x80"];
        yield 'U+FFFF' => [0xFFFF, "\xEF\xBF\xBF"];
        yield 'U+10000' => [0x10000, "\xF0\x90\x80\x80"];
        yield 'U+10FFFF' => [0x10FFFF, "\xF4\x8F\xBF\xBF"];
        yield 'U+1F600' => [0x1F600, "\xF0\x9F\x98\x80"];
    }

    #[DataProvider('scalars')]
    public function testEncodesEveryLengthOfUtf8(int $codePoint, string $utf8): void
    {
        self::assertSame(bin2hex($utf8), bin2hex(self::encoder()->encode($codePoint)));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function nonScalars(): iterable
    {
        yield 'negative' => [-1];
        yield 'first surrogate' => [0xD800];
        yield 'last surrogate' => [0xDFFF];
        yield 'past U+10FFFF' => [0x110000];
    }

    #[DataProvider('nonScalars')]
    public function testRejectsWhatIsNotAUnicodeScalarValue(int $codePoint): void
    {
        $this->expectException(LoaderException::class);
        $this->expectExceptionCode(2820);

        self::encoder()->encode($codePoint);
    }

    private static function encoder(): Utf8Encoder
    {
        return new Utf8Encoder();
    }
}
