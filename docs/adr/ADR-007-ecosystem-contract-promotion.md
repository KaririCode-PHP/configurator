# ADR-007: Ecosystem Contract Promotion

## Status
Accepted

## Context

KaririCode\Configurator V4.1 introduced three contracts as local interfaces:

- `EnvironmentResolver` — environment variable resolution
- `ValueProcessor` — configuration value transformation chain
- `ConfigProvider` — ecosystem self-configuration

These contracts were scoped to the Configurator namespace, but their domain responsibility spans multiple ecosystem components:

| Contract | Consumers | Owner (canonical source) |
|---|---|---|
| EnvironmentResolver | Configurator, Dotenv, DI Container, CLI | **Dotenv** (env loading is its job) |
| ValueProcessor | Configurator, ProcessorPipeline | **Configurator** (config-phase transforms) |
| ConfigProvider | Configurator, Cache, Logger, Queue, Auth | **Configurator** (config registration) |

Keeping contracts local to Configurator creates coupling: any component consuming these interfaces must depend on `kariricode/configurator`, violating the Dependency Inversion Principle.

## Decision

**Promote all three contracts to `kariricode/contract ^4.1`** as ecosystem-level interfaces:

```
KaririCode\Contract\Environment\EnvironmentResolverInterface
KaririCode\Contract\Configuration\ValueProcessorInterface
KaririCode\Contract\Configuration\ConfigProviderInterface
```

Configurator retains local type aliases (via `extends`) for backward compatibility during the 4.x cycle:

```php
// kariricode/configurator — backward compat alias
namespace KaririCode\Configurator\Contract;

interface EnvironmentResolver extends \KaririCode\Contract\Environment\EnvironmentResolverInterface {}
```

## Consequences

### Positive
- **Dotenv** can implement `EnvironmentResolverInterface` without depending on Configurator
- **ProcessorPipeline** can consume `ValueProcessorInterface` without circular dependency
- **Any component** can implement `ConfigProviderInterface` to self-declare its config needs
- Contract ownership follows ARFA 1.3 P2 (Composability): shared contracts live in `kariricode/contract`

### Negative
- Existing code using `KaririCode\Configurator\Contract\EnvironmentResolver` continues to work (extends the ecosystem interface), but should migrate to the canonical interface in next major version

### Integration Map

```
kariricode/contract ^4.1
├── Environment\EnvironmentResolverInterface
├── Configuration\ValueProcessorInterface
└── Configuration\ConfigProviderInterface

kariricode/dotenv ^4.x
└── Bridge\DotenvEnvironmentResolver implements EnvironmentResolverInterface
    └── Exposes loaded .env vars + system fallback

kariricode/configurator ^4.2
├── Contract\EnvironmentResolver extends EnvironmentResolverInterface (compat alias)
├── Contract\ValueProcessor extends ValueProcessorInterface (compat alias)
├── Contract\ConfigProvider extends ConfigProviderInterface (compat alias)
├── Environment\SystemEnvironmentResolver implements EnvironmentResolverInterface
├── Environment\InMemoryEnvironmentResolver implements EnvironmentResolverInterface
├── Processor\EnvironmentProcessor implements ValueProcessorInterface
├── Processor\TypeCastProcessor implements ValueProcessorInterface
├── Processor\EncryptedValueProcessor implements ValueProcessorInterface
└── Integration\DotenvBridge (convenience adapter)
```

## References
- ARFA 1.3 §P2: Composability Principle
- Framework Design Guidelines (Cwalina & Abrams, 3rd ed.) §4.1: Interface Design
- Dependency Inversion Principle (Martin, SOLID)
