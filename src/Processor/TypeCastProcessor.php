<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Processor;

use KaririCode\Configurator\Exception\ProcessorException;
use KaririCode\Contract\Configuration\ValueProcessor;

/**
 * Applies the type prefixes of configuration strings, after the Symfony
 * env var processors: `int:`, `float:`, `bool:`, `json:`, `csv:`,
 * `base64:`, `file:` (reads the file, trimmed), `trim:`, `lower:` and
 * `upper:`; registerCaster() adds a prefix of its own.
 *
 * Preconditions: a prefix runs only on a string that starts with it; a
 * payload that does not convert raises ProcessorException (CFG_2831)
 * without repeating the payload.
 *
 * Thread-safety: mutable through registerCaster(); register every caster
 * before the processor is shared.
 *
 * Side effects: `file:` reads the file it names; the other casters have
 * none.
 *
 * Example:
 *
 *     $processor = new TypeCastProcessor();
 *     $port = $processor->process('int:8080'); // 8080
 *
 * References: ADR-005, ADR-011; Symfony DependencyInjection env var
 * processors.
 *
 * @since 4.2.0
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

        $prefix = substr(string: $value, offset: 0, length: $colonPos);

        return isset($this->casters[$prefix]);
    }

    #[\Override]
    public function process(#[\SensitiveParameter] mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $colonPos = strpos($value, ':');

        if ($colonPos === false) {
            return $value;
        }

        $prefix = substr(string: $value, offset: 0, length: $colonPos);
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

    // ── Built-in Casters ─────────────────────────────────────────────────

    /** @return array<string, \Closure(string): mixed> */
    private function defaultCasters(): array
    {
        return [
            'int' => static function (string $v): int {
                $integer = filter_var(value: $v, filter: FILTER_VALIDATE_INT);

                return $integer === false
                    ? throw ProcessorException::typeCastFailed(type: 'int', reason: 'not a decimal integer within the 64-bit range')
                    : $integer;
            },

            'float' => static function (string $v): float {
                $float = filter_var(value: $v, filter: FILTER_VALIDATE_FLOAT);

                return $float === false
                    ? throw ProcessorException::typeCastFailed(type: 'float', reason: 'not a decimal number')
                    : $float;
            },

            'bool' => static fn(string $v): bool => match (strtolower($v)) {
                'true', '1', 'yes', 'on' => true,
                'false', '0', 'no', 'off', '' => false,
                default => throw ProcessorException::typeCastFailed('bool', 'not one of true, false, 1, 0, yes, no, on, off'),
            },

            'json' => static function (string $v): mixed {
                try {
                    return json_decode(json: $v, associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    throw ProcessorException::typeCastFailed('json', $e->getMessage());
                }
            },

            'csv' => static fn(string $v): array => $v === ''
                ? []
                : array_map('trim', explode(',', $v)),

            'base64' => static function (string $v): string {
                $decoded = base64_decode($v, true);

                if ($decoded === false) {
                    throw ProcessorException::typeCastFailed('base64', 'invalid encoding');
                }

                return $decoded;
            },

            'file' => static function (string $v): string {
                if (!is_file($v) || !is_readable($v)) {
                    throw ProcessorException::typeCastFailed('file', "unreadable: {$v}");
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
