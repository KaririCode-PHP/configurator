<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Integration;

use KaririCode\Configurator\Configuration;
use KaririCode\Configurator\ConfigurationBuilder;
use KaririCode\Configurator\Contract\Configurator;

/**
 * Factory for registering Configuration in PSR-11 containers.
 *
 * Provides static factory methods that return closures suitable
 * for container registration. Decouples the Configurator from
 * any specific DI container implementation.
 *
 * Usage with KaririCode\DependencyInjection:
 *   $container->register(Configurator::class, Psr11Bridge::factory('/app/config'));
 *
 * Usage with any PSR-11 container:
 *   $factory = Psr11Bridge::factory('/app/config', 'production');
 *   $container->set(Configurator::class, $factory);
 *
 * @package KaririCode\Configurator
 * @since   4.0.0 ARFA 1.3
 */
final class Psr11Bridge
{
    /**
     * Returns a closure that builds a sealed Configuration.
     *
     * @param string      $configDir   Base config directory
     * @param string|null $environment Optional environment for cascade loading
     *
     * @return \Closure(): Configuration
     */
    public static function factory(string $configDir, ?string $environment = null): \Closure
    {
        return static function () use ($configDir, $environment): Configuration {
            $builder = ConfigurationBuilder::create()
                ->withDirectory($configDir);

            if ($environment !== null) {
                $builder->withEnvironment($environment);
            }

            return $builder->build();
        };
    }
}
