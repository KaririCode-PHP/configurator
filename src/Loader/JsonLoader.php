<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Loader;

use KaririCode\Configurator\Contract\Loader;
use KaririCode\Configurator\Exception\LoaderException;

/**
 * Loads JSON files (RFC 8259) with `json_decode` in strict mode: objects
 * become associative arrays, and a document whose top level is not an
 * object or an array is rejected.
 *
 * Preconditions: the file is UTF-8 text.
 *
 * Thread-safety: stateless; one instance serves every caller.
 *
 * Side effects: load() reads the file.
 *
 * Example:
 *
 *     $data = new JsonLoader()->load('/app/config/services.json');
 *
 * References: RFC 8259, ADR-011. ARFA 1.3 P4: one format per loader.
 *
 * @since 4.2.0
 */
final readonly class JsonLoader implements Loader
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

        try {
            $data = json_decode(json: $content, associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw LoaderException::parseError($path, $exception->getMessage());
        }

        if (!is_array($data)) {
            throw LoaderException::invalidReturnType($path, get_debug_type($data));
        }

        return $data;
    }

    #[\Override]
    public function supports(string $extension): bool
    {
        return $extension === 'json';
    }
}
