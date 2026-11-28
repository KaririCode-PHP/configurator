<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Loader;

use KaririCode\Configurator\Contract\Loader;
use KaririCode\Configurator\Exception\LoaderException;

/**
 * Loads PHP files that return an array, the fastest format once OPcache
 * holds the compiled file, and the only one that may compute values
 * (getenv(), conditionals, closures).
 *
 * The path is resolved to its real path (symlinks and `..` followed) and
 * the file runs in a static closure, so it cannot reach the loader.
 * Which files may load is the caller's decision: the loader confines
 * nothing.
 *
 * Preconditions: the file is trusted code; it returns an array.
 *
 * Thread-safety: stateless; one instance serves every caller.
 *
 * Side effects: load() executes the file, with whatever side effects its
 * code has.
 *
 * Example:
 *
 *     $data = new PhpLoader()->load('/app/config/app.php');
 *
 * References: ADR-011; OWASP ASVS 5.0 V15 (the configuration is code the
 * deployment trusts). ARFA 1.3 P4: one format per loader.
 *
 * @since 4.2.0
 */
final readonly class PhpLoader implements Loader
{
    #[\Override]
    public function load(string $path): array
    {
        $realPath = $this->validatePath($path);

        // Isolated scope — prevents the included file from accessing $this
        /** @var mixed $result a configuration file may return anything; checked below. */
        $result = (static function (string $file): mixed {
            /** @psalm-suppress UnresolvableInclude including a caller-supplied configuration file is this loader's purpose */
            return require $file;
        })($realPath);

        if (!is_array($result)) {
            throw LoaderException::invalidReturnType($path, get_debug_type($result));
        }

        return $result;
    }

    #[\Override]
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
