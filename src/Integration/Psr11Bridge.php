<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Integration;

use KaririCode\Configurator\Configuration;
use KaririCode\Configurator\ConfigurationBuilder;
use KaririCode\Configurator\Contract\Configurator;

/**
 * Hands a PSR-11 container a factory for the application configuration,
 * so the configurator stays independent of any container: the closure
 * builds a sealed Configuration from a directory and an optional
 * environment overlay when the container first asks for it.
 *
 * Preconditions: the directory exists when the closure runs, not when it
 * is created.
 *
 * Thread-safety: stateless; the closure captures only its two arguments.
 *
 * Side effects: none until the closure runs; then it reads the
 * configured files.
 *
 * Example:
 *
 *     $container->set(Configurator::class, Psr11Bridge::factory('/app/config', 'production'));
 *
 * References: SPEC-CONFIGURATOR §12; PSR-11. ARFA 1.3 P4: no container
 * lock-in.
 *
 * @since 4.2.0
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
