<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Processor;

use KaririCode\Configurator\Environment\SystemEnvironmentResolver;
use KaririCode\Configurator\Exception\ProcessorException;
use KaririCode\Contract\Configuration\ValueProcessor;
use KaririCode\Contract\Environment\EnvironmentResolver;

/**
 * Interpolates environment references in configuration strings:
 * `${VAR}`, `${VAR:default}` and the shell form `${VAR:-default}`,
 * defaults nested (`${DB_HOST:${FALLBACK_HOST:localhost}}`), compatible
 * with Docker Compose and phpdotenv.
 *
 * In strict mode (the default) a reference without a value or a default
 * raises ProcessorException (CFG_2830), so a missing variable stops the
 * boot instead of becoming an empty string. The resolver is any
 * EnvironmentResolver: the process (the default), a .env store, a map
 * for tests, a vault.
 *
 * Preconditions: values are strings; references nest without a depth
 * limit other than the string itself.
 *
 * Thread-safety: immutable; as safe as its resolver.
 *
 * Side effects: each reference asks the resolver.
 *
 * Example:
 *
 *     $processor = new EnvironmentProcessor(new InMemoryEnvironmentResolver(['HOST' => 'db']));
 *     $dsn = $processor->process('pgsql:host=${HOST};port=${PORT:5432}'); // pgsql:host=db;port=5432
 *
 * References: POSIX.1-2017 §2.6.2 (parameter expansion), ADR-004; Spec
 * V4.3.3 §8.3 (fail closed).
 *
 * @since 4.2.0
 */
final readonly class EnvironmentProcessor implements ValueProcessor
{
    private EnvironmentResolver $resolver;

    public function __construct(
        ?EnvironmentResolver $resolver = null,
        private bool $strict = true,
    ) {
        $this->resolver = $resolver ?? new SystemEnvironmentResolver();
    }

    #[\Override]
    public function supports(mixed $value): bool
    {
        return is_string($value) && str_contains($value, '${');
    }

    #[\Override]
    public function process(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        return $this->interpolate($value);
    }

    // ── Internal ─────────────────────────────────────────────────────────

    /**
     * Walks the string, finds balanced ${...} references, resolves each.
     *
     * Uses manual brace-depth tracking instead of regex to correctly
     * handle nested ${...} patterns like ${A:${B:default}}.
     */
    private function interpolate(string $input): string
    {
        $result = '';
        $length = strlen($input);
        $i = 0;

        while ($i < $length) {
            // Look for ${ start marker
            if ($i + 1 < $length && $input[$i] === '$' && $input[$i + 1] === '{') {
                $expression = $this->extractBalancedExpression(input: $input, start: $i + 2, length: $length);

                if ($expression !== null) {
                    $result .= $this->resolveReference($expression['content']);
                    $i = $expression['endPos'];

                    continue;
                }
            }

            $result .= $input[$i];
            ++$i;
        }

        return $result;
    }

    /**
     * Extracts a balanced brace expression starting after '${'.
     *
     * Tracks brace depth to correctly find the matching closing '}'.
     * Returns the content between braces and the position after '}'.
     *
     * @param int $start Position right after '${' (first char of expression)
     *
     * @return array{content: string, endPos: int}|null Null if no balanced close found
     */
    private function extractBalancedExpression(string $input, int $start, int $length): ?array
    {
        $depth = 1;
        $pos = $start;

        while ($pos < $length) {
            if ($pos + 1 < $length && $input[$pos] === '$' && $input[$pos + 1] === '{') {
                ++$depth;
                $pos += 2;

                continue;
            }

            if ($input[$pos] === '}') {
                --$depth;

                if ($depth === 0) {
                    return [
                        'content' => substr(string: $input, offset: $start, length: $pos - $start),
                        'endPos' => $pos + 1,
                    ];
                }

                ++$pos;

                continue;
            }

            ++$pos;
        }

        // Unbalanced braces — return null, caller outputs literal
        return null;
    }

    private function resolveReference(string $expression): string
    {
        $parsed = $this->parseExpression($expression);
        $name = $parsed['name'];
        $default = $parsed['default'];

        $resolved = $this->resolver->get($name);

        if ($resolved !== null) {
            return $resolved;
        }

        if ($default !== null) {
            // Recursively resolve defaults that contain ${...}
            if (str_contains($default, '${')) {
                return $this->interpolate($default);
            }

            return $default;
        }

        if ($this->strict) {
            throw ProcessorException::undefinedVariable($name);
        }

        return '';
    }

    /**
     * Parses a variable expression into name and optional default.
     *
     * Handles nested ${...} in defaults by tracking brace depth
     * to find the colon separator at the top level.
     *
     * @return array{name: string, default: string|null}
     */
    private function parseExpression(string $expression): array
    {
        $depth = 0;
        $length = strlen($expression);

        for ($i = 0; $i < $length; ++$i) {
            $char = $expression[$i];

            if (substr(string: $expression, offset: $i, length: 2) === '${') {
                ++$depth;
                ++$i; // skip '{'

                continue;
            }

            if ($char === '}' && $depth > 0) {
                --$depth;

                continue;
            }

            // Colon separator at top level → marks default value boundary
            if ($char === ':' && $depth === 0) {
                $default = substr($expression, $i + 1);

                // Strip optional leading '-' (bash :-default syntax)
                return [
                    'name' => substr(string: $expression, offset: 0, length: $i),
                    'default' => str_starts_with($default, '-') ? substr($default, 1) : $default,
                ];
            }
        }

        return ['name' => $expression, 'default' => null];
    }
}
