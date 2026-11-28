<?php

declare(strict_types=1);

namespace KaririCode\Configurator;

use KaririCode\Configurator\Contract\ConfigValidator;
use KaririCode\Configurator\Contract\Configurator;
use KaririCode\Configurator\Contract\Loader;
use KaririCode\Configurator\Contract\MergeStrategy;
use KaririCode\Configurator\Exception\AccessException;
use KaririCode\Configurator\Exception\LoaderException;
use KaririCode\Configurator\Loader\IniLoader;
use KaririCode\Configurator\Loader\JsonLoader;
use KaririCode\Configurator\Loader\PhpLoader;
use KaririCode\Configurator\Loader\TomlLoader;
use KaririCode\Configurator\Loader\YamlLoader;
use KaririCode\Configurator\MergeStrategy\RecursiveMerge;
use KaririCode\Configurator\Processor\ValueProcessorChain;

/**
 * Holds the configuration of an application: it loads PHP, JSON, YAML,
 * INI and TOML files through pluggable loaders, merges them with a
 * strategy, runs the value processors (interpolation, casts, decryption)
 * and the schema check when sealed, and answers typed dot-notation reads
 * from then on.
 *
 * Two phases: while loading, load(), merge() and registerLoader() build
 * the tree; seal() runs the processors and the validator and freezes it,
 * after which any change raises AccessException (CFG_2801). The computed
 * properties `$count`, `$isEmpty` and `$keys` are PHP 8.4 get hooks over
 * the top level.
 *
 * Preconditions: load() and merge() run before seal(); a typed read of a
 * path that holds another type raises AccessException (CFG_2800), while
 * get() never throws and returns the default for a missing path or a
 * stored null.
 *
 * Thread-safety: mutable while loading; once sealed, safe to share
 * between fibers and requests.
 *
 * Side effects: load() reads the file it is given (PhpLoader includes
 * it); seal() runs the processors, which may read the environment or
 * files (TypeCastProcessor `file:`).
 *
 * Example:
 *
 *     $config = new Configuration();
 *     $config->load('/app/config/app.yaml')->merge(['app' => ['debug' => false]])->seal();
 *     $port = $config->getInt('server.port', 8080);
 *
 * References: ADR-001, ADR-003, ADR-005; SPEC-CONFIGURATOR §5, §8. ARFA
 * 1.3 P1: an immutable value once sealed.
 *
 * @see ConfigurationBuilder For fluent construction
 * @since 4.2.0
 */
final class Configuration implements Configurator
{
    // ── State ────────────────────────────────────────────────────────────

    /** @var array<array-key, mixed> */
    private array $data = [];

    /** @var array<string, Loader> Extension → Loader mapping */
    private array $loaders = [];

    private bool $sealed = false;

    // ── Computed Properties (PHP 8.4 Property Hooks) ─────────────────────

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

    // ── Constructor ──────────────────────────────────────────────────────

    public function __construct(
        private readonly MergeStrategy $mergeStrategy = new RecursiveMerge(),
        private readonly ?ConfigValidator $validator = null,
        private readonly ?ValueProcessorChain $processorChain = null,
    ) {
        $this->registerDefaultLoaders();
    }

    // ── Loading ──────────────────────────────────────────────────────────

    /**
     * Loads a configuration file, merging its contents into the
     * accumulated state. Must be called before seal().
     *
     * @throws AccessException When configuration is already sealed
     * @throws LoaderException When no loader supports the file extension, or the file cannot be loaded
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
     * @throws AccessException When the configuration is sealed (CFG_2801)
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

    // ── Typed Accessors (Configurator contract) ──────────────────────────

    #[\Override]
    public function get(string $path, mixed $default = null): mixed
    {
        return $this->resolve($path) ?? $default;
    }

    #[\Override]
    public function getString(string $path, string $default = ''): string
    {
        /** @var mixed $value configuration leaves are untyped; narrowed below. */
        $value = $this->resolve($path);

        if ($value === null) {
            return $default;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        throw AccessException::typeMismatch(path: $path, expected: 'string', actual: get_debug_type($value));
    }

    #[\Override]
    public function getInt(string $path, int $default = 0): int
    {
        /** @var mixed $value configuration leaves are untyped; narrowed below. */
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

        throw AccessException::typeMismatch(path: $path, expected: 'int', actual: get_debug_type($value));
    }

    #[\Override]
    public function getFloat(string $path, float $default = 0.0): float
    {
        /** @var mixed $value configuration leaves are untyped; narrowed below. */
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

        throw AccessException::typeMismatch(path: $path, expected: 'float', actual: get_debug_type($value));
    }

    #[\Override]
    public function getBool(string $path, bool $default = false): bool
    {
        /** @var mixed $value configuration leaves are untyped; narrowed below. */
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
                default => throw AccessException::typeMismatch(path: $path, expected: 'bool', actual: 'a string outside true, false, 1, 0, yes, no, on, off'),
            };
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        throw AccessException::typeMismatch(path: $path, expected: 'bool', actual: get_debug_type($value));
    }

    /**
     * @param array<array-key, mixed> $default
     *
     * @return array<array-key, mixed>
     */
    #[\Override]
    public function getArray(string $path, array $default = []): array
    {
        /** @var mixed $value configuration leaves are untyped; narrowed below. */
        $value = $this->resolve($path);

        if ($value === null) {
            return $default;
        }

        if (is_array($value)) {
            return $value;
        }

        throw AccessException::typeMismatch(path: $path, expected: 'array', actual: get_debug_type($value));
    }

    #[\Override]
    public function has(string $path): bool
    {
        return $this->resolve($path) !== null;
    }

    #[\Override]
    public function all(): array
    {
        return $this->data;
    }

    // ── Loader Registration ──────────────────────────────────────────────

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

    // ── Internal ─────────────────────────────────────────────────────────

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

            /** @var mixed $current the tree is untyped below its root */
            $current = $current[$segment];
        }

        return $current;
    }

    private function resolveLoader(string $extension): Loader
    {
        return $this->loaders[$extension]
            ?? throw LoaderException::unsupportedFormat($extension);
    }

    private function guardMutable(): void
    {
        if ($this->sealed) {
            throw AccessException::immutableViolation();
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
