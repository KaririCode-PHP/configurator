<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Integration;

use KaririCode\Configurator\ConfigurationBuilder;
use KaririCode\Contract\Environment\EnvironmentResolver;

/**
 * Convenience bridge for KaririCode\Dotenv integration.
 *
 * Encapsulates the Dotenv → Configurator wiring pattern so that
 * projects using both packages have a single, discoverable entry point.
 *
 * Usage:
 *   $dotenv = new \KaririCode\Dotenv\Dotenv('/app');
 *   $dotenv->load();
 *
 *   $config = DotenvBridge::configure($dotenv)
 *       ->withDirectory('/app/config')
 *       ->withEnvironment('production')
 *       ->build();
 *
 * This is equivalent to:
 *   ConfigurationBuilder::create()
 *       ->withDotenv($dotenv)
 *       ->withDirectory('/app/config')
 *       ->withEnvironment('production')
 *       ->build();
 *
 * @package KaririCode\Configurator
 * @since   4.2.0 ARFA 1.3
 */
final class DotenvBridge
{
    /**
     * Creates a pre-configured builder with Dotenv environment resolution.
     *
     * @param object|EnvironmentResolver $dotenv     Dotenv instance or resolver
     * @param bool                                $strict     Throw on undefined vars
     * @param bool                                $systemFallback Fall back to system env
     */
    public static function configure(
        object $dotenv,
        bool $strict = true,
        bool $systemFallback = true,
    ): ConfigurationBuilder {
        return ConfigurationBuilder::create()
            ->withDotenv($dotenv, $strict, $systemFallback);
    }
}
