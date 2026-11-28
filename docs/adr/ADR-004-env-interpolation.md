# ADR-004: Environment Variable Interpolation via Value Processor Chain

## Status

Accepted — V4.2.0 (decided in the 4.1 line)

## Date

2026-11-28 — recorded in this layout; the 4.x tarballs did not date the
decision.

## Context

Configuration that varies between deploys — credentials, hosts, keys —
comes from the environment (The Twelve-Factor App, 2012). Without
interpolation, the library cannot be adopted where those values must
stay out of the files.

### Prior Art

- Symfony Config `%env(VAR)%`, Laravel `env()`, Spring Boot `${VAR}`.
- Docker Compose variable substitution and POSIX parameter expansion
  (`${VAR:-default}`).

## Decision

A value processor, `EnvironmentProcessor`, interpolates `${VAR}`,
`${VAR:default}` and `${VAR:-default}`, with nested defaults, during
seal():

1. The `${VAR:default}` syntax rather than `%env(VAR)%`: universal, safe
   in YAML without quoting, compatible with Docker Compose.
2. Strict mode by default: an undefined variable without a default fails
   the boot (ProcessorException CFG_2830, ADR-011).
3. A processor chain (ADR-005): interpolation feeds casts, casts feed
   decryption.
4. The environment is read through `Environment\EnvironmentResolver` of
   kariricode/contract, so tests and other sources (a .env store, a
   vault) replace `$_ENV` and getenv() (ADR-007, ADR-010).

```
raw values → EnvironmentProcessor → TypeCastProcessor → EncryptedValueProcessor → validation
```

## Consequences

### Positive

- Environment-specific values work in YAML, JSON, INI and TOML alike.
- A missing variable fails at startup, not at request time.
- InMemoryEnvironmentResolver makes interpolation deterministic in tests.

### Negative

- A literal `${` in a value has to be written so that it does not look
  like a reference.

## Alternatives Considered

| Alternative | Why not |
|---|---|
| `%env(VAR)%` | Symfony-specific, and `%` needs quoting in YAML |
| Lenient by default (undefined becomes empty) | a missing credential would become an empty string in production |
| Interpolation inside the loaders | every format would repeat it, and processors could not compose |

## References

- The Twelve-Factor App, III. Config; POSIX.1-2017 §2.6.2.
- ADR-005, ADR-007, ADR-010, ADR-011.
