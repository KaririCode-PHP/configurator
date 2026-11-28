<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Contract;

use KaririCode\Configurator\Exception\ValidationException;

/**
 * Configuration data validation contract.
 *
 * Validates a configuration array against a schema that defines
 * required keys, allowed types, and value constraints. Schema
 * format is documented in SPEC-CONFIGURATOR §4.
 *
 * @package KaririCode\Configurator
 * @since   4.0.0 ARFA 1.3
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
