<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Exception;

/**
 * Raised when configuration data fails schema validation.
 *
 * Carries a structured list of violations so callers can
 * report all errors in a single pass rather than fail-fast.
 *
 * @package KaririCode\Configurator
 * @since   4.0.0 ARFA 1.3
 */
final class ValidationException extends ConfigurationException
{
    private const int SCHEMA_VIOLATION = 1200;
    private const int REQUIRED_MISSING = 1201;
    private const int TYPE_CONSTRAINT = 1202;

    /** @var list<string> */
    private readonly array $violations;

    /**
     * @param list<string> $violations Human-readable violation messages
     */
    private function __construct(string $message, int $code, array $violations)
    {
        parent::__construct($message, $code);
        $this->violations = $violations;
    }

    /**
     * @param list<string> $violations
     */
    public static function schemaViolations(array $violations): self
    {
        $summary = implode('; ', array_slice($violations, 0, 5));
        $count = count($violations);

        return new self(
            "Configuration validation failed ({$count} violation(s)): {$summary}.",
            self::SCHEMA_VIOLATION,
            $violations,
        );
    }

    /**
     * For custom ConfigValidator implementations. The built-in
     * SchemaValidator never throws it: it reports every violation at
     * once through schemaViolations() (code 1200).
     */
    public static function requiredMissing(string $path): self
    {
        return new self(
            "Required configuration key missing: '{$path}'.",
            self::REQUIRED_MISSING,
            ["Missing required key: {$path}"],
        );
    }

    /**
     * For custom ConfigValidator implementations. The built-in
     * SchemaValidator never throws it: it reports every violation at
     * once through schemaViolations() (code 1200).
     */
    public static function typeConstraint(string $path, string $expected, string $actual): self
    {
        return new self(
            "Type constraint failed at '{$path}': expected {$expected}, got {$actual}.",
            self::TYPE_CONSTRAINT,
            ["Type constraint: {$path} expected {$expected}, got {$actual}"],
        );
    }

    /** @return list<string> */
    public function getViolations(): array
    {
        return $this->violations;
    }
}
