# ADR-011: Error Codes from Block 2800–2899 on the Configuration Family

## Status

Accepted — V4.2.0

## Date

2026-11-28

## Context

The 4.2 tarball threw a concrete `ConfigurationException extends
\RuntimeException` with three subclasses (`LoaderException`,
`ValidationException`, `MergeException`) and 19 codes kept as private
constants in 1000–1009, 1100–1104, 1200–1202 and 1300. Those numbers
overlap kariricode/async-primitives, which released AP_1000–AP_1450 in
4.1.0. The exceptions implemented none of kariricode/contract's families,
so an edge handler could not tell a configuration fault from any other
`RuntimeException`, and a code alone did not name the package. The
parsers signalled syntax errors with SPL `\UnexpectedValueException`, the
INI loader with `\ErrorException`, and an encryption key of the wrong size
raised an uncoded `\InvalidArgumentException`. Some messages repeated
configuration values: a type cast that failed quoted its payload, and
`getBool()` quoted the string it rejected. `ValidationException` exposed
its violations through `getViolations()`. 1.0.0 threw one uncoded
`ConfigurationException`.

KaririCode Specification V4.3.3 §8.4 identifies a failure by its family
plus a numeric code from a block registered in CODE-BLOCKS.md of
kariricode/exception, whose family bases put `[PREFIX_code]` at the start
of every message and neutralise control characters.

### Prior Art

- kariricode/exception 2.0: `ErrorCode`, `CodeBlock`, the family bases and
  the registry rules (rule 3: claim the lowest free block before the first
  coded release; rule 5: collisions).
- kariricode/dotenv ADR-010: the same move, with a marker that keeps the
  old catch-all name.
- kariricode/contract 2.x: `Exception\Configuration` ("operators fix it,
  callers cannot… messages must not carry secrets").

## Decision

1. The package claims block 2800–2899 under the prefix CFG, the lowest
   free block of 100 codes. Rule 5 would move the tarball's 1000–1399 to a
   block of the same size with a constant offset; the configurator never
   released a code, so it takes a block of 100 under rule 3 and lays its
   19 codes out by area. The registry records the collision.

   | Range | Area | Exception |
   |---|---|---|
   | 2800–2809 | access to a built configuration | `AccessException` |
   | 2810–2819 | formats and files | `LoaderException` |
   | 2820–2829 | parsing | `LoaderException` |
   | 2830–2839 | interpolation and casts | `ProcessorException` |
   | 2840–2849 | encryption | `ProcessorException` |
   | 2850–2859 | schema validation | `ValidationException` |
   | 2860–2869 | merging | `MergeException` |
   | 2870–2899 | reserved | |

2. `Exception\ConfigCode` is the code table, a backed enum implementing
   `ErrorCode` (ARFA 1.3 E-003: `getCode()` reads the integer).
3. `Exception\ConfigurationException` becomes the marker interface every
   exception implements, so `catch (ConfigurationException $e)` still
   covers the package; it exposes the `ConfigCode` case as `$errorCode`.
4. Every concrete exception is final and extends the kariricode/exception
   base of the Configuration family. Every failure of this package is a
   configuration an operator fixes: a file, a value, a key, a schema, a
   key size. The HTTP edge answers 500 and keeps the detail in the logs.
5. The general factories of the old base move by area: `typeMismatch()`
   and `immutableViolation()` to the new `AccessException`;
   `unsupportedFormat()` and the two unsupported-feature factories to
   `LoaderException`; interpolation, casts and encryption to the new
   `ProcessorException`, which also gains `invalidEncryptionKey()` in
   place of the SPL exception. `pathNotFound()` is removed: the
   Configurator contract says `get()` never throws, so no implementation
   may raise it.
6. Parsers raise `LoaderException::invalidSyntax($reason)`, which knows no
   file; the loader that read the file reports it as `parseError()` with
   the same `$reason`. The INI loader turns a parse warning into the same
   failure without throwing from its error handler. No `throw new` of an
   SPL exception remains in `src`.
7. Messages name files, keys, lines, variables and types; never a
   configuration value. The parsers describe the broken line (the key it
   declares, or the kind of line) instead of repeating it; a failed cast
   says why (the JSON error, the allowed boolean words); `getBool()`
   describes the string it rejects instead of quoting it. Identifiers go
   through the base's `quote()`.
8. `ValidationException::$violations` is a public readonly property; the
   `getViolations()` getter goes.

## Consequences

### Positive

- `[CFG_2820]` in a log names this package and the failure; an edge
  handler maps the Configuration family to 500 without knowing the
  package.
- One `catch` still covers the package, and a test or a tool can match the
  `ConfigCode` case instead of a number.
- A secret in a configuration file does not reach a log through an
  exception message.

### Negative

- Code that compared `getCode()` with the 1000–1399 numbers, or caught the
  old subclasses by their parent class, changes: the old base is an
  interface now, and every number moved. No release threw the old codes.
- A message that describes a rejected value instead of quoting it takes
  longer to act on. TOML errors carry the line; YAML structural errors
  name the key and the indentation but not the line, because the parser
  works on normalized lines (block scalars and flow collections joined)
  that no longer map one to one to the file.

## Alternatives Considered

| Alternative | Why not |
|---|---|
| Keep 1000–1399 | async-primitives released codes from that block; rule 5 gives it to them |
| A 400-code block with the old layout shifted by a constant | the old layout had no release to stay compatible with; 19 codes fit a block of 100 |
| Split the exceptions across families (NotFound for missing files, Runtime for I/O) | a missing or unreadable configuration file is fixed by an operator, not retried by a caller; NotFound would answer 404 and Runtime suggests a retry |
| Keep SPL exceptions as the parsers' internal signal | an uncoded exception that escapes by mistake carries no family and no code; the conformance suite can check that none is thrown |
| Keep `pathNotFound()` for custom implementations | it contradicts the contract it would serve |

## References

- KaririCode Specification V4.3.3 §8.3, §8.4.
- kariricode/exception CODE-BLOCKS.md (rules 3–5) and README.
- kariricode/dotenv ADR-010.
- ADR-002, ADR-003, ADR-004, ADR-005, ADR-008.
