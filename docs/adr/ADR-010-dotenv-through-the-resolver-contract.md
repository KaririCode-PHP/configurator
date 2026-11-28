# ADR-010: Dotenv through the EnvironmentResolver Contract

## Status

Accepted — V4.2.0

## Date

2026-11-28

## Context

The 4.2 tarball integrated kariricode/dotenv by duck typing:
`ConfigurationBuilder::withDotenv(object $dotenv)` accepted an
`EnvironmentResolver`, or any object with an `all()` method, whose result
it copied into an `InMemoryEnvironmentResolver` and documented as
`array<string, string>` without checking it; an object without `all()`
failed at run time with an `InvalidArgumentException`.
`Integration\DotenvBridge::configure()` was a static shortcut for
`ConfigurationBuilder::create()->withDotenv()`. The docs also named a
`DotenvEnvironmentResolver::fromLoaded()` in kariricode/dotenv that no
release of that package had.

Three things changed since. kariricode/contract 2.9 defines
`Environment\EnvironmentResolver` as the one way components read the
environment. kariricode/dotenv 4.5.0 implements it over a loaded store in
`Integration\DotenvEnvironmentResolver` (its ADR-011, decision D14 of the
2026-11-27 inventory), wrapping the `Dotenv` instance rather than copying
its values. And kariricode/dotenv has no `all()`: its store is read
through `variables()`.

### Prior Art

- kariricode/contract 2.9: `Environment\EnvironmentResolver` (`get()`,
  `has()`).
- kariricode/dotenv ADR-011: the bridge lives with the store; consumers
  depend on the contract only.
- kariricode/event-dispatcher ADR-011 and kariricode/parser's
  `Integration` namespace: the same split between a component's types and
  the contract.

## Decision

1. `withDotenv()` takes an `EnvironmentResolver`; the `object` parameter
   and the `all()` branch go. It composes the resolver with
   `SystemEnvironmentResolver` unless `$systemFallback` is false, as
   before, so the store's values win and the process fills the gaps.
2. `Integration\DotenvBridge` is removed: with the contract as the
   parameter type it wraps one builder call.
3. The package keeps no dependency on kariricode/dotenv; `suggest` names
   the version that ships the resolver.

```php
$dotenv = new \KaririCode\Dotenv\Dotenv('/app');
$dotenv->load();

$config = ConfigurationBuilder::create()
    ->withDotenv(new \KaririCode\Dotenv\Integration\DotenvEnvironmentResolver($dotenv))
    ->withDirectory('/app/config')
    ->build();
```

## Consequences

### Positive

- The type system checks the integration: a wrong argument fails static
  analysis instead of a run-time `method_exists()`.
- Any source of environment values works the same way: a test's
  `InMemoryEnvironmentResolver`, a vault-backed resolver, or a `.env`
  store.
- The resolver reads the store when a value is interpolated, so a builder
  can be prepared before the store loads.

### Negative

- Code written against the 4.2 tarball's `withDotenv($dotenv)` or
  `DotenvBridge::configure($dotenv)` wraps the store in
  `DotenvEnvironmentResolver` first; neither was ever released.
- Interpolating from a `.env` file needs kariricode/dotenv 4.5.0 or later.

## Alternatives Considered

| Alternative | Why not |
|---|---|
| Keep accepting any object with `all()` | duck typing the contract exists to replace, and no released Dotenv has `all()` |
| Depend on kariricode/dotenv and accept `Dotenv` | a dependency for one parameter type, and every other source of environment values would need its own method |
| Keep `DotenvBridge` as a shortcut | a static wrapper of one builder call adds a name to learn and nothing to do |
| Rename `withDotenv()` to reflect any resolver | `withEnvInterpolation()` already takes any resolver; `withDotenv()` names the common case and its default, the fallback to the process environment |

## References

- kariricode/contract 2.9 and later: `Environment\EnvironmentResolver`.
- kariricode/dotenv ADR-011.
- ADR-004, ADR-007.
