# ADR-004: Environment Variable Interpolation via Value Processor Chain

## Status
Accepted

## Context
Every competitive configuration library provides environment variable
interpolation: Symfony Config uses `%env(VAR)%`, Laravel uses `env()`,
Spring Boot uses `${VAR}`. Without this, the library cannot be adopted
for production applications where database credentials, API keys, and
deployment-specific values must come from the environment.

The Twelve-Factor App methodology (Heroku, 2012) establishes environment
variables as the standard for configuration that varies between deploys.

## Decision
Implement a **ValueProcessor chain** that transforms configuration values
during the seal phase. The `EnvironmentProcessor` handles `${VAR:default}`
interpolation, compatible with Docker Compose, shell, and POSIX syntax.

Design choices:
1. **`${VAR:default}` syntax** rather than `%env(VAR)%` — more universal,
   works in YAML without quoting issues, compatible with Docker Compose
2. **Strict mode by default** — undefined vars without defaults throw,
   preventing silent misconfiguration in production
3. **Processor chain architecture** — enables composition: env resolution
   feeds into type casting feeds into decryption, all pluggable
4. **EnvironmentResolver contract** — decouples from `$_ENV`/`getenv()`
   for testability and alternative sources (Vault, AWS SSM)

Processing pipeline at seal():
```
raw values → EnvironmentProcessor → TypeCastProcessor → EncryptedValueProcessor → validation
```

## Consequences
- Environment-specific values in YAML/JSON files work as expected
- Strict mode catches missing env vars at startup, not at request time
- Processor chain is extensible without modifying Configuration core
- InMemoryEnvironmentResolver enables deterministic testing
