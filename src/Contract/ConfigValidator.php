<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Contract;

use KaririCode\Configurator\Exception\ValidationException;

/**
 * Checks a configuration tree against the rules it must meet, before the
 * tree is sealed; the built-in SchemaValidator reads the schema format
 * of SPEC-CONFIGURATOR §4.
 *
 * Preconditions: validate() receives the tree after the value processors
 * ran, so interpolated and cast values are what is checked; it returns
 * nothing on success and throws ValidationException otherwise.
 *
 * Thread-safety: implementations are stateless or immutable.
 *
 * Side effects: none in SchemaValidator; an implementation must add
 * none.
 *
 * Example:
 *
 *     $validator = new SchemaValidator(['app.name' => 'string', 'app.port' => 'int']);
 *     $validator->validate(['app' => ['name' => 'demo', 'port' => 8080]]);
 *
 * References: SPEC-CONFIGURATOR §4, ADR-011. ARFA 1.3 P5: every
 * violation reported in one pass.
 *
 * @since 4.2.0
 */
interface ConfigValidator
{
    /**
     * Validates configuration data against the registered schema.
     *
     * @param array<array-key, mixed> $data Configuration data to validate
     *
     * @throws ValidationException When one or more validation rules fail (CFG_2850;
     *                             custom validators may also use CFG_2851, CFG_2852)
     */
    public function validate(array $data): void;
}
