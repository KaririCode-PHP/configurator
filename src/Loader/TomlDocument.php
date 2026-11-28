<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Loader;

use KaririCode\Configurator\Exception\LoaderException;

/**
 * The tables and keys of one TOML document, or of one inline table,
 * built under the definition rules of TOML 1.0: a key is defined once,
 * a value is never extended, a [header] does not redeclare a table, and
 * dotted keys do not reopen a table that a header declared.
 *
 * @internal Parser state of TomlLoader: one instance per document or inline table.
 *
 * @package KaririCode\Configurator
 * @since   4.2.0
 */
final class TomlDocument
{
    /** @var array<array-key, mixed> The root table: the document as nested arrays */
    public private(set) array $root = [];

    /** @var array<string, TomlDefinition> How each key path came to exist, by pathId() */
    private array $defined = [];

    /**
     * Declares the table of a [header].
     *
     * @param non-empty-list<string> $table
     *
     * @throws LoaderException When the table is already defined or a parent is a value
     */
    public function declareTable(array $table): void
    {
        $root = $this->root;
        $cursor = &$root;
        $last = count($table) - 1;

        foreach ($table as $depth => $segment) {
            $id = $this->pathId(array_slice($table, 0, $depth + 1));
            $origin = $this->defined[$id] ?? null;

            if ($depth === $last) {
                if ($origin !== null && $origin !== TomlDefinition::ImplicitTable) {
                    throw LoaderException::invalidSyntax(sprintf("table '%s' is already defined", implode('.', $table)));
                }

                $this->defined[$id] = TomlDefinition::HeaderTable;
            } elseif ($origin === TomlDefinition::Value) {
                throw LoaderException::invalidSyntax(sprintf("key '%s' is not a table", $segment));
            } else {
                $this->defined[$id] = $origin ?? TomlDefinition::ImplicitTable;
            }

            if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }

            $cursor = &$cursor[$segment];
        }

        unset($cursor);
        $this->root = $root;
    }

    /**
     * Assigns $value at $table + $key, where $table is the current
     * [header] (empty at the top level) and $key the possibly dotted key.
     *
     * @param list<string>                                   $table
     * @param non-empty-list<string>                         $key
     * @param string|int|float|bool|array<array-key, mixed> $value
     *
     * @throws LoaderException When a definition rule is violated
     */
    public function assign(array $table, array $key, string|int|float|bool|array $value): void
    {
        $path = [...$table, ...$key];
        $leaf = array_pop($path);
        $root = $this->root;
        $cursor = &$root;

        foreach ($path as $depth => $segment) {
            $this->claimParent(array_slice($path, 0, $depth + 1), $segment, dotted: $depth >= count($table));

            if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }

            $cursor = &$cursor[$segment];
        }

        $id = $this->pathId([...$path, $leaf]);

        if (isset($this->defined[$id])) {
            throw LoaderException::invalidSyntax(sprintf("duplicate key '%s'", implode('.', [...$path, $leaf])));
        }

        $this->defined[$id] = TomlDefinition::Value;
        $cursor[$leaf] = $value;
        unset($cursor);
        $this->root = $root;
    }

    /**
     * Checks that the table at $prefix, whose last segment is $segment,
     * may hold a key, and records the tables a dotted key creates
     * ($dotted is false for the segments of the enclosing [header]).
     *
     * @param list<string> $prefix
     *
     * @throws LoaderException When the prefix is a value, or a dotted key reopens a declared table
     */
    private function claimParent(array $prefix, string $segment, bool $dotted): void
    {
        $id = $this->pathId($prefix);
        $origin = $this->defined[$id] ?? null;

        if ($origin === TomlDefinition::Value) {
            throw LoaderException::invalidSyntax(sprintf("key '%s' is not a table", $segment));
        }

        if (!$dotted) {
            return;
        }

        if ($origin === TomlDefinition::HeaderTable || $origin === TomlDefinition::ImplicitTable) {
            throw LoaderException::invalidSyntax(sprintf("table '%s' is already defined", $segment));
        }

        $this->defined[$id] = TomlDefinition::DottedTable;
    }

    /**
     * Unambiguous identifier of a key path (length-prefixed segments).
     *
     * @param list<string> $segments
     */
    private function pathId(array $segments): string
    {
        return implode('', array_map(static fn(string $segment): string => strlen($segment) . ':' . $segment, $segments));
    }
}
