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
    #[\Override]
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

    #[\Override]
    public function supports(string $extension): bool
    {
        return $extension === 'ini';
    }

    // ── Internal ──────────────────────────────────────────────────────

    /**
     * Expands dotted keys into nested arrays, recursing into sections.
     *
     * Each entry becomes a one-path tree; the trees are then replaced
     * recursively in file order, so "a.b" and "a.c" coexist under "a"
     * and a later scalar overwrites an earlier one.
     *
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private function expandDottedKeys(array $data): array
    {
        $trees = [];

        /** @var mixed $value INI values are untyped; sections arrive as arrays */
        foreach ($data as $key => $value) {
            $path = is_string($key) ? explode('.', $key) : [$key];
            $trees[] = $this->nest($path, is_array($value) ? $this->expandDottedKeys($value) : $value);
        }

        return array_replace_recursive([], ...$trees);
    }

    /**
     * Wraps a value in one nested array per path segment.
     *
     * @param non-empty-list<array-key> $path
     *
     * @return array<array-key, mixed>
     */
    private function nest(array $path, mixed $value): array
    {
        $tree = [array_pop($path) => $value];

        foreach (array_reverse($path) as $segment) {
            $tree = [$segment => $tree];
        }

        return $tree;
    }
}
