<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Loader;

/**
 * Records how a key path came to exist in a TOML document, because
 * TOML's redefinition rules depend on it: values are never extended, a
 * [header] cannot redeclare a table, and dotted keys cannot reopen a
 * table a header declared.
 *
 * Preconditions: used only by TomlDocument.
 *
 * Thread-safety: an enum; immutable.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     $reopened = $origin === TomlDefinition::HeaderTable;
 *
 * References: TOML 1.0 (Keys, Tables), ADR-008.
 *
 * @internal Parser state of TomlLoader.
 * @since 4.2.0
 */
enum TomlDefinition
{
    /** Holds a value (scalar, array, or inline table). */
    case Value;

    /** Created by a dotted key (a.b = 1 creates table a). */
    case DottedTable;

    /** Declared by a [header]. */
    case HeaderTable;

    /** Created as the parent of a header ([a.b] creates table a). */
    case ImplicitTable;
}
