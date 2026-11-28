<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Loader;

use KaririCode\Configurator\Contract\Loader;
use KaririCode\Configurator\Exception\LoaderException;

/**
 * Loads INI files with PHP's own parser (`parse_ini_string`,
 * INI_SCANNER_TYPED): booleans, numbers and null come typed, sections
 * become nested arrays, and dotted keys expand into the same nesting, so
 * `[database] host = x` and `database.host = x` both give `['database'
 * => ['host' => 'x']]`.
 *
 * Native parsing executes nothing and needs no grammar of its own. INI
 * has no array syntax beyond repeated keys; nested lists need PHP, JSON,
 * YAML or TOML.
 *
 * Preconditions: the file is UTF-8 text; any parser warning fails the
 * load.
 *
 * Thread-safety: stateless; one instance serves every caller.
 *
 * Side effects: load() reads the file and installs an error handler for
 * the duration of the parse.
 *
 * Example:
 *
 *     $data = new IniLoader()->load('/app/config/app.ini');
 *
 * References: ADR-008, ADR-011. ARFA 1.3 P4: one format per loader.
 *
 * @since 4.2.0
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

        // Capture parse warnings as the failure's reason instead of
        // suppressing them with the @ operator; any warning fails the load.
        $warning = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            $parsed = parse_ini_string(ini_string: $content, process_sections: true, scanner_mode: INI_SCANNER_TYPED);
        } finally {
            restore_error_handler();
        }

        if ($parsed === false || $warning !== null) {
            throw LoaderException::parseError($path, $warning ?? 'malformed INI syntax');
        }

        return $this->expandDottedKeys($parsed);
    }

    #[\Override]
    public function supports(string $extension): bool
    {
        return $extension === 'ini';
    }

    // ── Internal ─────────────────────────────────────────────────────────

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
