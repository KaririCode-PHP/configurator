# ADR-002: Zero-Dependency YAML Parser

## Status
Accepted

## Context
The V2.x Configurator depended on `symfony/yaml` for YAML support.
This created a transitive dependency chain of 4 packages and violated
the KaririCode ecosystem principle of zero external dependencies.

Configuration YAML files use a limited subset of the YAML 1.2 spec:
key-value mappings, nested structures, sequences, comments, quoted
strings, block scalars (| and >), and automatic type casting. Features
like anchors (&), aliases (*), tags (!!), and complex keys are unused
in practice for configuration files.

## Decision
Implement a built-in YAML parser covering the configuration subset.
The parser is a single-pass, line-oriented algorithm that tracks
indentation depth to determine nesting. It supports:

  - Block mappings and sequences
  - Flow collections ([...] and {...})
  - Quoted strings (single and double)
  - Block scalars (literal | and folded >)
  - Type casting (null, bool, int, float, inf, nan)
  - Inline comments
  - UTF-8 BOM stripping
  - Document markers (--- and ...)

## Consequences
- Zero external dependencies for the entire component.
- Reduced attack surface (no third-party parser code).
- Anchors and aliases are not supported — users needing these
  features should use PHP format instead.
- Parser complexity is ~400 lines, well within maintainability bounds.

## Amendment (4.2 deep review)

Differential testing of the PHP loader against PyYAML showed that the
original single-pass design silently corrupted common configuration
patterns: `- key: value` list items became strings, sequences written at
the parent key's indentation produced numeric keys, `|-` / `>-` lost
their content, and block scalars lost blank lines. Two root causes:
blank lines and comments were dropped before block scalars were
recognised, and quote scanning treated any `\` before a quote as an
escape (breaking `"C:\\"`).

The parser now runs in two phases:

1. **Lexical** — block scalars (with chomping and indentation
   indicators) and multi-line flow collections are consumed from the raw
   text and rewritten as single-line equivalents; only then are comments
   and blank lines removed. Structural scans work on a copy in which
   quoted spans are masked, with real escape handling.
2. **Structural** — mappings and sequences are built from indentation;
   a line that cannot be placed raises LoaderException 1102 instead of
   being skipped.

Consequences: the loader matches PyYAML on the 42-document differential
corpus; unsupported-feature errors keep code 1007 (previously wrapped as
1102); `|` and `>` now keep one final line break (YAML clip chomping);
the parser grew to ~630 lines of code (plus ~210 of documentation); blank lines inside multi-line plain scalars
still fold into a space (documented limitation).
