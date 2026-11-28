# ADR-005: Value Processor Chain for Extensible Transformations

## Status

Accepted — V4.2.0 (decided in the 4.1 line)

## Date

2026-11-28 — recorded in this layout; the 4.x tarballs did not date the
decision.

## Context

Configuration values often need transformations the loaders do not
provide: interpolation, casts from string representations, decryption
of secrets, and domain conversions (URLs, DSNs).

### Prior Art

- Symfony's `EnvVarProcessorInterface`, tied to the DI container.
- Spring Boot's `@Value` with a `ConversionService`.

## Decision

The `ValueProcessor` contract of kariricode/contract and a
`ValueProcessorChain` composite apply transformations to every scalar of
the tree at seal() time, in registration order: each processor whose
`supports()` accepts the current value transforms it and hands the
result to the next, so `${PORT}` → `int:8080` → `8080` in one pass.

Built in: EnvironmentProcessor (`${VAR:default}`), TypeCastProcessor
(`int:`, `bool:`, `json:`, `csv:` and more, extensible through
`registerCaster()`) and EncryptedValueProcessor (`ENC(...)`, AES-256-GCM).

## Consequences

### Positive

- Transformations need no DI container and compose.
- Processing runs before validation, so the schema checks the values the
  application will read.

### Negative

- Order matters: a processor registered before interpolation sees the
  reference, not its value.

## Alternatives Considered

| Alternative | Why not |
|---|---|
| Transform on read | every read would repeat the work, and validation would check raw strings |
| One processor with a fixed list of features | no extension point for domain conversions |
| Couple to a DI container's parameter system | the configurator must work without a container |

## References

- ADR-004, ADR-011 (`ProcessorException`, CFG_2830–CFG_2842).
- SPEC-CONFIGURATOR §6; NIST SP 800-38D.
