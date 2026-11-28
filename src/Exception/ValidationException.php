<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Exception;

use KaririCode\Exception\ConfigurationException as ConfigurationBase;

// ── Exception: ValidationException ───────────────────────────────────
// Configuration that breaks its schema (2850–2859).
// ─────────────────────────────────────────────────────────────────────

/**
 * Reports every way a sealed configuration breaks its schema at once,
 * so an operator fixes all of them in one pass: `$violations` lists one
 * message per failure, and the message joins the first five. Violations
 * name paths and types, never values.
 *
 * Preconditions: built only through its named constructors; every list
 * of violations has at least one entry.
 *
 * Thread-safety: immutable once built.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     try {
 *         $config = ConfigurationBuilder::create()->withSchema(['app.port' => 'int'])->build();
 *     } catch (ValidationException $e) {
 *         foreach ($e->violations as $violation) {
 *             fwrite(STDERR, "- {$violation}\n");
 *         }
 *     }
 *
 * References: SPEC-CONFIGURATOR §4, ADR-011; Spec V4.3.3 §8.4
 * (Configuration family). ARFA 1.3 P5: every failure reported in one pass.
 *
 * @see https://github.com/KaririCode-PHP/exception/blob/main/README.md
 */
final class ValidationException extends ConfigurationBase implements ConfigurationException
{
    /** @var list<string> One message per violation, in schema order. */
    public readonly array $violations;

    /** @param list<string> $violations */
    private function __construct(ConfigCode $code, string $message, array $violations)
    {
        parent::__construct($code, $message);
        $this->violations = $violations;
    }

    /** @param list<string> $violations */
    public static function schemaViolations(array $violations): self
    {
        $summary = implode('; ', array_slice($violations, 0, 5));
        $count = count($violations);

        return new self(
            code: ConfigCode::SchemaViolations,
            message: "Configuration validation failed ({$count} violation(s)): {$summary}.",
            violations: $violations,
        );
    }

    /**
     * For custom ConfigValidator implementations. The built-in
     * SchemaValidator reports every violation at once through
     * schemaViolations().
     */
    public static function requiredMissing(string $path): self
    {
        return new self(
            code: ConfigCode::RequiredMissing,
            message: 'Required configuration key missing: ' . self::quote($path) . '.',
            violations: ["Missing required key: {$path}"],
        );
    }

    /**
     * For custom ConfigValidator implementations. The built-in
     * SchemaValidator reports every violation at once through
     * schemaViolations().
     */
    public static function typeConstraint(string $path, string $expected, string $actual): self
    {
        return new self(
            code: ConfigCode::TypeConstraint,
            message: 'Type constraint failed at ' . self::quote($path) . ": expected {$expected}, got {$actual}.",
            violations: ["Type constraint: {$path} expected {$expected}, got {$actual}"],
        );
    }
}
