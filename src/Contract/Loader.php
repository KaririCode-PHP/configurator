<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Contract;

use KaririCode\Configurator\Exception\LoaderException;

/**
 * Parses one configuration file format into a nested array;
 * Configuration picks the loader by file extension.
 *
 * Preconditions: load() receives a path the caller chose to load;
 * supports() answers for a lower-case extension without the dot. A
 * loader reports every failure as LoaderException and never repeats file
 * content in a message.
 *
 * Thread-safety: implementations are stateless; one instance serves
 * every file.
 *
 * Side effects: load() reads the file; PhpLoader also executes it.
 *
 * Example:
 *
 *     $config->registerLoader(new NeonLoader(), 'neon');
 *
 * References: ADR-002, ADR-008, ADR-011. ARFA 1.3 P4: one format per
 * implementation.
 *
 * @since 4.2.0
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
     * @throws LoaderException When the file is missing, unreadable or malformed
     *                         (CFG_2811, CFG_2812, CFG_2820), or uses a syntax
     *                         feature the loader does not support (YAML:
     *                         CFG_2822, TOML: CFG_2823); custom loaders that
     *                         confine paths may use pathTraversal() (CFG_2813)
     */
    public function load(string $path): array;

    /**
     * Checks whether this loader supports the given file extension.
     */
    public function supports(string $extension): bool;
}
