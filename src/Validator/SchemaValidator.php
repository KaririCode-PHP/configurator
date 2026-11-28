<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Validator;

use KaririCode\Configurator\Contract\ConfigValidator;
use KaririCode\Configurator\Exception\ValidationException;

/**
 * Checks a configuration tree against a schema of dot-notation paths and
 * types (`string`, `int`, `float`, `bool`, `array`, `numeric`, `scalar`
 * or a class name), and reports every violation at once.
 *
 * A leading `?` makes a key optional; without it the key must exist, and
 * a key that exists with a null value passes (SPEC-CONFIGURATOR §4).
 *
 * Preconditions: the schema maps paths to type descriptors.
 *
 * Thread-safety: immutable; safe to share between fibers and requests.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     new SchemaValidator(['app.name' => 'string', 'app.rate' => '?float'])->validate($data);
 *
 * References: SPEC-CONFIGURATOR §4, ADR-011. ARFA 1.3 P5: every
 * violation in one pass.
 *
 * @since 4.2.0
 */
final readonly class SchemaValidator implements ConfigValidator
{
    // ── Type Map ─────────────────────────────────────────────────────────

    /**
     * @param array<string, string> $schema Dot-notation path → type descriptor
     */
    public function __construct(
        private array $schema,
    ) {}

    /**
     * Returns the raw schema for introspection or merging.
     *
     * @return array<string, string>
     */
    public function schema(): array
    {
        return $this->schema;
    }

    #[\Override]
    public function validate(array $data): void
    {
        $violations = [];

        foreach ($this->schema as $path => $typeDescriptor) {
            $nullable = str_starts_with($typeDescriptor, '?');
            $type = $nullable ? substr($typeDescriptor, 1) : $typeDescriptor;

            /** @var mixed $value configuration leaves are untyped; checked below. */
            $value = $this->resolve($data, $path);

            // Required check (non-nullable keys must exist)
            if ($value === null) {
                if (!$nullable && !$this->existsInData($data, $path)) {
                    $violations[] = "Missing required key: '{$path}'.";
                }

                continue;
            }

            // Type check
            if (!$this->checkType($value, $type)) {
                $actual = get_debug_type($value);
                $violations[] = "Type mismatch at '{$path}': expected {$type}, got {$actual}.";
            }
        }

        if ($violations !== []) {
            throw ValidationException::schemaViolations($violations);
        }
    }

    // ── Internal ─────────────────────────────────────────────────────────

    /**
     * @param array<array-key, mixed> $data
     */
    private function resolve(array $data, string $path): mixed
    {
        $segments = explode('.', $path);
        $current = $data;

        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }

            /** @var mixed $current the tree is untyped below its root */
            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function existsInData(array $data, string $path): bool
    {
        $segments = explode('.', $path);
        $current = $data;

        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return false;
            }

            /** @var mixed $current the tree is untyped below its root */
            $current = $current[$segment];
        }

        return true;
    }

    private function checkType(mixed $value, string $type): bool
    {
        return match ($type) {
            'string' => is_string($value),
            'int', 'integer' => is_int($value),
            'float', 'double' => is_float($value),
            'bool', 'boolean' => is_bool($value),
            'array' => is_array($value),
            'numeric' => is_numeric($value),
            'scalar' => is_scalar($value),
            // Any other type is a class or interface name.
            default => $value instanceof $type,
        };
    }
}
