<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Loader;

/**
 * How a key path came to exist in a TOML document. TOML's rules on
 * redefinition depend on it: values are never extended, a [header]
 * cannot redeclare a table, and dotted keys cannot reopen a table
 * declared by a header.
 *
 * @internal Parser state of TomlLoader.
 *
 * @package KaririCode\Configurator
 * @since   4.2.0 ARFA 1.3
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
