<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Loader;

use KaririCode\Configurator\Contract\Loader;
use KaririCode\Configurator\Exception\LoaderException;

/**
 * Loads configuration from JSON files.
 *
 * Uses native json_decode with JSON_THROW_ON_ERROR for strict
 * parsing. Supports standard JSON (RFC 8259) including nested
 * objects, arrays, and all scalar types.
 *
 * @package KaririCode\Configurator
 * @since   4.0.0 ARFA 1.3
 */
final readonly class JsonLoader implements Loader
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

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw LoaderException::parseError($path, $exception->getMessage());
        }

        if (!is_array($data)) {
            throw LoaderException::invalidReturnType($path, get_debug_type($data));
        }

        return $data;
    }

    public function supports(string $extension): bool
    {
        return $extension === 'json';
    }
}
