<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Loader;

use KaririCode\Configurator\Contract\Loader;
use KaririCode\Configurator\Exception\LoaderException;

/**
 * Loads configuration from native PHP files that return arrays.
 *
 * Fastest loader in the chain — the file is compiled by PHP's
 * opcache on first access, making subsequent loads effectively
 * a memory read. Supports closures, conditionals, and dynamic
 * values (e.g. getenv() calls inside the returned array).
 *
 * Security: the file is resolved to its canonical real path
 * (following symlinks and '..') and executed in an isolated scope
 * so the included file cannot access $this. Path containment against
 * an allowed base directory is the caller's responsibility — this
 * loader receives an already-resolved path from the directory scan
 * and does not restrict which paths may be loaded.
 *
 * @package KaririCode\Configurator
 * @since   4.0.0 ARFA 1.3
 */
final readonly class PhpLoader implements Loader
{
    public function load(string $path): array
    {
        $realPath = $this->validatePath($path);

        // Isolated scope — prevents the included file from accessing $this
        $result = (static function (string $file): mixed {
            return require $file;
        })($realPath);

        if (!is_array($result)) {
            throw LoaderException::invalidReturnType($path, get_debug_type($result));
        }

        return $result;
    }

    public function supports(string $extension): bool
    {
        return $extension === 'php';
    }

    private function validatePath(string $path): string
    {
        $realPath = realpath($path);

        if ($realPath === false) {
            throw LoaderException::fileNotFound($path);
        }

        if (!is_readable($realPath)) {
            throw LoaderException::fileUnreadable($path);
        }

        return $realPath;
    }
}
