<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Processor;

use KaririCode\Configurator\Environment\SystemEnvironmentResolver;
use KaririCode\Configurator\Exception\ConfigurationException;
use KaririCode\Contract\Configuration\ValueProcessor;
use KaririCode\Contract\Environment\EnvironmentResolver;

/**
 * Interpolates environment variable references within string values.
 *
 * Syntax (compatible with Docker Compose, shell, and phpdotenv):
 *   ${VAR}           — resolves VAR, throws if undefined and strict
 *   ${VAR:default}   — resolves VAR, falls back to 'default'
 *   ${VAR:-default}  — same as above (bash-style default operator)
 *
 * Supports recursive interpolation: ${DB_HOST:${FALLBACK_HOST:localhost}}
 *
 * When strict mode is enabled (default), referencing an undefined
 * variable without a default value raises ConfigurationException.
 * This prevents silent misconfiguration in production deployments.
 *
 * The resolver can be any EnvironmentResolver implementation:
 *   - SystemEnvironmentResolver (default — $_ENV → $_SERVER → getenv())
 *   - DotenvEnvironmentResolver (from kariricode/dotenv — .env + system)
 *   - InMemoryEnvironmentResolver (testing)
 *   - Custom (Vault, AWS SSM, Azure Key Vault)
 *
 * Ref: IEEE Std 1003.1 (POSIX) §2.6.2 Parameter Expansion;
 *      Docker Compose variable substitution specification.
 *
 * @package KaririCode\Configurator
 * @since   4.1.0 ARFA 1.3
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

    // ── Internal ──────────────────────────────────────────────────────

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
                $expression = $this->extractBalancedExpression($input, $i + 2, $length);

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
                        'content' => substr($input, $start, $pos - $start),
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
            throw ConfigurationException::undefinedEnvironmentVariable($name);
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

            if (substr($expression, $i, 2) === '${') {
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
                    'name' => substr($expression, 0, $i),
                    'default' => str_starts_with($default, '-') ? substr($default, 1) : $default,
                ];
            }
        }

        return ['name' => $expression, 'default' => null];
    }
}
