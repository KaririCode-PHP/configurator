# ADR-007: Ecosystem Contract Promotion

## Status

Accepted — V4.2.0 (decided in the 4.2 line; the names and the aliases
are amended by ADR-009 and ADR-010)

## Date

2026-11-28 — recorded in this layout; the 4.2 tarball did not date the
decision.

## Context

The 4.1 line introduced three contracts as local interfaces of the
configurator: `EnvironmentResolver` (environment resolution),
`ValueProcessor` (value transformation) and `ConfigProvider` (ecosystem
self-configuration). Their responsibility spans several components:

| Contract | Consumers |
|---|---|
| EnvironmentResolver | configurator, dotenv, dependency injection, CLI |
| ValueProcessor | configurator, processor pipeline |
| ConfigProvider | configurator, cache, logger, queue, authentication |

Keeping them in the configurator forces every consumer to depend on it,
against the Dependency Inversion Principle.

### Prior Art

- PSR interfaces, published apart from their implementations.
- kariricode/contract, the ecosystem's package of shared interfaces.

## Decision

The three contracts live in kariricode/contract, and the configurator
types its parameters with them:

```
KaririCode\Contract\Environment\EnvironmentResolver
KaririCode\Contract\Configuration\ValueProcessor
KaririCode\Contract\Configuration\ConfigProvider
```

The 4.2 tarball named them with an `Interface` suffix in an unpublished
contract 4.1 and kept deprecated local aliases; the published contract
(2.9 and later) has the names above, and no release shipped the aliases,
so ADR-009 removed them. Dotenv ships its own implementation of
`EnvironmentResolver` (ADR-010).

## Consequences

### Positive

- Dotenv implements EnvironmentResolver without depending on the
  configurator; the processor pipeline consumes ValueProcessor without a
  cycle; any component declares its configuration through
  ConfigProvider.
- Shared contracts live in one package (ARFA 1.3 P2).

### Negative

- A change to one of these interfaces is a change to kariricode/contract
  and to every implementation in the ecosystem.

## Alternatives Considered

| Alternative | Why not |
|---|---|
| Keep the contracts local | every consumer would depend on the configurator for three interfaces |
| Move them to kariricode/dotenv | the configurator and other consumers would depend on dotenv |
| Keep local aliases of the contract names | no release shipped them; an alias of a name nobody used adds an upgrade step (ADR-009) |

## References

- ARFA 1.3 P2; Cwalina and Abrams, Framework Design Guidelines (3rd ed.)
  §4.1; Martin, the Dependency Inversion Principle.
- ADR-009, ADR-010.
