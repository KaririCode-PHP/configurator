<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Processor;

use KaririCode\Contract\Configuration\ValueProcessor;

/**
 * Runs a list of value processors over every scalar of a configuration
 * tree: each processor that supports the current value transforms it and
 * hands the result to the next, so interpolation can feed a cast
 * (`${PORT}` becomes `int:8080`, then 8080).
 *
 * Preconditions: processors run in the order they were added; arrays are
 * walked, never passed to a processor.
 *
 * Thread-safety: mutable through add(); add every processor before the
 * chain runs.
 *
 * Side effects: those of its processors.
 *
 * Example:
 *
 *     $chain = new ValueProcessorChain()->add(new EnvironmentProcessor())->add(new TypeCastProcessor());
 *     $data = $chain->processArray(['port' => '${PORT:int:8080}']);
 *
 * References: ADR-005; GoF Chain of Responsibility. ARFA 1.3 P2:
 * composed transformations.
 *
 * @since 4.2.0
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

    // ── Internal ─────────────────────────────────────────────────────────

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
