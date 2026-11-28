# KaririCode Configurator

**Multi-format, type-safe configuration management for PHP 8.4+**

Part of the [KaririCode Framework](https://kariricode.org) ecosystem.

[![PHP 8.4+](https://img.shields.io/badge/PHP-8.4%2B-777BB4.svg)](https://www.php.net)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![ARFA 1.3](https://img.shields.io/badge/ARFA-1.3-blue.svg)](docs/spec/SPEC-CONFIGURATOR.md)

---

## Overview

KaririCode Configurator loads, merges, validates, and exposes configuration from PHP, JSON, YAML, INI, and TOML files through type-safe dot-notation accessors. After the loading phase, configuration is sealed and becomes fully immutable.

**No third-party packages.** The YAML and TOML parsers, environment interpolation, value processors, and hot reload are implemented in this package; the only required dependency is `kariricode/contract` (shared ecosystem interfaces). Encrypted values use PHP's `ext-openssl` (AES-256-GCM), needed only if you use `ENC(...)`.

### Key Capabilities

- **Multi-format**: PHP arrays, JSON, YAML, INI, TOML (custom YAML and TOML parsers)
- **Type-safe accessors**: `getString()`, `getInt()`, `getFloat()`, `getBool()`, `getArray()`
- **Environment interpolation**: `${VAR:default}` with recursive nesting, strict mode
- **Value processor chain**: composable transforms — env → typecast → decrypt
- **Encrypted values**: `ENC()` envelope with AES-256-GCM
- **Config providers**: ecosystem components self-declare defaults + schema
- **Hot reload**: file-watching decorator for long-running processes
- **Schema validation**: type enforcement with nullable support
- **Ecosystem contracts**: shared interfaces via `kariricode/contract ^4.1`
- **Dotenv integration**: first-class bridge with `kariricode/dotenv`

---

## Installation

```bash
composer require kariricode/configurator
```

For Dotenv integration:

```bash
composer require kariricode/configurator kariricode/dotenv
```

**Requirements:** PHP 8.4+ (uses property hooks, asymmetric visibility)

**Optional, per feature:**

| Feature | Needs |
|---|---|
| `ENC(...)` encrypted values | `ext-openssl` |
| `CacheBridge` | any PSR-16 implementation (`psr/simple-cache`) |
| `EventDispatcherBridge`, reload events | any PSR-14 dispatcher (`psr/event-dispatcher`) |
| `.env` files | `kariricode/dotenv` |

> Release note: `kariricode/contract ^4.1` must be published before this version can be installed from Packagist (latest public contract release: 2.8.6).

---

## Quick Start

```php
use KaririCode\Configurator\ConfigurationBuilder;

$config = ConfigurationBuilder::create()
    ->withDirectory(__DIR__ . '/config')
    ->withEnvironment('production')
    ->withEnvInterpolation()
    ->build();

$dbHost = $config->getString('database.host', 'localhost');
$debug  = $config->getBool('app.debug', false);
$port   = $config->getInt('server.port', 8080);
```

Config files are loaded alphabetically, with environment overlays applied after base files:

```
config/
├── app.php                  ← base
├── app.production.php       ← overlay (merged on top)
├── database.yaml
└── services.json
```

---

## Environment Variable Interpolation

```yaml
# config/database.yaml
database:
  host: ${DB_HOST:localhost}
  port: ${DB_PORT:5432}
  password: ${DB_PASSWORD}        # strict mode throws if undefined
  url: pgsql://${DB_HOST}:${DB_PORT}/mydb
  fallback: ${PRIMARY_HOST:${SECONDARY_HOST:localhost}}  # nested defaults
```

| Syntax | Behavior |
|---|---|
| `${VAR}` | Resolve; throw if undefined (strict mode) |
| `${VAR:default}` | Resolve; fall back to `default` |
| `${VAR:-default}` | Same (bash-style) |
| `${A:${B:fallback}}` | Nested — recursive resolution |

Strict mode is **on by default**. This prevents silent misconfiguration in production.

---

## Dotenv Integration

The builder reads a `.env` store through the contract's
`EnvironmentResolver`; kariricode/dotenv 4.5 and later ship the resolver of
their store (ADR-010):

```php
use KaririCode\Configurator\ConfigurationBuilder;
use KaririCode\Dotenv\Dotenv;
use KaririCode\Dotenv\Integration\DotenvEnvironmentResolver;

$dotenv = new Dotenv('/app');
$dotenv->load();

$config = ConfigurationBuilder::create()
    ->withDotenv(new DotenvEnvironmentResolver($dotenv))   // wires ${VAR} interpolation
    ->withDirectory('/app/config')
    ->withEnvironment('production')
    ->build();
```

The store's values take priority; variables it does not define are read
from the process environment (`$_ENV`, `$_SERVER`, `getenv()`), unless
`systemFallback: false`. Any other `EnvironmentResolver` works the same way,
and `withEnvInterpolation()` takes one without the fallback.

---

## Value Processor Chain

Processors apply at `seal()` time, before validation. **Order matters** — the output of one becomes input to the next.

```php
use KaririCode\Configurator\Processor\{EnvironmentProcessor, TypeCastProcessor, EncryptedValueProcessor};

$config = ConfigurationBuilder::create()
    ->withEnvInterpolation()                              // 1st: resolve ${VAR}
    ->withValueProcessor(new TypeCastProcessor())         // 2nd: int:, bool:, json:
    ->withValueProcessor(new EncryptedValueProcessor($key)) // 3rd: ENC() decrypt
    ->withDirectory('/app/config')
    ->build();
```

### Pipeline Example

```yaml
server:
  port: ${HTTP_PORT}    # env: HTTP_PORT=int:8080
```

```
"${HTTP_PORT}" → EnvironmentProcessor → "int:8080" → TypeCastProcessor → 8080 (int)
```

### Type Cast Prefixes

| Prefix | Output | Example |
|---|---|---|
| `int:` | `int` | `int:8080` → `8080` |
| `float:` | `float` | `float:3.14` → `3.14` |
| `bool:` | `bool` | `bool:true` → `true` |
| `json:` | `array\|mixed` | `json:{"a":1}` → `['a' => 1]` |
| `csv:` | `array` | `csv:a,b,c` → `['a','b','c']` |
| `base64:` | `string` | `base64:dGVzdA==` → `'test'` |
| `file:` | `string` | `file:/path/key` → file contents |
| `trim:` | `string` | `trim:  hello  ` → `'hello'` |
| `lower:` | `string` | `lower:HELLO` → `'hello'` |
| `upper:` | `string` | `upper:hello` → `'HELLO'` |

Custom casters:

```php
$processor = new TypeCastProcessor();
$processor->registerCaster('hex', fn(string $v): int => hexdec($v));
// "hex:ff" → 255
```

### Encrypted Values

```php
// Generate encrypted value (CLI tool or deployment script)
$encrypted = EncryptedValueProcessor::encrypt('s3cr3t_password', $key);
// Returns: ENC(base64...)
```

```yaml
database:
  password: ENC(Ym9vdHN0cmFwLWtleS1oZXJl...)
```

Algorithm: AES-256-GCM (NIST SP 800-38D). Key: 32 bytes raw or 64 hex chars.

---

## Config Providers

Ecosystem components self-declare their configuration needs:

```php
use KaririCode\Contract\Configuration\ConfigProviderInterface;

final class CacheConfigProvider implements ConfigProviderInterface
{
    public function namespace(): string { return 'cache'; }

    public function defaults(): array
    {
        return ['driver' => 'file', 'ttl' => 3600, 'prefix' => 'kc_'];
    }

    public function schema(): array
    {
        return ['driver' => 'string', 'ttl' => 'int'];
    }
}

$config = ConfigurationBuilder::create()
    ->withProvider(new CacheConfigProvider())
    ->withOverrides(['cache' => ['driver' => 'redis']])  // user wins
    ->build();

$config->getString('cache.driver');  // 'redis' (override)
$config->getInt('cache.ttl');        // 3600 (provider default)
```

**Loading priority** (lowest → highest):

1. Provider defaults
2. Directory config files
3. Environment overlays (`app.production.php`)
4. Programmatic overrides (`withOverrides()`)

---

## Schema Validation

```php
$config = ConfigurationBuilder::create()
    ->withSchema([
        'app.name'  => 'string',   // required string
        'app.debug' => 'bool',     // required bool
        'app.port'  => 'int',      // required int
        'db.host'   => '?string',  // optional (nullable)
    ])
    ->withDirectory('/app/config')
    ->build();
```

Leading `?` marks a key as optional. Missing required keys or type mismatches throw `ValidationException`.

---

## Hot Reload

For workers, queue consumers, and long-running processes:

```php
use KaririCode\Configurator\Integration\ReloadableConfiguration;

$config = new ReloadableConfiguration(
    watchFiles: ['/app/config/app.yaml', '/app/config/database.yaml'],
    builder: ConfigurationBuilder::create()->withDirectory('/app/config'),
    checkIntervalSec: 5.0,
    dispatcher: $eventDispatcher, // optional PSR-14
);

// In your event loop / middleware / tick
$config->checkAndReload();

$config->getString('app.name'); // always returns current value
```

Uses `filemtime()` polling — portable across all platforms. Atomic rebuild ensures readers never see partial state. Emits `ConfigReloaded` event for cache invalidation.

---

## Environment Resolvers

| Resolver | Source | Use Case |
|---|---|---|
| `SystemEnvironmentResolver` | `$_ENV → $_SERVER → getenv()` | Default (no .env loading) |
| `InMemoryEnvironmentResolver` | Constructor array | Testing |
| `CompositeEnvironmentResolver` | Chains resolvers in order | Dotenv + system fallback |
| `DotenvEnvironmentResolver` | kariricode/dotenv + system | Production with .env files |

Custom resolvers (Vault, AWS SSM):

```php
use KaririCode\Contract\Environment\EnvironmentResolverInterface;

final class VaultResolver implements EnvironmentResolverInterface
{
    public function get(string $name): ?string
    {
        return $this->vaultClient->readSecret("config/{$name}");
    }

    public function has(string $name): bool
    {
        return $this->get($name) !== null;
    }
}

// Vault → Dotenv → System
$resolver = new CompositeEnvironmentResolver(
    new VaultResolver($vaultClient),
    new DotenvEnvironmentResolver($dotenv),
    new SystemEnvironmentResolver(),
);
```

---

## Ecosystem Integration

### Bridges

| Bridge | Requires | Purpose |
|---|---|---|
| `CacheBridge` | PSR-16 | Raw-data caching (value processors not applied); key = ordered file list + merge strategy |
| `EventDispatcherBridge` | PSR-14 | Lifecycle events |
| `ConfigurationProcessor` | ProcessorInterface | Pipeline flow step |
| `Psr11Bridge` | — (returns a factory closure) | DI container registration |
| `ReloadableConfiguration` | (optional PSR-14) | Hot reload |

### Shared Contracts (kariricode/contract ^4.1)

```
KaririCode\Contract\Environment\EnvironmentResolverInterface
KaririCode\Contract\Configuration\ValueProcessorInterface
KaririCode\Contract\Configuration\ConfigProviderInterface
```

These contracts are ecosystem-level — any KaririCode component can implement them without depending on the Configurator package. See [ADR-007](docs/adr/ADR-007-ecosystem-contract-promotion.md).

### Backward Compatibility

Local aliases (`KaririCode\Configurator\Contract\EnvironmentResolver`, etc.) extend the ecosystem contracts and remain functional. Existing code requires no changes. New code should type-hint the ecosystem contracts directly.

---

## Built-in Parsers

All parsers fail loudly: unsupported features raise `LoaderException` (`CFG_2822` YAML, `CFG_2823` TOML) and malformed files raise `LoaderException` `CFG_2820` naming the file. Full capability list and verification method: [SPEC §9](docs/spec/SPEC-CONFIGURATOR.md).

**YAML (subset of 1.2)** — block and flow collections, including lists of objects (`- host: a`), sequences at the parent key's indentation and multi-line flow collections; plain, single- and double-quoted scalars (with `\uXXXX` escapes); block scalars `|` / `>` with chomping (`-`, `+`) and indentation indicators; ints in four radixes (a leading zero is decimal, per YAML 1.2), `.inf`, `.nan`.

> **Behaviour change in 4.2:** `|` and `>` blocks now end with one line break, as YAML 1.2 specifies. Use `|-` / `>-` for the previous result.

Unsupported: anchors, aliases, merge keys, type tags (`!!str`), tab indentation, quoted scalars spanning lines.

**TOML (subset of 1.0)** — keys (bare, quoted, dotted), tables, strings, integers (overflow-checked), floats, booleans, arrays, single-line inline tables. Invalid TOML — unquoted strings, duplicate keys or tables, keys that extend a value — is rejected with the line number instead of being reinterpreted. Unsupported: `[[arrays of tables]]`, multi-line strings, dates.

**INI** — `parse_ini_string()` with sections and typed scanning; dotted keys expand into nested arrays.

---

## Lifecycle

```
Construction → load()* → merge()* → seal() → get()*
              ├── mutable phase ──┤  ├── immutable ─┤

seal() pipeline:
  raw data → ValueProcessors → Validation → freeze
```

After `seal()`, any call to `load()` or `merge()` throws `AccessException` (`CFG_2801`). The builder calls `seal()` automatically via `build()`.

---

## Architecture

```
kariricode/contract ^4.1
├── Environment\EnvironmentResolverInterface
├── Configuration\ValueProcessorInterface
└── Configuration\ConfigProviderInterface

kariricode/dotenv ^4.x
└── Bridge\DotenvEnvironmentResolver implements EnvironmentResolverInterface

kariricode/configurator ^4.2
├── src/
│   ├── Configuration.php              ← sealed, immutable config store
│   ├── ConfigurationBuilder.php       ← fluent builder
│   ├── Contract/                      ← backward-compat aliases
│   ├── Environment/
│   │   ├── SystemEnvironmentResolver  ← $_ENV → $_SERVER → getenv()
│   │   ├── InMemoryEnvironmentResolver ← testing
│   │   └── CompositeEnvironmentResolver ← chains resolvers
│   ├── Processor/
│   │   ├── EnvironmentProcessor       ← ${VAR:default}
│   │   ├── TypeCastProcessor          ← int:, bool:, json:
│   │   ├── EncryptedValueProcessor    ← ENC() AES-256-GCM
│   │   └── ValueProcessorChain       ← composite chain
│   ├── Integration/
│   │   ├── CacheBridge                ← PSR-16
│   │   ├── EventDispatcherBridge      ← PSR-14
│   │   ├── ConfigurationProcessor     ← pipeline step
│   │   ├── Psr11Bridge                ← PSR-11
│   │   └── ReloadableConfiguration   ← hot reload
│   ├── Loader/                        ← PHP, JSON, YAML, INI, TOML
│   ├── MergeStrategy/                 ← Recursive, Replace
│   ├── Validator/                     ← Schema validation
│   ├── Event/                         ← ConfigLoaded, ConfigSealed, ConfigReloaded
│   └── Exception/                     ← typed exceptions with error codes
└── tests/
    ├── Unit/                          ← 16 test files
    ├── Integration/                   ← 3 test files (pipeline, providers, ecosystem)
    └── Conformance/                   ← YAML edge cases (30+ cases)
```

---

## ARFA 1.3 Compliance

| Principle | Implementation |
|---|---|
| **P1** Immutable State | Seal pattern — frozen after loading |
| **P2** Composability | Ecosystem contracts in kariricode/contract; Dotenv integration; ConfigurationProcessor for pipeline flows |
| **P3** Environment Awareness | Cascade loading + Dotenv + CompositeEnvironmentResolver |
| **P4** Protocol Agnostic | No HTTP/CLI coupling |
| **P5** Observability | EventDispatcherBridge + ConfigReloaded events |

---

## Error Codes

Every exception implements `KaririCode\Configurator\Exception\ConfigurationException`, so one `catch` covers the package, and carries a code from block 2800–2899 (prefix `CFG`) of the ecosystem registry: the message starts with `[CFG_<code>]` and names files, keys and types, never configuration values. Rows marked *custom* are never raised by built-in components; they are the vocabulary for your own `Loader`, `ConfigValidator` and `MergeStrategy` implementations.

| Code | Named constructor | Raised by |
|---|---|---|
| `CFG_2800` | `AccessException::typeMismatch()` | typed getters |
| `CFG_2801` | `AccessException::immutableViolation()` | Configuration after seal() |
| `CFG_2810` | `LoaderException::unsupportedFormat()` | Configuration::load() |
| `CFG_2811` | `LoaderException::fileNotFound()` | all loaders |
| `CFG_2812` | `LoaderException::fileUnreadable()` | all loaders |
| `CFG_2813` | `LoaderException::pathTraversal()` | *custom* loaders that confine paths |
| `CFG_2820` | `LoaderException::parseError()` | JSON, YAML, TOML and INI loaders |
| `CFG_2821` | `LoaderException::invalidReturnType()` | PHP and JSON loaders |
| `CFG_2822` | `LoaderException::unsupportedYamlFeature()` | YamlLoader |
| `CFG_2823` | `LoaderException::unsupportedTomlFeature()` | TomlLoader |
| `CFG_2830` | `ProcessorException::undefinedVariable()` | EnvironmentProcessor (strict mode) |
| `CFG_2831` | `ProcessorException::typeCastFailed()` | TypeCastProcessor |
| `CFG_2840` | `ProcessorException::invalidEncryptionKey()` | EncryptedValueProcessor |
| `CFG_2841` | `ProcessorException::decryptionFailed()` | EncryptedValueProcessor |
| `CFG_2842` | `ProcessorException::encryptionFailed()` | EncryptedValueProcessor::encrypt() |
| `CFG_2850` | `ValidationException::schemaViolations()` | SchemaValidator |
| `CFG_2851` | `ValidationException::requiredMissing()` | *custom* validators |
| `CFG_2852` | `ValidationException::typeConstraint()` | *custom* validators |
| `CFG_2860` | `MergeException::conflict()` | *custom* merge strategies |

---

## References

- **Specification**: [SPEC-CONFIGURATOR V4.2](docs/spec/SPEC-CONFIGURATOR.md)
- **Architecture Decisions**: [docs/adr/](docs/adr/)
- **ARFA 1.3**: Adaptive Reactive Flow Architecture (patent pending)
- **Symfony Config**: EnvVarProcessorInterface, TreeBuilder
- **Spring Boot Config**: @Value, @RefreshScope, Vault transit
- **Twelve-Factor App**: III. Config — store config in the environment
- **NIST SP 800-38D**: AES-GCM authenticated encryption
- **Clean Code** (Martin): naming, functions, comments
- **Framework Design Guidelines** (Cwalina & Abrams): interface design, contract patterns

---

## License

MIT License. See [LICENSE](LICENSE).
