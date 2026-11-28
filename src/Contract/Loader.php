<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Contract;

use KaririCode\Configurator\Exception\ConfigurationException;
use KaririCode\Configurator\Exception\LoaderException;

/**
 * Configuration file loader contract.
 *
 * Each implementation handles a single file format (PHP, JSON, YAML,
 * INI, TOML).
 * Loaders are stateless and thread-safe — they parse the file and
 * return a normalized associative array.
 *
 * @package KaririCode\Configurator
 * @since   4.0.0 ARFA 1.3
 */
interface Loader
{
    /**
     * Loads and parses a configuration file.
     *
     * @param string $path Absolute path to the configuration file
     *
     * @return array<array-key, mixed> Parsed configuration data
     *
     * @throws LoaderException        When the file is missing, unreadable, or malformed
     *                                (custom loaders that confine paths may use
     *                                LoaderException::pathTraversal(), code 1104)
     * @throws ConfigurationException When the file uses a syntax feature the loader
     *                                does not support (YAML: 1007, TOML: 1008)
     */
    public function load(string $path): array;

    /**
     * Checks whether this loader supports the given file extension.
     */
    public function supports(string $extension): bool;
}
