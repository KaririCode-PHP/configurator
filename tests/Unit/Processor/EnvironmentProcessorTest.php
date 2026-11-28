<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Tests\Unit\Processor;

use KaririCode\Configurator\Environment\InMemoryEnvironmentResolver;
use KaririCode\Configurator\Exception\ConfigurationException;
use KaririCode\Configurator\Processor\EnvironmentProcessor;
use PHPUnit\Framework\TestCase;

final class EnvironmentProcessorTest extends TestCase
{
    private InMemoryEnvironmentResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new InMemoryEnvironmentResolver([
            'DB_HOST' => 'db.prod.example.com',
            'DB_PORT' => '5432',
            'DB_PASSWORD' => 's3cr3t',
            'APP_ENV' => 'production',
            'EMPTY_VAR' => '',
        ]);
    }

    // ── supports() ────────────────────────────────────────────────────

    public function testSupportsStringWithDollarBrace(): void
    {
        $processor = new EnvironmentProcessor($this->resolver);

        $this->assertTrue($processor->supports('${DB_HOST}'));
        $this->assertTrue($processor->supports('host: ${DB_HOST}'));
    }

    public function testDoesNotSupportNonStrings(): void
    {
        $processor = new EnvironmentProcessor($this->resolver);

        $this->assertFalse($processor->supports(42));
        $this->assertFalse($processor->supports(true));
        $this->assertFalse($processor->supports(null));
        $this->assertFalse($processor->supports([]));
    }

    public function testDoesNotSupportStringWithoutPlaceholder(): void
    {
        $processor = new EnvironmentProcessor($this->resolver);

        $this->assertFalse($processor->supports('localhost'));
        $this->assertFalse($processor->supports('$DB_HOST'));
        $this->assertFalse($processor->supports('{DB_HOST}'));
    }

    // ── Simple interpolation ──────────────────────────────────────────

    public function testResolvesSingleVariable(): void
    {
        $processor = new EnvironmentProcessor($this->resolver);

        $this->assertSame('db.prod.example.com', $processor->process('${DB_HOST}'));
    }

    public function testResolvesMultipleVariablesInString(): void
    {
        $processor = new EnvironmentProcessor($this->resolver);

        $this->assertSame(
            'pgsql://db.prod.example.com:5432',
            $processor->process('pgsql://${DB_HOST}:${DB_PORT}'),
        );
    }

    public function testPreservesTextAroundVariable(): void
    {
        $processor = new EnvironmentProcessor($this->resolver);

        $this->assertSame(
            'host=db.prod.example.com;port=5432',
            $processor->process('host=${DB_HOST};port=${DB_PORT}'),
        );
    }

    // ── Default values ────────────────────────────────────────────────

    public function testUsesDefaultWhenVariableUndefined(): void
    {
        $processor = new EnvironmentProcessor($this->resolver, strict: false);

        $this->assertSame('localhost', $processor->process('${UNDEFINED_VAR:localhost}'));
    }

    public function testBashStyleDefaultWithDash(): void
    {
        $processor = new EnvironmentProcessor($this->resolver, strict: false);

        $this->assertSame('fallback', $processor->process('${UNDEFINED_VAR:-fallback}'));
    }

    public function testDefinedVariableIgnoresDefault(): void
    {
        $processor = new EnvironmentProcessor($this->resolver);

        $this->assertSame('db.prod.example.com', $processor->process('${DB_HOST:localhost}'));
    }

    public function testEmptyDefaultIsValid(): void
    {
        $processor = new EnvironmentProcessor($this->resolver, strict: false);

        $this->assertSame('', $processor->process('${MISSING:}'));
    }

    // ── Strict mode ───────────────────────────────────────────────────

    public function testStrictModeThrowsOnUndefinedVariable(): void
    {
        $processor = new EnvironmentProcessor($this->resolver, strict: true);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('UNDEFINED_VAR');

        $processor->process('${UNDEFINED_VAR}');
    }

    public function testStrictModeAllowsDefaultValues(): void
    {
        $processor = new EnvironmentProcessor($this->resolver, strict: true);

        $this->assertSame('fallback', $processor->process('${UNDEFINED_VAR:fallback}'));
    }

    public function testNonStrictReturnsEmptyForUndefined(): void
    {
        $processor = new EnvironmentProcessor($this->resolver, strict: false);

        $this->assertSame('', $processor->process('${UNDEFINED_VAR}'));
    }

    // ── Nested defaults ───────────────────────────────────────────────

    public function testNestedDefaultFallback(): void
    {
        $processor = new EnvironmentProcessor($this->resolver, strict: false);

        // ${MISSING:${ALSO_MISSING:final_default}}
        $this->assertSame(
            'final_default',
            $processor->process('${MISSING:${ALSO_MISSING:final_default}}'),
        );
    }

    public function testNestedDefaultResolvesFirstAvailable(): void
    {
        $processor = new EnvironmentProcessor($this->resolver);

        // ${MISSING:${DB_HOST}} → resolves MISSING fails, then DB_HOST resolves
        $this->assertSame(
            'db.prod.example.com',
            $processor->process('${MISSING:${DB_HOST}}'),
        );
    }

    public function testTripleNestedDefault(): void
    {
        $processor = new EnvironmentProcessor($this->resolver, strict: false);

        // ${A:${B:${C:deep}}} → all undefined → "deep"
        $this->assertSame(
            'deep',
            $processor->process('${A:${B:${C:deep}}}'),
        );
    }

    public function testTripleNestedResolvesMiddle(): void
    {
        $processor = new EnvironmentProcessor($this->resolver);

        // ${MISSING:${DB_HOST:${C:deep}}} → DB_HOST resolves
        $this->assertSame(
            'db.prod.example.com',
            $processor->process('${MISSING:${DB_HOST:${C:deep}}}'),
        );
    }

    public function testNestedWithTextAround(): void
    {
        $processor = new EnvironmentProcessor($this->resolver, strict: false);

        $this->assertSame(
            'host=fallback;port=5432',
            $processor->process('host=${MISSING:fallback};port=${DB_PORT}'),
        );
    }

    public function testNestedDefaultWithSurroundingText(): void
    {
        $processor = new EnvironmentProcessor($this->resolver, strict: false);

        $this->assertSame(
            'prefix_final_default_suffix',
            $processor->process('prefix_${A:${B:final_default}}_suffix'),
        );
    }

    // ── Edge cases ────────────────────────────────────────────────────

    public function testEmptyVariableReturnsEmptyString(): void
    {
        $processor = new EnvironmentProcessor($this->resolver);

        $this->assertSame('', $processor->process('${EMPTY_VAR}'));
    }

    public function testNonStringInputPassesThrough(): void
    {
        $processor = new EnvironmentProcessor($this->resolver);

        $this->assertSame(42, $processor->process(42));
    }

    public function testDefaultsToSystemEnvironment(): void
    {
        // Constructor without resolver should create SystemEnvironmentResolver
        $processor = new EnvironmentProcessor(strict: false);

        // Should not throw — uses system resolver
        $result = $processor->process('${VERY_UNLIKELY_VAR_NAME_12345}');
        $this->assertSame('', $result);
    }

    public function testAdjacentPlaceholders(): void
    {
        $processor = new EnvironmentProcessor($this->resolver);

        $this->assertSame(
            'db.prod.example.com5432',
            $processor->process('${DB_HOST}${DB_PORT}'),
        );
    }

    public function testUnbalancedBraceOutputsLiteral(): void
    {
        $processor = new EnvironmentProcessor($this->resolver, strict: false);

        // Unbalanced — no closing brace → literal output
        $this->assertSame('${UNCLOSED', $processor->process('${UNCLOSED'));
    }

    public function testDollarSignWithoutBraceIsLiteral(): void
    {
        $processor = new EnvironmentProcessor($this->resolver);

        $this->assertSame('price is $5', $processor->process('price is $5'));
    }

    public function testEmptyPlaceholderNonStrict(): void
    {
        $processor = new EnvironmentProcessor($this->resolver, strict: false);

        // ${} — empty name, resolver returns null
        $this->assertSame('', $processor->process('${}'));
    }

    public function testResolvedValueContainingColon(): void
    {
        $resolver = new InMemoryEnvironmentResolver([
            'DSN' => 'pgsql:host=localhost:5432',
        ]);
        $processor = new EnvironmentProcessor($resolver);

        $this->assertSame(
            'pgsql:host=localhost:5432',
            $processor->process('${DSN}'),
        );
    }
}
