<?php

declare(strict_types=1);

namespace KaririCode\Configurator;

use KaririCode\Configurator\Contract\ConfigValidator;
use KaririCode\Configurator\Contract\Configurator;
use KaririCode\Configurator\Contract\Loader;
use KaririCode\Configurator\Contract\MergeStrategy;
use KaririCode\Configurator\Exception\ConfigurationException;
use KaririCode\Configurator\Exception\LoaderException;
use KaririCode\Configurator\Loader\IniLoader;
use KaririCode\Configurator\Loader\JsonLoader;
use KaririCode\Configurator\Loader\PhpLoader;
use KaririCode\Configurator\Loader\TomlLoader;
use KaririCode\Configurator\Loader\YamlLoader;
use KaririCode\Configurator\MergeStrategy\RecursiveMerge;
use KaririCode\Configurator\Processor\ValueProcessorChain;

/**
 * Centralized, immutable configuration manager.
 *
 * Loads configuration from PHP, JSON, and YAML files using pluggable
 * loaders, merges them via a configurable strategy, applies value
 * processors (environment interpolation, type casting, decryption),
 * optionally validates against a schema, and exposes values through
 * type-safe dot-notation accessors. After the loading phase, the
 * internal state is sealed and any mutation attempt raises
 * ConfigurationException.
 *
 * PHP 8.4 features used:
 *   - Property hooks for computed properties (count, isEmpty, keys)
 *   - Asymmetric visibility for controlled mutation during loading
 *
 * @see ConfigurationBuilder For fluent construction
 *
 * @package KaririCode\Configurator
 * @since   4.0.0 ARFA 1.3
 */
final class Configuration implements Configurator
{
    // ── State ─────────────────────────────────────────────────────────

    /** @var array<array-key, mixed> */
    private array $data = [];

    /** @var array<string, Loader> Extension → Loader mapping */
    private array $loaders = [];

    private bool $sealed = false;

    // ── Computed Properties (PHP 8.4 Property Hooks) ──────────────────

    public int $count {
        get => count($this->data);
    }

    public bool $isEmpty {
        get => $this->data === [];
    }

    /** @var list<array-key> Top-level keys; numeric keys are int in PHP arrays */
    public array $keys {
        get => array_keys($this->data);
    }

    // ── Constructor ───────────────────────────────────────────────────

    public function __construct(
        private readonly MergeStrategy $mergeStrategy = new RecursiveMerge(),
        private readonly ?ConfigValidator $validator = null,
        private readonly ?ValueProcessorChain $processorChain = null,
    ) {
        $this->registerDefaultLoaders();
    }

    // ── Loading ───────────────────────────────────────────────────────

    /**
     * Loads a configuration file, merging its contents into the
     * accumulated state. Must be called before seal().
     *
     * @throws ConfigurationException When configuration is already sealed
     * @throws LoaderException        When no loader supports the file extension
     */
    public function load(string $path): self
    {
        $this->guardMutable();

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $loader = $this->resolveLoader($extension);

        $loaded = $loader->load($path);
        $this->data = $this->mergeStrategy->merge($this->data, $loaded);

        return $this;
    }

    /**
     * Merges an associative array directly into the configuration.
     *
     * Useful for programmatic overrides (e.g. environment variables,
     * CLI arguments) applied after file loading.
     *
     * @param array<array-key, mixed> $data
     *
     * @throws ConfigurationException When the configuration is sealed (code 1002)
     */
    public function merge(array $data): self
    {
        $this->guardMutable();

        $this->data = $this->mergeStrategy->merge($this->data, $data);

        return $this;
    }

    /**
     * Seals the configuration, making it immutable.
     *
     * Processing order: value processors → validation → seal.
     * Value processors run before validation so that interpolated
     * and type-cast values are validated against the schema.
     */
    public function seal(): self
    {
        // Run value processor chain (env interpolation, type casting, decryption)
        if ($this->processorChain !== null && !$this->processorChain->isEmpty()) {
            $this->data = $this->processorChain->processArray($this->data);
        }

        if ($this->validator !== null) {
            $this->validator->validate($this->data);
        }

        $this->sealed = true;

        return $this;
    }

    // ── Typed Accessors (Configurator contract) ───────────────────────

    public function get(string $path, mixed $default = null): mixed
    {
        return $this->resolve($path) ?? $default;
    }

    public function getString(string $path, string $default = ''): string
    {
        $value = $this->resolve($path);

        if ($value === null) {
            return $default;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        throw ConfigurationException::typeMismatch($path, 'string', get_debug_type($value));
    }

    public function getInt(string $path, int $default = 0): int
    {
        $value = $this->resolve($path);

        if ($value === null) {
            return $default;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        throw ConfigurationException::typeMismatch($path, 'int', get_debug_type($value));
    }

    public function getFloat(string $path, float $default = 0.0): float
    {
        $value = $this->resolve($path);

        if ($value === null) {
            return $default;
        }

        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        throw ConfigurationException::typeMismatch($path, 'float', get_debug_type($value));
    }

    public function getBool(string $path, bool $default = false): bool
    {
        $value = $this->resolve($path);

        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return match (strtolower($value)) {
                'true', '1', 'yes', 'on' => true,
                'false', '0', 'no', 'off', '' => false,
                default => throw ConfigurationException::typeMismatch($path, 'bool', "string('{$value}')"),
            };
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        throw ConfigurationException::typeMismatch($path, 'bool', get_debug_type($value));
    }

    /**
     * @param array<array-key, mixed> $default
     *
     * @return array<array-key, mixed>
     */
    public function getArray(string $path, array $default = []): array
    {
        $value = $this->resolve($path);

        if ($value === null) {
            return $default;
        }

        if (is_array($value)) {
            return $value;
        }

        throw ConfigurationException::typeMismatch($path, 'array', get_debug_type($value));
    }

    public function has(string $path): bool
    {
        return $this->resolve($path) !== null;
    }

    public function all(): array
    {
        return $this->data;
    }

    // ── Loader Registration ───────────────────────────────────────────

    /**
     * Registers a custom loader for a specific file extension.
     *
     * Calling this after seal() is allowed — loaders can be added
     * for future use even on sealed configurations that are cloned
     * and reloaded.
     */
    public function registerLoader(Loader $loader, string ...$extensions): self
    {
        foreach ($extensions as $ext) {
            $this->loaders[strtolower($ext)] = $loader;
        }

        return $this;
    }

    // ── Internal ──────────────────────────────────────────────────────

    /**
     * Resolves a dot-notation path to a value within the data tree.
     *
     * Algorithm: iteratively descends the nested array using each
     * segment as a key. Returns null when any segment is missing.
     *
     * Complexity: O(d) where d = dot-depth of the path.
     */
    private function resolve(string $path): mixed
    {
        $segments = explode('.', $path);
        $current = $this->data;

        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        return $current;
    }

    private function resolveLoader(string $extension): Loader
    {
        return $this->loaders[$extension]
            ?? throw ConfigurationException::unsupportedFormat($extension);
    }

    private function guardMutable(): void
    {
        if ($this->sealed) {
            throw ConfigurationException::immutableViolation();
        }
    }

    private function registerDefaultLoaders(): void
    {
        $php = new PhpLoader();
        $json = new JsonLoader();
        $yaml = new YamlLoader();
        $ini = new IniLoader();
        $toml = new TomlLoader();

        $this->loaders['php'] = $php;
        $this->loaders['json'] = $json;
        $this->loaders['yaml'] = $yaml;
        $this->loaders['yml'] = $yaml;
        $this->loaders['ini'] = $ini;
        $this->loaders['toml'] = $toml;
    }
}
