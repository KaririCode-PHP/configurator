<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Processor;

use KaririCode\Configurator\Exception\ConfigurationException;
use KaririCode\Contract\Configuration\ValueProcessor;

/**
 * Applies explicit type coercion directives embedded in string values.
 *
 * Syntax (inspired by Symfony env var processors):
 *   "int:8080"          → (int) 8080
 *   "float:3.14"        → (float) 3.14
 *   "bool:true"         → true
 *   "json:{\"a\":1}"    → ['a' => 1]
 *   "csv:a,b,c"         → ['a', 'b', 'c']
 *   "base64:dGVzdA=="   → 'test'
 *   "file:/path/to/key" → file_get_contents('/path/to/key')
 *
 * The processor pattern is extensible: custom prefixes can be
 * registered via registerCaster(). This enables domain-specific
 * transformations (e.g. 'dsn:', 'url:', 'enum:') without modifying
 * the core processor.
 *
 * Ref: Symfony EnvVarProcessor (symfony/dependency-injection §7.3);
 *      Spring Boot @Value type conversion.
 *
 * @package KaririCode\Configurator
 * @since   4.1.0 ARFA 1.3
 */
final class TypeCastProcessor implements ValueProcessor
{
    /** @var array<string, \Closure(string): mixed> */
    private array $casters;

    public function __construct()
    {
        $this->casters = $this->defaultCasters();
    }

    #[\Override]
    public function supports(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        $colonPos = strpos($value, ':');

        if ($colonPos === false || $colonPos === 0) {
            return false;
        }

        $prefix = substr($value, 0, $colonPos);

        return isset($this->casters[$prefix]);
    }

    #[\Override]
    public function process(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $colonPos = strpos($value, ':');

        if ($colonPos === false) {
            return $value;
        }

        $prefix = substr($value, 0, $colonPos);
        $payload = substr($value, $colonPos + 1);

        $caster = $this->casters[$prefix] ?? null;

        if ($caster === null) {
            return $value;
        }

        return $caster($payload);
    }

    /**
     * Registers a custom type caster for a given prefix.
     *
     * @param \Closure(string): mixed $caster Transform function
     */
    public function registerCaster(string $prefix, \Closure $caster): self
    {
        $this->casters[$prefix] = $caster;

        return $this;
    }

    // ── Built-in Casters ──────────────────────────────────────────────

    /** @return array<string, \Closure(string): mixed> */
    private function defaultCasters(): array
    {
        return [
            'int' => static fn(string $v): int => (int) $v,

            'float' => static fn(string $v): float => (float) $v,

            'bool' => static fn(string $v): bool => match (strtolower($v)) {
                'true', '1', 'yes', 'on' => true,
                'false', '0', 'no', 'off', '' => false,
                default => throw ConfigurationException::typeCastFailed('bool', $v),
            },

            'json' => static function (string $v): mixed {
                try {
                    return json_decode($v, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    throw ConfigurationException::typeCastFailed('json', $v);
                }
            },

            'csv' => static fn(string $v): array => $v === ''
                ? []
                : array_map('trim', explode(',', $v)),

            'base64' => static function (string $v): string {
                $decoded = base64_decode($v, true);

                if ($decoded === false) {
                    throw ConfigurationException::typeCastFailed('base64', 'invalid encoding');
                }

                return $decoded;
            },

            'file' => static function (string $v): string {
                if (!is_file($v) || !is_readable($v)) {
                    throw ConfigurationException::typeCastFailed('file', "unreadable: {$v}");
                }

                $content = file_get_contents($v);

                return $content !== false ? trim($content) : '';
            },

            'trim' => static fn(string $v): string => trim($v),

            'lower' => static fn(string $v): string => strtolower($v),

            'upper' => static fn(string $v): string => strtoupper($v),
        ];
    }
}
