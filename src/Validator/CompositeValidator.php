<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Validator;

use KaririCode\Configurator\Contract\ConfigValidator;
use KaririCode\Configurator\Exception\ValidationException;

/**
 * Runs several validators over the same configuration and reports the
 * violations of all of them in one ValidationException, so the schemas
 * that config providers declare still apply when the application brings
 * a validator of its own.
 *
 * Preconditions: each validator reports its failures as
 * ValidationException; any other exception stops the run and
 * propagates as thrown.
 *
 * Thread-safety: immutable; as safe as the validators it holds.
 *
 * Side effects: those of its validators.
 *
 * Example:
 *
 *     $validator = new CompositeValidator(new SchemaValidator($providerSchema), new TlsPolicyValidator());
 *     $validator->validate($data); // one exception with the violations of both
 *
 * References: SPEC-CONFIGURATOR §4, §7, ADR-011. ARFA 1.3 P5: every
 * violation reported in one pass.
 *
 * @since 4.2.0
 */
final readonly class CompositeValidator implements ConfigValidator
{
    /** @var array<array-key, ConfigValidator> In the order they run */
    private array $validators;

    public function __construct(ConfigValidator ...$validators)
    {
        $this->validators = $validators;
    }

    #[\Override]
    public function validate(array $data): void
    {
        $violations = [];

        foreach ($this->validators as $validator) {
            try {
                $validator->validate($data);
            } catch (ValidationException $exception) {
                $violations = [...$violations, ...$exception->violations];
            }
        }

        if ($violations !== []) {
            throw ValidationException::schemaViolations($violations);
        }
    }
}
