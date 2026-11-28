# ADR-008: INI and TOML Loaders

## Status
Accepted

## Context
V4.2 adds two configuration formats: INI and TOML. Both are common in
the PHP and wider tooling ecosystem, and supporting them lets teams
keep configuration in whatever format their stack already uses.

The constraint is the same as ADR-002: no third-party dependencies.
INI has a native, well-tested PHP function (`parse_ini_string`); TOML
does not, so it needs a hand-written parser like the YAML one.

Configuration TOML files use a limited subset of the TOML 1.0 spec:
key/value pairs, tables, dotted keys, strings, numbers, booleans,
arrays, and inline tables. Datetime types, arrays of tables, and
multi-line strings are rare in configuration and add disproportionate
parser complexity.

## Decision

**INI** — wrap the native `parse_ini_string` with `INI_SCANNER_TYPED`
so values are auto-typed and sections become nested arrays. Dotted
keys (`database.host = ...`) are expanded into nested structures to
match the configuration dot-notation model, so a section and a dotted
key produce the same shape. Parse warnings are converted to a clean
`LoaderException` through a scoped error handler rather than the `@`
suppression operator.

**TOML** — implement a built-in subset parser, single-pass and
line-oriented, mirroring the YAML parser's structure (ADR-002). It
supports:

  - Key/value pairs, bare and quoted keys, dotted keys
  - Tables and dotted tables ([a.b.c])
  - Basic ("...") and literal ('...') strings, with \uXXXX / \UXXXXXXXX
    escapes decoded to UTF-8 without ext-mbstring
  - Integers (decimal, hex, octal, binary, with underscores)
  - Floats (including inf/nan)
  - Booleans
  - Arrays (nested, multi-line)
  - Inline tables ({ a = 1, b = 2 })

Unsupported features are detected and reported through a dedicated
`ConfigurationException::unsupportedTomlFeature` (error code 1008),
separate from the YAML feature error, so the message and code name the
correct format:

  - Arrays of tables ([[table]])
  - Multi-line strings (""" and ''')
  - Date/time values

## Consequences
- Two new formats with zero third-party dependencies.
- Unicode escapes are decoded by a small pure-PHP code-point encoder,
  so the component gains no ext-mbstring or ext-intl requirement.
- The TOML subset omits datetime, arrays of tables, and multi-line
  strings — files needing these should use the PHP format, and the
  parser fails loudly (not silently) when it sees them.
- INI has no native nested-array syntax beyond sections and dotted
  keys, so deep sequences still require PHP/JSON/YAML/TOML.
- Parser complexity is comparable to the YAML parser and stays within
  maintainability bounds.

## Amendment (4.2 deep review)

Differential testing against Python's tomllib showed the first TOML
parser accepting 15 invalid documents, some with silent data loss
(`a = 1` followed by `a.b = 2` discarded the value; duplicate keys
overwrote each other). The parser now enforces TOML 1.0's rules:
strings must be quoted, numbers follow the TOML grammar and are checked
for 64-bit overflow, and a definition registry (pure enum
`TomlDefinition`: Value, DottedTable, HeaderTable, ImplicitTable)
rejects duplicate keys and tables, extension of values, and dotted keys
or headers that reopen each other's tables.

Error reporting: parse errors carry the real file path and the correct
line (line numbers were wrong after multi-line arrays); only
`\UnexpectedValueException` is caught internally — the former catch-all
masked programming errors as parse errors. INI parse warnings are
converted with `\ErrorException` in a scoped handler.

Verification: 33/33 against tomllib (17 identical parses, 16 required
rejections).
