<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Validator;

use KaririCode\Configurator\Contract\ConfigValidator;
use KaririCode\Configurator\Exception\ValidationException;

/**
 * Validates configuration against a schema that declares
 * expected types and required keys.
 *
 * Schema format (dot-notation keys → type descriptors):
 *   [
 *       'app.name'      => 'string',
 *       'app.debug'     => 'bool',
 *       'app.port'      => 'int',
 *       'app.rate'      => 'float',
 *       'app.servers'   => 'array',
 *       'app.callback'  => '?string',   // nullable string
 *       'database.host' => 'string',
 *   ]
 *
 * A leading '?' marks the key as optional. Keys without '?'
 * are required and must exist in the data.
 *
 * @package KaririCode\Configurator
 * @since   4.0.0 ARFA 1.3
 */
final readonly class SchemaValidator implements ConfigValidator
{
    // ── Type Map ──────────────────────────────────────────────────────

    private const array TYPE_CHECKS = [
        'string' => 'is_string',
        'int' => 'is_int',
        'integer' => 'is_int',
        'float' => 'is_float',
        'double' => 'is_float',
        'bool' => 'is_bool',
        'boolean' => 'is_bool',
        'array' => 'is_array',
        'numeric' => 'is_numeric',
        'scalar' => 'is_scalar',
    ];

    /**
     * @param array<string, string> $schema Dot-notation path → type descriptor
     */
    public function __construct(
        private array $schema,
    ) {
    }

    /**
     * Returns the raw schema for introspection or merging.
     *
     * @return array<string, string>
     */
    public function schema(): array
    {
        return $this->schema;
    }

    public function validate(array $data): void
    {
        $violations = [];

        foreach ($this->schema as $path => $typeDescriptor) {
            $nullable = str_starts_with($typeDescriptor, '?');
            $type = $nullable ? substr($typeDescriptor, 1) : $typeDescriptor;

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

    // ── Internal ──────────────────────────────────────────────────────

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

            $current = $current[$segment];
        }

        return true;
    }

    private function checkType(mixed $value, string $type): bool
    {
        $check = self::TYPE_CHECKS[$type] ?? null;

        if ($check !== null) {
            return $check($value);
        }

        // Class/interface name
        return $value instanceof $type;
    }
}
