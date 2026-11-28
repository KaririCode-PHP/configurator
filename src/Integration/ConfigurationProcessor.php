<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Integration;

use KaririCode\Configurator\Configuration;
use KaririCode\Configurator\ConfigurationBuilder;
use KaririCode\Contract\Processor\Processor;

/**
 * Exposes configuration loading as a ProcessorPipeline step.
 *
 * Enables configuration to participate in ARFA 1.3 reactive flows.
 * The processor loads configuration files and injects the sealed
 * Configuration instance into the processing context.
 *
 * Usage within a pipeline:
 *   $pipeline->through([
 *       new ConfigurationProcessor('/app/config'),
 *       new DatabaseProcessor(),
 *       new CacheProcessor(),
 *   ]);
 *
 * Requires: kariricode/contract (Processor)
 *
 * @package KaririCode\Configurator
 * @since   4.0.0 ARFA 1.3
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
