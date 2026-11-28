<p align="center">
  <a href="https://kariricode.org" target="_blank">
    <img src="https://kariricode.org/assets/images/kariricode-logo.svg" alt="KaririCode Logo" width="200">
  </a>
</p>

<h1 align="center">KaririCode\Configurator</h1>

<p align="center">
  <strong>Multi-format, type-safe configuration for PHP 8.4+</strong>
</p>

<p align="center">
  <a href="https://packagist.org/packages/kariricode/configurator"><img src="https://img.shields.io/packagist/v/kariricode/configurator.svg" alt="Latest Version"></a>
  <a href="https://github.com/KaririCode-PHP/configurator/actions"><img src="https://img.shields.io/github/actions/workflow/status/KaririCode-PHP/configurator/ci.yml?branch=main" alt="CI Status"></a>
  <a href="https://php.net"><img src="https://img.shields.io/badge/php-%3E%3D8.4-8892BF.svg" alt="PHP Version"></a>
  <a href="LICENSE"><img src="https://img.shields.io/badge/license-MIT-green.svg" alt="MIT License"></a>
</p>

Loads PHP, JSON, YAML, INI and TOML files, merges them with environment overlays, interpolates `${VAR}`, applies type casts and decrypts `ENC(...)` values, validates the result against a schema, and serves it through typed dot-notation reads. Once sealed, a configuration is immutable.

**Version:** 4.2.0 · **PHP:** ≥ 8.4 · **Dependencies:** kariricode/contract, kariricode/exception · **ARFA 1.3:** Level 1 · **Spec:** V4.3.3 · **ASVS:** 5.0 level 2 · **License:** MIT

## 1. Features

- Five formats, with built-in YAML and TOML parsers (no symfony/yaml, no TOML library) that fail loudly on what they do not support.
- Typed reads: `getString()`, `getInt()`, `getFloat()`, `getBool()`, `getArray()`; `get()` and `has()` never throw.
- Environment overlays (`app.production.php` over `app.php`), recursive merge by default.
- A value processor chain run at seal time: `${VAR:default}` interpolation (strict by default), type prefixes (`int:`, `bool:`, `json:`, `file:`…) and AES-256-GCM `ENC(...)` values.
- Schema validation that reports every violation at once; config providers that declare their defaults and schema.
- Hot reload for long-running workers that keeps the last good configuration when a file breaks.
- PSR-11, PSR-14 and PSR-16 bridges; Dotenv integration through the contract's `EnvironmentResolver`.
- Coded exceptions (`CFG_2800`–`CFG_2860`) whose messages never carry configuration values.

## 2. Installation

```bash
composer require kariricode/configurator
```

| Feature | Needs |
|---|---|
| `ENC(...)` values | `ext-openssl` |
| `CacheBridge` | a PSR-16 implementation (`psr/simple-cache`) |
| `EventDispatcherBridge`, reload events | a PSR-14 dispatcher (`psr/event-dispatcher`) |
| `.env` files | `kariricode/dotenv` 4.5 or later |

## 3. Quick Start

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

A directory loads its base files, sorted by name, then the overlays of the selected environment:

```
config/
├── app.php              ← base
├── app.production.php   ← overlay, loads only with withEnvironment('production')
├── database.yaml        ← base
└── services.json        ← base
```

Base files have no dot before the extension; `<name>.<env>.<ext>` is an overlay and never loads for another environment, or without one. A glob pattern (`withDirectory('/config/*.php')`) loads every file it matches.

**Priority** (lowest to highest): provider defaults, directory files (base, then overlays), explicit files (`withFile()`), overrides (`withOverrides()`).

## 4. Environment Interpolation

```yaml
# config/database.yaml
database:
  host: ${DB_HOST}
  port: ${DB_PORT:5432}
  user: ${DB_USER:-app}
  url: ${DATABASE_URL:${DB_FALLBACK_URL:sqlite::memory:}}
```

| Syntax | Meaning |
|---|---|
| `${VAR}` | the variable; fails when undefined (strict mode) |
| `${VAR:default}` | the variable, or `default` |
| `${VAR:-default}` | the same, shell style |
| `${A:${B:fallback}}` | defaults nest |

Strict mode is the default: an undefined variable without a default stops the boot (`CFG_2830`) instead of becoming an empty string. The name ends at the first colon.

### Dotenv

kariricode/dotenv 4.5 and later implement the contract's `EnvironmentResolver` over a loaded store (ADR-010):

```php
use KaririCode\Dotenv\Dotenv;
use KaririCode\Dotenv\Integration\DotenvEnvironmentResolver;

$dotenv = new Dotenv('/app');
$dotenv->load();

$config = ConfigurationBuilder::create()
    ->withDotenv(new DotenvEnvironmentResolver($dotenv))
    ->withDirectory('/app/config')
    ->build();
```

The store's values win; variables it does not define come from the process (`$_ENV`, `$_SERVER`, `getenv()`) unless `systemFallback: false`.

### Resolvers

| Resolver | Source |
|---|---|
| `SystemEnvironmentResolver` | `$_ENV`, then `$_SERVER`, then `getenv()` (the default) |
| `InMemoryEnvironmentResolver` | a map, for tests and tools |
| `CompositeEnvironmentResolver` | several resolvers in priority order |
| `DotenvEnvironmentResolver` | a loaded `.env` store (kariricode/dotenv) |

Any `KaririCode\Contract\Environment\EnvironmentResolver` works, such as a vault client:

```php
$resolver = new CompositeEnvironmentResolver(
    new VaultResolver($vaultClient),
    new DotenvEnvironmentResolver($dotenv),
    new SystemEnvironmentResolver(),
);

$config = ConfigurationBuilder::create()->withEnvInterpolation($resolver)->build();
```

## 5. Value Processors

Processors run at seal time, before validation, in the order they are added; each one's output is the next one's input.

```php
use KaririCode\Configurator\Processor\EncryptedValueProcessor;
use KaririCode\Configurator\Processor\TypeCastProcessor;

$config = ConfigurationBuilder::create()
    ->withEnvInterpolation()                                    // ${VAR}
    ->withValueProcessor(new TypeCastProcessor())               // int:, bool:, json:
    ->withValueProcessor(new EncryptedValueProcessor($key))     // ENC(...)
    ->withDirectory('/app/config')
    ->build();
```

```
"${HTTP_PORT}" → EnvironmentProcessor → "int:8080" → TypeCastProcessor → 8080
```

| Prefix | Result |
|---|---|
| `int:8080` | `8080`; a decimal integer within 64 bits, anything else fails |
| `float:3.14` | `3.14`; a decimal number (`1e3` included), anything else fails |
| `bool:true` | `true`; true, false, 1, 0, yes, no, on, off, any case |
| `json:{"a":1}` | `['a' => 1]` |
| `csv:a,b,c` | `['a', 'b', 'c']` |
| `base64:dGVzdA==` | `'test'` |
| `file:/run/secrets/db` | the file's contents, trimmed |
| `trim:`, `lower:`, `upper:` | the string, transformed |

A payload that does not convert fails with `CFG_2831`, and the message never repeats it. `registerCaster('hex', fn(string $v): int => hexdec($v))` adds a prefix.

### Encrypted values

```php
$encrypted = EncryptedValueProcessor::encrypt('s3cr3t_password', $key); // ENC(base64...)
```

```yaml
database:
  password: ENC(Ym9vdHN0cmFwLWtleS1oZXJl...)
```

AES-256-GCM (NIST SP 800-38D); the key is 32 raw bytes or 64 hex characters and belongs in the environment or a secrets manager. A tampered value fails (`CFG_2841`); it never decrypts to garbage.

## 6. Providers and Schema

Components declare their defaults and their schema through the contract's `ConfigProvider`:

```php
use KaririCode\Contract\Configuration\ConfigProvider;

final class CacheConfigProvider implements ConfigProvider
{
    public function namespace(): string { return 'cache'; }

    public function defaults(): array { return ['driver' => 'file', 'ttl' => 3600]; }

    public function schema(): array { return ['driver' => 'string', 'ttl' => 'int']; }
}

$config = ConfigurationBuilder::create()
    ->withProvider(new CacheConfigProvider())
    ->withOverrides(['cache' => ['driver' => 'redis']])
    ->withSchema(['app.name' => 'string', 'db.host' => '?string'])
    ->build();
```

Types are `string`, `int`, `float`, `bool`, `array`, `numeric`, `scalar` or a class name; a leading `?` makes a key optional. Provider schemas merge with `withSchema()` (the application wins on the same path) and still apply next to a custom validator. Every violation is reported in one `ValidationException` (`CFG_2850`) and in its `$violations`.

## 7. Hot Reload

```php
use KaririCode\Configurator\Integration\ReloadableConfiguration;

$config = new ReloadableConfiguration(
    watchFiles: ['/app/config/app.yaml', '/app/config/database.yaml'],
    builder: ConfigurationBuilder::create()->withDirectory('/app/config'),
    checkIntervalSec: 5.0,
    dispatcher: $eventDispatcher,
);

$config->getString('app.name'); // checks the files at most once per interval
```

`filemtime()` polling works on every platform and on container volumes. A rebuild completes before the swap, so readers never see a partial state. When a file breaks, the last good configuration stays, `ConfigReloadFailed` is dispatched once, and reads keep working; `checkAndReload()` and `forceReload()` rethrow the failure (ADR-012).

## 8. Parsers

All parsers fail loudly: malformed files raise `LoaderException` `CFG_2820` with the file, and unsupported features `CFG_2822` (YAML) or `CFG_2823` (TOML).

**YAML** (subset of 1.2): block and flow collections, lists of objects, sequences at the parent key's indentation, multi-line flow collections; plain, single- and double-quoted scalars with the escape set; block scalars `|` and `>` with chomping and indentation indicators; integers in four radixes, `.inf`, `.nan`. Unsupported: anchors, aliases, merge keys, type tags, tab indentation, quoted scalars spanning lines, a flow collection as the whole document.

**TOML** (subset of 1.0): bare, quoted and dotted keys, tables, strings, overflow-checked integers, floats, booleans, arrays, single-line inline tables; invalid documents are rejected with the line. Unsupported: arrays of tables, multi-line strings, dates.

**INI**: PHP's `parse_ini_string()` with sections and typed values; dotted keys nest.

Details and the differential tests against PyYAML and tomllib: [SPEC §9](docs/spec/SPEC-CONFIGURATOR.md).

## 9. Bridges

| Bridge | Requires | Purpose |
|---|---|---|
| `CacheBridge` | PSR-16 | caches the merged tree before processing, so no decrypted value reaches the cache |
| `EventDispatcherBridge` | PSR-14 | `ConfigLoaded`, `ConfigLoadError`, `ConfigSealed` |
| `ReloadableConfiguration` | optional PSR-14 | hot reload, `ConfigReloaded`, `ConfigReloadFailed` |
| `Psr11Bridge` | — | a factory closure for any container |
| `ConfigurationProcessor` | `Processor` (kariricode/contract) | configuration as a pipeline step |

## 10. Errors

Every exception implements `KaririCode\Configurator\Exception\ConfigurationException`, so one `catch` covers the package, extends the kariricode/exception base of the Configuration family, and carries a code from block 2800–2899 (ADR-011). Messages name files, keys, lines and types, never configuration values.

| Code | Named constructor | Raised by |
|---|---|---|
| `CFG_2800` | `AccessException::typeMismatch()` | typed reads |
| `CFG_2801` | `AccessException::immutableViolation()` | changes after `seal()` |
| `CFG_2810` | `LoaderException::unsupportedFormat()` | `Configuration::load()` |
| `CFG_2811` | `LoaderException::fileNotFound()` | all loaders |
| `CFG_2812` | `LoaderException::fileUnreadable()` | all loaders |
| `CFG_2813` | `LoaderException::pathTraversal()` | *custom* loaders that confine paths |
| `CFG_2820` | `LoaderException::parseError()` | JSON, YAML, TOML and INI loaders |
| `CFG_2821` | `LoaderException::invalidReturnType()` | PHP and JSON loaders |
| `CFG_2822` | `LoaderException::unsupportedYamlFeature()` | YamlLoader |
| `CFG_2823` | `LoaderException::unsupportedTomlFeature()` | TomlLoader |
| `CFG_2830` | `ProcessorException::undefinedVariable()` | EnvironmentProcessor, strict mode |
| `CFG_2831` | `ProcessorException::typeCastFailed()` | TypeCastProcessor |
| `CFG_2840` | `ProcessorException::invalidEncryptionKey()` | EncryptedValueProcessor |
| `CFG_2841` | `ProcessorException::decryptionFailed()` | EncryptedValueProcessor |
| `CFG_2842` | `ProcessorException::encryptionFailed()` | `EncryptedValueProcessor::encrypt()` |
| `CFG_2850` | `ValidationException::schemaViolations()` | SchemaValidator, CompositeValidator |
| `CFG_2851` | `ValidationException::requiredMissing()` | *custom* validators |
| `CFG_2852` | `ValidationException::typeConstraint()` | *custom* validators |
| `CFG_2860` | `MergeException::conflict()` | *custom* merge strategies |

*Custom* rows are never raised by built-in components; they are the vocabulary for your own `Loader`, `ConfigValidator` and `MergeStrategy`.

## 11. Architecture and Quality

Component specification (contracts, type taxonomy, lifecycle, parsers, error codes): [SPEC-CONFIGURATOR](docs/spec/SPEC-CONFIGURATOR.md). Decisions: [docs/adr/](docs/adr/). Quality record: [QD-V433](docs/quality/QD-V433-COMPLIANCE.md). Security: [SECURITY.md](SECURITY.md). Changes: [CHANGELOG.md](CHANGELOG.md). Review of the 4.2 tarball: [docs/DEEP-REVIEW-V4.2.md](docs/DEEP-REVIEW-V4.2.md).

The quality gates of KaririCode Specification V4.3.3 run through [KaririCode Devkit](https://github.com/KaririCode-PHP/devkit) (`kcode`), which installs the toolchain pinned in `devkit.php` into `.kcode/` (ADR-009); [CONTRIBUTING.md](CONTRIBUTING.md) has the setup.

```bash
kcode init           # the toolchain pinned in devkit.php, into .kcode/
composer check       # cs-check, phpstan, psalm, complexity, the three suites, mutation
composer security    # validate, audit of both locks, secret scan
```

## 12. License

MIT — see [LICENSE](LICENSE).

**Author:** Walmir Silva — [walmir.silva@kariricode.org](mailto:walmir.silva@kariricode.org) · [KaririCode Framework](https://kariricode.org)
