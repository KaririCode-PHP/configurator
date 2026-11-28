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

    // ── supports() ────────────────────────────────────────────────────

    public function testSupportsKnownPrefixes(): void
    {
        $this->assertTrue($this->processor->supports('int:8080'));
        $this->assertTrue($this->processor->supports('float:3.14'));
        $this->assertTrue($this->processor->supports('bool:true'));
        $this->assertTrue($this->processor->supports('json:{"a":1}'));
        $this->assertTrue($this->processor->supports('csv:a,b,c'));
        $this->assertTrue($this->processor->supports('base64:dGVzdA=='));
        $this->assertTrue($this->processor->supports('trim:  hello  '));
        $this->assertTrue($this->processor->supports('lower:HELLO'));
        $this->assertTrue($this->processor->supports('upper:hello'));
    }

    public function testDoesNotSupportUnknownPrefixes(): void
    {
        $this->assertFalse($this->processor->supports('unknown:value'));
        $this->assertFalse($this->processor->supports('localhost:3306'));
        $this->assertFalse($this->processor->supports('no_colon'));
    }

    public function testDoesNotSupportNonStrings(): void
    {
        $this->assertFalse($this->processor->supports(42));
        $this->assertFalse($this->processor->supports(null));
    }

    // ── int ───────────────────────────────────────────────────────────

    public function testCastsToInt(): void
    {
        $this->assertSame(8080, $this->processor->process('int:8080'));
        $this->assertSame(0, $this->processor->process('int:0'));
        $this->assertSame(-1, $this->processor->process('int:-1'));
    }

    // ── float ─────────────────────────────────────────────────────────

    public function testCastsToFloat(): void
    {
        $this->assertSame(3.14, $this->processor->process('float:3.14'));
        $this->assertSame(0.0, $this->processor->process('float:0'));
    }

    // ── bool ──────────────────────────────────────────────────────────

    public function testCastsToBoolTrue(): void
    {
        $this->assertTrue($this->processor->process('bool:true'));
        $this->assertTrue($this->processor->process('bool:1'));
        $this->assertTrue($this->processor->process('bool:yes'));
        $this->assertTrue($this->processor->process('bool:on'));
    }

    public function testCastsToBoolFalse(): void
    {
        $this->assertFalse($this->processor->process('bool:false'));
        $this->assertFalse($this->processor->process('bool:0'));
        $this->assertFalse($this->processor->process('bool:no'));
        $this->assertFalse($this->processor->process('bool:off'));
    }

    public function testBoolCastThrowsOnInvalidValue(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->processor->process('bool:maybe');
    }

    // ── json ──────────────────────────────────────────────────────────

    public function testCastsJsonToArray(): void
    {
        $this->assertSame(['a' => 1, 'b' => 2], $this->processor->process('json:{"a":1,"b":2}'));
    }

    public function testJsonCastThrowsOnInvalidJson(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->processor->process('json:{invalid}');
    }

    // ── csv ───────────────────────────────────────────────────────────

    public function testCastsCsvToArray(): void
    {
        $this->assertSame(['a', 'b', 'c'], $this->processor->process('csv:a,b,c'));
    }

    public function testCsvTrimsWhitespace(): void
    {
        $this->assertSame(['a', 'b', 'c'], $this->processor->process('csv: a , b , c '));
    }

    public function testCsvEmptyReturnsEmptyArray(): void
    {
        $this->assertSame([], $this->processor->process('csv:'));
    }

    // ── base64 ────────────────────────────────────────────────────────

    public function testDecodesBase64(): void
    {
        $this->assertSame('test', $this->processor->process('base64:dGVzdA=='));
    }

    public function testBase64ThrowsOnInvalidEncoding(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->processor->process('base64:!!!invalid!!!');
    }

    // ── String transforms ─────────────────────────────────────────────

    public function testTrimsValue(): void
    {
        $this->assertSame('hello', $this->processor->process('trim:  hello  '));
    }

    public function testLowercasesValue(): void
    {
        $this->assertSame('hello world', $this->processor->process('lower:HELLO WORLD'));
    }

    public function testUppercasesValue(): void
    {
        $this->assertSame('HELLO WORLD', $this->processor->process('upper:hello world'));
    }

    // ── Custom caster ─────────────────────────────────────────────────

    public function testCustomCasterRegistration(): void
    {
        $this->processor->registerCaster(
            'hex',
            static fn (string $v): int => hexdec($v),
        );

        $this->assertTrue($this->processor->supports('hex:ff'));
        $this->assertSame(255, $this->processor->process('hex:ff'));
    }

    // ── Pass-through ──────────────────────────────────────────────────

    public function testNonStringPassesThrough(): void
    {
        $this->assertSame(42, $this->processor->process(42));
    }
}
