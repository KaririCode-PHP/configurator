# ADR-002: Zero-Dependency YAML Parser

## Status

Accepted — V4.2.0 (decided in the 4.0 line; amended by the 4.2 deep
review and by ADR-011)

## Date

2026-11-28 — recorded in this layout; the 4.x tarballs did not date the
decision.

## Context

The 2.x line of the configurator depended on `symfony/yaml`, a
transitive chain of four packages, against the ecosystem's preference
for components without runtime dependencies.

Configuration files use a small subset of YAML 1.2: mappings, nested
structures, sequences, comments, quoted strings, block scalars (| and >)
and scalar type resolution. Anchors, aliases, tags and complex keys are
unused in practice.

### Prior Art

- symfony/yaml and the PECL yaml extension (libyaml): complete parsers,
  as a dependency or an extension.
- PyYAML, used as the reference of the differential tests below.

## Decision

A built-in parser for the configuration subset: block mappings and
sequences, flow collections, single- and double-quoted strings, block
scalars with chomping and indentation indicators, the core schema's
scalar types (null, bool, int, float, inf, nan), inline comments, the
UTF-8 BOM and document markers. Features outside the subset fail loudly
instead of being misread.

## Consequences

### Positive

- No runtime dependency, and no third-party parser in the attack
  surface.

### Negative

- Anchors and aliases are not supported; files that need them use the
  PHP format.
- The package maintains a parser of its own.

## Alternatives Considered

| Alternative | Why not |
|---|---|
| Keep symfony/yaml | four packages in every application's tree for a subset the configurator can parse itself |
| The yaml extension | not installed by default; an extension cannot be required by a library that wants to install everywhere |
| Drop YAML | the format most configuration directories already use |

## Amendment (4.2 deep review)

Differential testing against PyYAML showed that the first, single-pass
design silently corrupted common patterns: `- key: value` items became
strings, sequences written at the parent key's indentation produced
numeric keys, `|-` and `>-` lost their content, and block scalars lost
blank lines. Blank lines and comments were dropped before block scalars
were recognised, and quote scanning treated any `\` before a quote as an
escape.

The parser now runs in two phases. The lexical phase consumes block
scalars and multi-line flow collections from the raw text and rewrites
them as single lines before comments and blank lines go; structural
scans work on a copy with quoted spans masked. The structural phase
builds mappings and sequences from indentation, and a line it cannot
place fails instead of being skipped. The loader matches PyYAML on the
42-document differential corpus; `|` and `>` keep one final line break
(clip chomping); blank lines inside multi-line plain scalars still fold
into a space (a documented limitation).

## Amendment (ADR-011)

Syntax errors are LoaderException CFG_2820 and unsupported features
CFG_2822 (they were 1102 and 1007). Messages describe the broken line,
by the key it declares or the kind of line, instead of repeating it, so
a value on a broken line does not reach a log.

## References

- YAML 1.2.2; PyYAML (differential reference).
- ADR-008, ADR-011; SPEC-CONFIGURATOR §9.1.
