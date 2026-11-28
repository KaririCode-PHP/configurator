<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Loader;

use KaririCode\Configurator\Contract\Loader;
use KaririCode\Configurator\Exception\LoaderException;

/**
 * Loads configuration from INI files.
 *
 * Uses native parse_ini_string with INI_SCANNER_TYPED, so values
 * are auto-typed: true/false/on/off/yes/no become booleans, numeric
 * strings become int or float, and null/none become null. Sections
 * ([section]) become nested arrays.
 *
 * Dotted keys are expanded into nested structures to match the
 * configuration dot-notation model. Both forms produce the same
 * shape:
 *
 *   [database]              database.host = localhost
 *   host = localhost   ≡    (at top level)
 *
 * yields ['database' => ['host' => 'localhost']] either way.
 *
 * Native parsing means no code execution (unlike PHP files) and no
 * hand-written grammar. Scope: INI has no native array syntax beyond
 * repeated keys, so nested sequences require the PHP/JSON/YAML
 * formats.
 *
 * @package KaririCode\Configurator
 * @since   4.2.0 ARFA 1.3
 */
final readonly class IniLoader implements Loader
{
    public function load(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw LoaderException::fileNotFound($path);
        }

        $content = file_get_contents($path);

        if ($content === false) {
            throw LoaderException::fileUnreadable($path);
        }

        // Convert parse warnings into a clean, catchable failure instead
        // of suppressing them with the @ operator.
        set_error_handler(static function (int $severity, string $message): never {
            throw new \ErrorException($message, 0, $severity);
        });

        try {
            $parsed = parse_ini_string($content, true, INI_SCANNER_TYPED);
        } catch (\ErrorException $exception) {
            throw LoaderException::parseError($path, $exception->getMessage());
        } finally {
            restore_error_handler();
        }

        if ($parsed === false) {
            throw LoaderException::parseError($path, 'malformed INI syntax');
        }

        return $this->expandDottedKeys($parsed);
    }

    public function supports(string $extension): bool
    {
        return $extension === 'ini';
    }

    // ── Internal ──────────────────────────────────────────────────────

    /**
     * Expands dotted keys into nested arrays, recursing into sections.
     *
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private function expandDottedKeys(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $value = $this->expandDottedKeys($value);
            }

            if (is_string($key) && str_contains($key, '.')) {
                $this->setNested($result, explode('.', $key), $value);

                continue;
            }

            $result[$key] = $this->mergeValue($result[$key] ?? null, $value);
        }

        return $result;
    }

    /**
     * Writes a value at a nested path, creating intermediate arrays.
     *
     * @param array<array-key, mixed> $target
     * @param list<string>         $segments
     */
    private function setNested(array &$target, array $segments, mixed $value): void
    {
        $cursor = &$target;

        foreach ($segments as $depth => $segment) {
            if ($depth === count($segments) - 1) {
                $cursor[$segment] = $this->mergeValue($cursor[$segment] ?? null, $value);

                return;
            }

            if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }

            $cursor = &$cursor[$segment];
        }
    }

    /**
     * Merges nested arrays produced by overlapping dotted keys so that
     * "a.b" and "a.c" coexist under "a" instead of overwriting.
     */
    private function mergeValue(mixed $existing, mixed $incoming): mixed
    {
        if (is_array($existing) && is_array($incoming)) {
            foreach ($incoming as $k => $v) {
                $existing[$k] = $this->mergeValue($existing[$k] ?? null, $v);
            }

            return $existing;
        }

        return $incoming;
    }
}
