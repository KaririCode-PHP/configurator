<?php

declare(strict_types=1);

namespace KaririCode\Configurator;

use KaririCode\Configurator\Contract\ConfigValidator;
use KaririCode\Configurator\Contract\Loader;
use KaririCode\Configurator\Contract\MergeStrategy;
use KaririCode\Configurator\Environment\CompositeEnvironmentResolver;
use KaririCode\Configurator\Environment\SystemEnvironmentResolver;
use KaririCode\Configurator\MergeStrategy\RecursiveMerge;
use KaririCode\Configurator\Processor\EnvironmentProcessor;
use KaririCode\Configurator\Processor\ValueProcessorChain;
use KaririCode\Configurator\Validator\SchemaValidator;
use KaririCode\Contract\Configuration\ConfigProvider;
use KaririCode\Contract\Configuration\ValueProcessor;
use KaririCode\Contract\Environment\EnvironmentResolver;

/**
 * Builds a sealed Configuration from the sources an application
 * declares, applied in a fixed priority: provider defaults, then
 * directory files (base, then the environment overlay), then explicit
 * files, then programmatic overrides.
 *
 * It also wires the value processors (environment interpolation, casts,
 * decryption), custom loaders, the merge strategy and the schema, so the
 * Configuration class stays free of construction concerns. Environment
 * resolvers, value processors and config providers are the types of
 * kariricode/contract, so any component can supply them without
 * depending on this package.
 *
 * Preconditions: create() starts a builder; every with*() call returns
 * the same builder; build() may run more than once and builds a new
 * Configuration each time, which ReloadableConfiguration relies on.
 *
 * Thread-safety: a builder is mutable and belongs to the code that
 * configures it; the configurations it builds are independent of it.
 *
 * Side effects: build() lists and reads the configured files and
 * directories, and the processors may read the environment.
 *
 * Example:
 *
 *     $config = ConfigurationBuilder::create()
 *         ->withDirectory(__DIR__ . '/config')
 *         ->withEnvironment('production')
 *         ->withEnvInterpolation()
 *         ->withSchema(['app.name' => 'string', 'app.debug' => 'bool'])
 *         ->build();
 *
 * With a .env store, kariricode/dotenv 4.5 or later: `->withDotenv(new
 * DotenvEnvironmentResolver($dotenv))` (ADR-010).
 *
 * References: ADR-001, ADR-004, ADR-005, ADR-010; SPEC-CONFIGURATOR §5,
 * §7. ARFA 1.3 P2: composition of sources and processors.
 *
 * @since 4.2.0
 */
final class ConfigurationBuilder
{
    /** Extensions withDirectory() picks up, in load order per directory. */
    private const array SUPPORTED_EXTENSIONS = ['php', 'json', 'yaml', 'yml', 'ini', 'toml'];

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

    /** @var list<ConfigProvider> */
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

    // ── Configuration ────────────────────────────────────────────────────

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

    // ── Config Providers ─────────────────────────────────────────────────

    /**
     * Registers an ecosystem config provider.
     *
     * Accepts both the ecosystem ConfigProvider and the
     * local backward-compatible alias.
     *
     * Provider defaults are merged before user-supplied files,
     * ensuring user config always takes precedence. Provider
     * schemas are accumulated and merged with any explicit
     * schema set via withSchema().
     */
    public function withProvider(ConfigProvider $provider): self
    {
        $this->providers[] = $provider;

        $namespace = $provider->namespace();

        foreach ($provider->schema() as $key => $type) {
            $this->providerSchema["{$namespace}.{$key}"] = $type;
        }

        return $this;
    }

    // ── Value Processors ─────────────────────────────────────────────────

    /**
     * Adds a value processor to the processing chain.
     *
     * Accepts both the ecosystem ValueProcessor and the
     * local backward-compatible alias.
     */
    public function withValueProcessor(ValueProcessor $processor): self
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
     * @param EnvironmentResolver|null $resolver Custom env resolver (defaults to system)
     * @param bool                              $strict   Throw on undefined vars without defaults
     */
    public function withEnvInterpolation(
        ?EnvironmentResolver $resolver = null,
        bool $strict = true,
    ): self {
        $this->processorChain->add(
            new EnvironmentProcessor($resolver, $strict),
        );

        return $this;
    }

    /**
     * Interpolates ${VAR} from a loaded .env store, read through the
     * contract's EnvironmentResolver, with the process environment as
     * the fallback for variables the store does not define.
     *
     * kariricode/dotenv 4.5 and later ship the resolver of their store:
     *
     *   $dotenv = new \KaririCode\Dotenv\Dotenv('/app');
     *   $dotenv->load();
     *
     *   $config = ConfigurationBuilder::create()
     *       ->withDotenv(new \KaririCode\Dotenv\Integration\DotenvEnvironmentResolver($dotenv))
     *       ->build();
     *
     * @param bool $strict         Throw on undefined vars without defaults
     * @param bool $systemFallback Read the process environment when the store has no value
     */
    public function withDotenv(
        EnvironmentResolver $resolver,
        bool $strict = true,
        bool $systemFallback = true,
    ): self {
        return $this->withEnvInterpolation(
            resolver: $systemFallback
                ? new CompositeEnvironmentResolver($resolver, new SystemEnvironmentResolver())
                : $resolver,
            strict: $strict,
        );
    }

    // ── Sources ──────────────────────────────────────────────────────────

    public function withFile(string $path): self
    {
        $this->files[] = $path;

        return $this;
    }

    /**
     * Loads the configuration files of a directory, sorted by name: the
     * base files (`app.php`, no dot before the extension), then the
     * overlays of the selected environment (`app.production.php`). Files
     * of every supported extension count (php, json, yaml, yml, ini,
     * toml); a missing directory loads nothing.
     *
     * A glob pattern (`/config/*.php`) loads every file it matches, in
     * name order, overlays included.
     */
    public function withDirectory(string $path): self
    {
        $this->directories[] = $path;

        return $this;
    }

    /**
     * Selects the environment whose overlays load after the base files of
     * each directory; with 'production':
     *
     *   1. config/database.php             (base)
     *   2. config/database.production.php  (overlay)
     *
     * Without an environment, and for any other one, overlays such as
     * `database.production.php` do not load.
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

    // ── Build ────────────────────────────────────────────────────────────

    public function build(): Configuration
    {
        $validator = $this->buildValidator();

        $config = new Configuration(
            mergeStrategy: $this->mergeStrategy,
            validator: $validator,
            processorChain: $this->processorChain->isEmpty() ? null : $this->processorChain,
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

    // ── Internal ─────────────────────────────────────────────────────────

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
        foreach ($this->directories as $directory) {
            if (str_contains($directory, '*')) {
                $this->loadGlob($config, $directory);

                continue;
            }

            if (!is_dir($directory)) {
                continue;
            }

            [$baseFiles, $envFiles] = $this->filesOf($directory);
            $this->loadSorted($config, $baseFiles);
            $this->loadSorted($config, $envFiles);
        }
    }

    /**
     * The configuration files of a directory: base files, whose names have
     * no dot before the extension (`app.php`), and the overlays of the
     * selected environment (`app.production.php`), which load after them.
     * The overlays of other environments never load.
     *
     * @return array{list<string>, list<string>}
     */
    private function filesOf(string $directory): array
    {
        $baseFiles = [];
        $envFiles = [];

        foreach (self::SUPPORTED_EXTENSIONS as $extension) {
            foreach ($this->globFiles("{$directory}/*.{$extension}") as $file) {
                $basename = pathinfo($file, PATHINFO_FILENAME);

                if (!str_contains($basename, '.')) {
                    $baseFiles[] = $file;
                } elseif ($this->environment !== null && str_ends_with($basename, ".{$this->environment}")) {
                    $envFiles[] = $file;
                }
            }
        }

        return [$baseFiles, $envFiles];
    }

    /** @param list<string> $files */
    private function loadSorted(Configuration $config, array $files): void
    {
        sort($files);

        foreach ($files as $file) {
            $config->load($file);
        }
    }

    private function loadGlob(Configuration $config, string $pattern): void
    {
        $files = $this->globFiles($pattern);
        sort($files);

        foreach ($files as $file) {
            if (is_file($file)) {
                $config->load($file);
            }
        }
    }

    /** @return list<string> */
    private function globFiles(string $pattern): array
    {
        $files = glob($pattern);

        return $files === false ? [] : $files;
    }

    private function loadFiles(Configuration $config): void
    {
        foreach ($this->files as $file) {
            $config->load($file);
        }
    }
}
