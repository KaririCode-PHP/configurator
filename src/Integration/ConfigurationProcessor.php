<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Integration;

use KaririCode\Configurator\Configuration;
use KaririCode\Configurator\ConfigurationBuilder;
use KaririCode\Contract\Processor\Processor;

/**
 * Runs configuration loading as a step of a
 * kariricode/processor-pipeline flow: process() builds a sealed
 * Configuration from a directory, an optional environment overlay and
 * extra files, and returns it as the step's output; the input is
 * ignored.
 *
 * Preconditions: the directory exists; the extra files load after the
 * directory, in the order given.
 *
 * Thread-safety: immutable; each process() call builds a new
 * configuration.
 *
 * Side effects: process() reads the configured files.
 *
 * Example:
 *
 *     $config = new ConfigurationProcessor('/app/config', environment: 'production')->process(null);
 *
 * References: SPEC-CONFIGURATOR §12; kariricode/contract
 * `Processor\Processor`. ARFA 1.3 P2: configuration as a flow step.
 *
 * @since 4.2.0
 */
final readonly class ConfigurationProcessor implements Processor
{
    /**
     * @param string      $configDirectory Base directory for config files
     * @param string|null $environment     Optional deployment environment overlay
     * @param list<string> $additionalFiles  Extra files to load after directory scan
     */
    public function __construct(
        private string $configDirectory,
        private ?string $environment = null,
        private array $additionalFiles = [],
    ) {}

    #[\Override]
    public function process(mixed $input): mixed
    {
        $builder = ConfigurationBuilder::create()
            ->withDirectory($this->configDirectory);

        if ($this->environment !== null) {
            $builder->withEnvironment($this->environment);
        }

        foreach ($this->additionalFiles as $file) {
            $builder->withFile($file);
        }

        return $builder->build();
    }
}
