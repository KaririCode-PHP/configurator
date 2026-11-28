<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Tests\Unit\Environment;

use KaririCode\Configurator\Environment\CompositeEnvironmentResolver;
use KaririCode\Configurator\Environment\InMemoryEnvironmentResolver;
use KaririCode\Configurator\Environment\SystemEnvironmentResolver;
use KaririCode\Contract\Environment\EnvironmentResolver;
use PHPUnit\Framework\TestCase;

final class EnvironmentResolverTest extends TestCase
{
    // ── InMemoryEnvironmentResolver ───────────────────────────────────

    public function testInMemoryGetReturnsSetValue(): void
    {
        $resolver = new InMemoryEnvironmentResolver(['KEY' => 'value']);

        self::assertSame('value', $resolver->get('KEY'));
    }

    public function testInMemoryGetReturnsNullForMissing(): void
    {
        $resolver = new InMemoryEnvironmentResolver();

        self::assertNull($resolver->get('MISSING'));
    }

    public function testInMemoryHasReturnsCorrectly(): void
    {
        $resolver = new InMemoryEnvironmentResolver(['EXISTS' => 'yes']);

        self::assertTrue($resolver->has('EXISTS'));
        self::assertFalse($resolver->has('NOT_EXISTS'));
    }

    public function testInMemorySetAddsVariable(): void
    {
        $resolver = new InMemoryEnvironmentResolver();
        $resolver->set('NEW_KEY', 'new_value');

        self::assertSame('new_value', $resolver->get('NEW_KEY'));
    }

    public function testInMemoryRemoveDeletesVariable(): void
    {
        $resolver = new InMemoryEnvironmentResolver(['KEY' => 'value']);
        $resolver->remove('KEY');

        self::assertNull($resolver->get('KEY'));
        self::assertFalse($resolver->has('KEY'));
    }

    public function testInMemoryEmptyStringIsValid(): void
    {
        $resolver = new InMemoryEnvironmentResolver(['EMPTY' => '']);

        self::assertTrue($resolver->has('EMPTY'));
        self::assertSame('', $resolver->get('EMPTY'));
    }

    public function testInMemoryAllReturnsAllVariables(): void
    {
        $vars = ['A' => '1', 'B' => '2'];
        $resolver = new InMemoryEnvironmentResolver($vars);

        self::assertSame($vars, $resolver->all());
    }

    // ── SystemEnvironmentResolver ─────────────────────────────────────

    public function testSystemResolverReadsFromEnvSuperglobal(): void
    {
        $_ENV['KCODE_TEST_VAR'] = 'from_env';
        $resolver = new SystemEnvironmentResolver();

        try {
            self::assertSame('from_env', $resolver->get('KCODE_TEST_VAR'));
            self::assertTrue($resolver->has('KCODE_TEST_VAR'));
        } finally {
            unset($_ENV['KCODE_TEST_VAR']);
        }
    }

    public function testSystemResolverReadsFromServerSuperglobal(): void
    {
        $_SERVER['KCODE_SERVER_VAR'] = 'from_server';
        $resolver = new SystemEnvironmentResolver();

        try {
            self::assertSame('from_server', $resolver->get('KCODE_SERVER_VAR'));
        } finally {
            unset($_SERVER['KCODE_SERVER_VAR']);
        }
    }

    public function testSystemResolverReturnsNullForMissing(): void
    {
        $resolver = new SystemEnvironmentResolver();

        self::assertNull($resolver->get('KCODE_VERY_UNLIKELY_VAR_12345'));
        self::assertFalse($resolver->has('KCODE_VERY_UNLIKELY_VAR_12345'));
    }

    public function testSystemResolverPrioritizesEnvOverServer(): void
    {
        $_ENV['KCODE_PRIORITY_VAR'] = 'from_env';
        $_SERVER['KCODE_PRIORITY_VAR'] = 'from_server';
        $resolver = new SystemEnvironmentResolver();

        try {
            self::assertSame('from_env', $resolver->get('KCODE_PRIORITY_VAR'));
        } finally {
            unset($_ENV['KCODE_PRIORITY_VAR'], $_SERVER['KCODE_PRIORITY_VAR']);
        }
    }

    // ── CompositeEnvironmentResolver ──────────────────────────────────

    public function testCompositeResolvesFromFirstResolver(): void
    {
        $primary = new InMemoryEnvironmentResolver(['KEY' => 'primary']);
        $fallback = new InMemoryEnvironmentResolver(['KEY' => 'fallback']);

        $composite = new CompositeEnvironmentResolver($primary, $fallback);

        self::assertSame('primary', $composite->get('KEY'));
    }

    public function testCompositeFallsBackToSecondResolver(): void
    {
        $primary = new InMemoryEnvironmentResolver([]);
        $fallback = new InMemoryEnvironmentResolver(['KEY' => 'fallback']);

        $composite = new CompositeEnvironmentResolver($primary, $fallback);

        self::assertSame('fallback', $composite->get('KEY'));
    }

    public function testCompositeReturnsNullWhenNoneResolves(): void
    {
        $r1 = new InMemoryEnvironmentResolver([]);
        $r2 = new InMemoryEnvironmentResolver([]);

        $composite = new CompositeEnvironmentResolver($r1, $r2);

        self::assertNull($composite->get('MISSING'));
        self::assertFalse($composite->has('MISSING'));
    }

    public function testCompositeHasChecksAllResolvers(): void
    {
        $primary = new InMemoryEnvironmentResolver([]);
        $fallback = new InMemoryEnvironmentResolver(['ONLY_IN_FALLBACK' => 'yes']);

        $composite = new CompositeEnvironmentResolver($primary, $fallback);

        self::assertTrue($composite->has('ONLY_IN_FALLBACK'));
    }

    public function testCompositeThreeResolverChain(): void
    {
        $r1 = new InMemoryEnvironmentResolver(['A' => 'from_r1']);
        $r2 = new InMemoryEnvironmentResolver(['B' => 'from_r2']);
        $r3 = new InMemoryEnvironmentResolver(['C' => 'from_r3']);

        $composite = new CompositeEnvironmentResolver($r1, $r2, $r3);

        self::assertSame('from_r1', $composite->get('A'));
        self::assertSame('from_r2', $composite->get('B'));
        self::assertSame('from_r3', $composite->get('C'));
        self::assertNull($composite->get('D'));
    }

    public function testCompositeWithNoResolvers(): void
    {
        $composite = new CompositeEnvironmentResolver();

        self::assertNull($composite->get('ANY'));
        self::assertFalse($composite->has('ANY'));
    }
}
