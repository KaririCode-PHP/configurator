# ADR-008: INI and TOML Loaders

## Status

Accepted — V4.2.0 (decided in the 4.2 line; amended by the 4.2 deep
review and by ADR-011)

## Date

2026-11-28 — recorded in this layout; the 4.2 tarball did not date the
decision.

## Context

The 4.2 line adds two formats, INI and TOML, common across PHP and other
tooling, so teams can keep configuration in the format their stack
already uses. The constraint is that of ADR-002: no third-party
dependencies. INI has a native, well-tested PHP function
(`parse_ini_string`); TOML does not, so it needs a parser of its own.

Configuration TOML uses a subset of TOML 1.0: key/value pairs, tables,
dotted keys, strings, numbers, booleans, arrays and inline tables.
Date-time values, arrays of tables and multi-line strings are rare in
configuration and add disproportionate parser complexity.

### Prior Art

- PHP's `parse_ini_string()` with INI_SCANNER_TYPED.
- yosymfony/toml and devium/toml: complete TOML parsers, as
  dependencies.
- Python's tomllib, used as the reference of the differential tests.

## Decision

**INI**: wrap `parse_ini_string` with INI_SCANNER_TYPED, so values come
typed and sections become nested arrays. Dotted keys
(`database.host = ...`) expand into nested structures, so a section and
a dotted key give the same shape. Parse warnings become a
LoaderException through a scoped error handler, never the `@` operator.

**TOML**: a built-in subset parser that mirrors the YAML parser: bare,
quoted and dotted keys; tables; basic and literal strings with
`\uXXXX`/`\UXXXXXXXX` decoded to UTF-8 without ext-mbstring; integers
(decimal, hex, octal, binary, with underscores); floats with inf and
nan; booleans; arrays (nested, multi-line); inline tables. Arrays of
tables, multi-line strings and date-time values fail with an
unsupported-feature error of their own, distinct from YAML's, so the
message and the code name the format.

## Consequences

### Positive

- Two formats more, with no runtime dependency and no extension.
- The parser fails loudly on what it does not support.

### Negative

- Files that need date-times, arrays of tables or multi-line strings use
  another format.
- INI has no nested-list syntax beyond sections and dotted keys.

## Alternatives Considered

| Alternative | Why not |
|---|---|
| A TOML library | a runtime dependency (ADR-002) for a subset the configurator can parse |
| Hand-written INI parsing | PHP's parser is native and battle-tested |
| Full TOML 1.0 | date-times and arrays of tables would double the parser for features configuration rarely uses |

## Amendment (4.2 deep review)

Differential testing against tomllib showed the first TOML parser
accepting 15 invalid documents, some with silent data loss (`a = 1`
followed by `a.b = 2` dropped the value; duplicate keys overwrote each
other). The parser now enforces TOML 1.0's rules: strings must be
quoted, numbers follow the TOML grammar and are checked for 64-bit
overflow, and a definition registry (the pure enum `TomlDefinition`)
rejects duplicate keys and tables, extended values, and dotted keys or
headers that reopen each other's tables. Parse errors carry the real
file path and the correct line (line numbers were wrong after
multi-line arrays). Verification: 33 of 33 documents agree with tomllib
(17 identical parses, 16 required rejections).

## Amendment (ADR-009, ADR-011)

The definition rules live in `Loader\TomlDocument` (ADR-009). Parse
errors are LoaderException CFG_2820 and unsupported features CFG_2823
(they were 1102 and 1008); the parsers raise
`LoaderException::invalidSyntax()` instead of SPL exceptions, the INI
loader captures warnings without throwing from its handler, and messages
no longer repeat values.

## References

- TOML 1.0.0; Python tomllib (differential reference).
- ADR-002, ADR-009, ADR-011; SPEC-CONFIGURATOR §9.2, §9.3.
