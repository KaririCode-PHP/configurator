<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Processor;

use KaririCode\Contract\Configuration\ValueProcessor;

/**
 * Applies a chain of ValueProcessors to configuration data recursively.
 *
 * Traverses the entire configuration tree, applying each processor
 * to every scalar value. Processors are evaluated in registration order;
 * the first processor whose supports() returns true handles the value,
 * then the result is passed to subsequent processors.
 *
 * Design: Chain of Responsibility (GoF) with recursive tree traversal.
 * The chain terminates when no processor supports the current value.
 *
 * @package KaririCode\Configurator
 * @since   4.1.0 ARFA 1.3
 */
final class ValueProcessorChain
{
    /** @var list<ValueProcessor> */
    private array $processors = [];

    public function add(ValueProcessor $processor): self
    {
        $this->processors[] = $processor;

        return $this;
    }

    /**
     * Processes a scalar value through the chain.
     *
     * Each processor that supports the current value transforms it,
     * and the result is passed to the next processor. This enables
     * composition: EnvironmentProcessor resolves ${VAR} → "int:8080",
     * then TypeCastProcessor converts "int:8080" → 8080.
     */
    public function processValue(mixed $value): mixed
    {
        foreach ($this->processors as $processor) {
            if ($processor->supports($value)) {
                /** @var mixed $value a processor may return any value */
                $value = $processor->process($value);
            }
        }

        return $value;
    }

    /**
     * Recursively processes all values in a configuration array.
     *
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    public function processArray(array $data): array
    {
        if ($this->processors === []) {
            return $data;
        }

        return $this->walk($data);
    }

    public function isEmpty(): bool
    {
        return $this->processors === [];
    }

    // ── Internal ──────────────────────────────────────────────────────

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private function walk(array $data): array
    {
        return array_map(
            fn(mixed $value): mixed => is_array($value) ? $this->walk($value) : $this->processValue($value),
            $data,
        );
    }
}
