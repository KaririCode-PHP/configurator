<?php

declare(strict_types=1);

namespace KaririCode\Configurator;

use KaririCode\Configurator\Contract\ConfigValidator;
use KaririCode\Configurator\Contract\Loader;
use KaririCode\Configurator\Contract\MergeStrategy;
use KaririCode\Configurator\MergeStrategy\RecursiveMerge;
use KaririCode\Configurator\Processor\EnvironmentProcessor;
use KaririCode\Configurator\Processor\ValueProcessorChain;
use KaririCode\Configurator\Validator\SchemaValidator;
use KaririCode\Contract\Configuration\ConfigProviderInterface;
use KaririCode\Contract\Configuration\ValueProcessorInterface;
use KaririCode\Contract\Environment\EnvironmentResolverInterface;

/**
 * Fluent builder for Configuration instances.
 *
 * Separates construction complexity from the Configuration class.
 * Supports directory scanning, glob patterns, environment-aware
 * loading, config providers for ecosystem self-configuration,
 * value processor chains (env interpolation, type casting, encryption),
 * and optional schema validation.
 *
 * Ecosystem integration (V4.2):
 *   - Accepts EnvironmentResolverInterface from kariricode/contract
 *   - Accepts ConfigProviderInterface from kariricode/contract
 *   - withDotenv() enables one-line integration with kariricode/dotenv
 *   - Backward compatible: local Contract aliases still accepted
 *
 * Usage:
 *   $config = ConfigurationBuilder::create()
 *       ->withDirectory(__DIR__ . '/config')
 *       ->withEnvironment('production')
 *       ->withEnvInterpolation()
 *       ->withProvider(new CacheConfigProvider())
 *       ->withSchema(['app.name' => 'string', 'app.debug' => 'bool'])
 *       ->build();
 *
 * With Dotenv integration:
 *   $dotenv = new \KaririCode\Dotenv\Dotenv('/app');
 *   $dotenv->load();
 *
 *   $config = ConfigurationBuilder::create()
 *       ->withDotenv($dotenv)
 *       ->withDirectory('/app/config')
 *       ->build();
 *
 * @package KaririCode\Configurator
 * @since   4.0.0 ARFA 1.3
 */
final class ConfigurationBuilder
{
    private MergeStrategy $mergeStrategy;
    private ?ConfigValidator $validator = null;

    /** @var list<string> */
    private array $files = [];

    /** @var list<string> */
    private array $directories = [];

    private ?string $environment = null;

    /** @var array<array-key, mixed> */
    private array $overrides = [];

    /** @var list<array{Loader, list<string>}> */
    private array $customLoaders = [];

    /** @var list<ConfigProviderInterface> */
    private array $providers = [];

    private ValueProcessorChain $processorChain;

    /** @var array<string, string> Combined schema from providers */
    private array $providerSchema = [];

    private bool $sealed = true;

    private function __construct()
    {
        $this->mergeStrategy = new RecursiveMerge();
        $this->processorChain = new ValueProcessorChain();
    }

    public static function create(): self
    {
        return new self();
    }

    // ── Configuration ─────────────────────────────────────────────────

    public function withMergeStrategy(MergeStrategy $strategy): self
    {
        $this->mergeStrategy = $strategy;

        return $this;
    }

    /**
     * @param array<string, string> $schema Key → type mapping
     */
    public function withSchema(array $schema): self
    {
        $this->validator = new SchemaValidator($schema);

        return $this;
    }

    public function withValidator(ConfigValidator $validator): self
    {
        $this->validator = $validator;

        return $this;
    }

    public function withLoader(Loader $loader, string ...$extensions): self
    {
        $this->customLoaders[] = [$loader, array_values($extensions)];

        return $this;
    }

    // ── Config Providers ──────────────────────────────────────────────

    /**
     * Registers an ecosystem config provider.
     *
     * Accepts both the ecosystem ConfigProviderInterface and the
     * local backward-compatible alias.
     *
     * Provider defaults are merged before user-supplied files,
     * ensuring user config always takes precedence. Provider
     * schemas are accumulated and merged with any explicit
     * schema set via withSchema().
     */
    public function withProvider(ConfigProviderInterface $provider): self
    {
        $this->providers[] = $provider;

        $namespace = $provider->namespace();

        foreach ($provider->schema() as $key => $type) {
            $this->providerSchema["{$namespace}.{$key}"] = $type;
        }

        return $this;
    }

    // ── Value Processors ──────────────────────────────────────────────

    /**
     * Adds a value processor to the processing chain.
     *
     * Accepts both the ecosystem ValueProcessorInterface and the
     * local backward-compatible alias.
     */
    public function withValueProcessor(ValueProcessorInterface $processor): self
    {
        $this->processorChain->add($processor);

        return $this;
    }

    /**
     * Enables ${VAR:default} environment variable interpolation.
     *
     * Shortcut for withValueProcessor(new EnvironmentProcessor(...)).
     * This is the most commonly needed processor — the explicit method
     * makes it discoverable and documents the feature prominently.
     *
     * @param EnvironmentResolverInterface|null $resolver Custom env resolver (defaults to system)
     * @param bool                              $strict   Throw on undefined vars without defaults
     */
    public function withEnvInterpolation(
        ?EnvironmentResolverInterface $resolver = null,
        bool $strict = true,
    ): self {
        $this->processorChain->add(
            new EnvironmentProcessor($resolver, $strict),
        );

        return $this;
    }

    /**
     * Integrates with KaririCode\Dotenv for .env-based interpolation.
     *
     * Accepts any object with an all() method that returns loaded
     * environment variables, or an EnvironmentResolverInterface directly.
     *
     * This is the recommended way to integrate Dotenv with Configurator.
     *
     * Usage:
     *   $dotenv = new \KaririCode\Dotenv\Dotenv('/app');
     *   $dotenv->load();
     *
     *   $config = ConfigurationBuilder::create()
     *       ->withDotenv($dotenv)
     *       ->build();
     *
     * @param object|EnvironmentResolverInterface $dotenv         Dotenv instance or pre-built resolver
     * @param bool                                $strict         Throw on undefined vars without defaults
     * @param bool                                $systemFallback Fall back to system env when Dotenv has no value
     *
     * @throws \InvalidArgumentException When $dotenv is neither a resolver nor an object exposing all()
     */
    public function withDotenv(
        object $dotenv,
        bool $strict = true,
        bool $systemFallback = true,
    ): self {
        if ($dotenv instanceof EnvironmentResolverInterface) {
            $resolver = $systemFallback
                ? new Environment\CompositeEnvironmentResolver($dotenv, new Environment\SystemEnvironmentResolver())
                : $dotenv;

            return $this->withEnvInterpolation($resolver, $strict);
        }

        if (!method_exists($dotenv, 'all')) {
            throw new \InvalidArgumentException(
                sprintf(
                    'withDotenv() expects a Dotenv instance with all() method '
                    . 'or an EnvironmentResolverInterface, got %s.',
                    get_debug_type($dotenv),
                ),
            );
        }

        /** @var array<string, string> $loadedVars */
        $loadedVars = $dotenv->all();

        $primary = new Environment\InMemoryEnvironmentResolver($loadedVars);

        $resolver = $systemFallback
            ? new Environment\CompositeEnvironmentResolver($primary, new Environment\SystemEnvironmentResolver())
            : $primary;

        return $this->withEnvInterpolation($resolver, $strict);
    }

    // ── Sources ───────────────────────────────────────────────────────

    public function withFile(string $path): self
    {
        $this->files[] = $path;

        return $this;
    }

    /**
     * Scans a directory for configuration files.
     *
     * Supports glob patterns (e.g. '/config/*.php').
     * Non-glob paths scan for *.php, *.json, *.yaml, *.yml.
     */
    public function withDirectory(string $path): self
    {
        $this->directories[] = $path;

        return $this;
    }

    /**
     * Sets the deployment environment for cascade loading.
     *
     * When set, the builder loads base files first, then overlays
     * environment-specific files. Example with environment 'production':
     *   1. config/database.php       (base)
     *   2. config/database.production.php  (overlay)
     */
    public function withEnvironment(string $environment): self
    {
        $this->environment = $environment;

        return $this;
    }

    /**
     * Programmatic overrides applied after all files are loaded.
     *
     * @param array<array-key, mixed> $overrides
     */
    public function withOverrides(array $overrides): self
    {
        $this->overrides = $overrides;

        return $this;
    }

    /**
     * When false, the built Configuration is not sealed and remains
     * mutable for further programmatic modification.
     */
    public function sealed(bool $sealed): self
    {
        $this->sealed = $sealed;

        return $this;
    }

    // ── Build ─────────────────────────────────────────────────────────

    public function build(): Configuration
    {
        $validator = $this->buildValidator();

        $config = new Configuration(
            $this->mergeStrategy,
            $validator,
            $this->processorChain->isEmpty() ? null : $this->processorChain,
        );

        foreach ($this->customLoaders as [$loader, $extensions]) {
            $config->registerLoader($loader, ...$extensions);
        }

        // 1. Provider defaults (lowest priority)
        $this->loadProviderDefaults($config);

        // 2. Directory files (base + environment overlays)
        $this->loadDirectories($config);

        // 3. Explicit files
        $this->loadFiles($config);

        // 4. Programmatic overrides (highest priority)
        if ($this->overrides !== []) {
            $config->merge($this->overrides);
        }

        if ($this->sealed) {
            $config->seal();
        }

        return $config;
    }

    // ── Internal ──────────────────────────────────────────────────────

    private function buildValidator(): ?ConfigValidator
    {
        if ($this->providerSchema !== [] && $this->validator === null) {
            return new SchemaValidator($this->providerSchema);
        }

        if ($this->providerSchema !== [] && $this->validator instanceof SchemaValidator) {
            return new SchemaValidator(
                array_merge($this->providerSchema, $this->validator->schema()),
            );
        }

        return $this->validator;
    }

    private function loadProviderDefaults(Configuration $config): void
    {
        foreach ($this->providers as $provider) {
            $namespace = $provider->namespace();
            $defaults = $provider->defaults();

            if ($defaults !== []) {
                $config->merge([$namespace => $defaults]);
            }
        }
    }

    private function loadDirectories(Configuration $config): void
    {
        $supportedExtensions = ['php', 'json', 'yaml', 'yml', 'ini', 'toml'];

        foreach ($this->directories as $directory) {
            if (str_contains($directory, '*')) {
                $this->loadGlob($config, $directory);

                continue;
            }

            if (!is_dir($directory)) {
                continue;
            }

            $baseFiles = [];
            $envFiles = [];

            foreach ($supportedExtensions as $ext) {
                foreach (glob("{$directory}/*.{$ext}") ?: [] as $file) {
                    $basename = pathinfo($file, PATHINFO_FILENAME);

                    if ($this->environment !== null && str_ends_with($basename, ".{$this->environment}")) {
                        $envFiles[] = $file;
                    } else {
                        $baseFiles[] = $file;
                    }
                }
            }

            sort($baseFiles);

            foreach ($baseFiles as $file) {
                $config->load($file);
            }

            sort($envFiles);

            foreach ($envFiles as $file) {
                $config->load($file);
            }
        }
    }

    private function loadGlob(Configuration $config, string $pattern): void
    {
        $files = glob($pattern) ?: [];
        sort($files);

        foreach ($files as $file) {
            if (is_file($file)) {
                $config->load($file);
            }
        }
    }

    private function loadFiles(Configuration $config): void
    {
        foreach ($this->files as $file) {
            $config->load($file);
        }
    }
}
