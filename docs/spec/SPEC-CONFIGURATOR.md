# SPEC-CONFIGURATOR: KaririCode\Configurator V4.2

## 1. Overview

Multi-format configuration management with type-safe access,
environment variable interpolation, value processor chains,
schema validation, ecosystem self-configuration via providers,
hot-reload for long-running processes, encrypted value support,
and first-class Dotenv integration. Zero external dependencies.

V4.2 promotes contracts to the ecosystem level (kariricode/contract)
and adds seamless integration with kariricode/dotenv. See ADR-007.

## 2. Contracts

### 2.1 Ecosystem Contracts (kariricode/contract ^4.1)

| Interface                     | Package                    | Responsibility                         |
|-------------------------------|----------------------------|----------------------------------------|
| EnvironmentResolverInterface  | Contract\Environment       | Env var resolution abstraction         |
| ValueProcessorInterface       | Contract\Configuration     | Pluggable value transformation         |
| ConfigProviderInterface       | Contract\Configuration     | Ecosystem component self-configuration |

### 2.2 Local Contracts

| Interface       | Status              | Responsibility                       |
|-----------------|---------------------|--------------------------------------|
| Configurator    | Canonical (local)   | Type-safe dot-notation read access   |
| Loader          | Canonical (local)   | File parsing (one format per impl)   |
| MergeStrategy   | Canonical (local)   | Array merge policy                   |
| ConfigValidator | Canonical (local)   | Schema-based validation              |
| EnvironmentResolver | @deprecated alias | → EnvironmentResolverInterface   |
| ValueProcessor  | @deprecated alias   | → ValueProcessorInterface            |
| ConfigProvider  | @deprecated alias   | → ConfigProviderInterface            |

## 3. Type Taxonomy

| Class                      | Category    | PHP Keyword            |
|----------------------------|-------------|------------------------|
| Configuration              | Entity      | final class            |
| ConfigurationBuilder       | Builder     | final class            |
| PhpLoader                  | Service     | final readonly class   |
| JsonLoader                 | Service     | final readonly class   |
| YamlLoader                 | Service     | final readonly class   |
| IniLoader                  | Service     | final readonly class   |
| TomlLoader                 | Service     | final readonly class   |
| RecursiveMerge             | Service     | final readonly class   |
| ReplaceMerge               | Service     | final readonly class   |
| SchemaValidator            | Service     | final readonly class   |
| EnvironmentProcessor       | Processor   | final readonly class  |
| TypeCastProcessor          | Processor   | final class            |
| EncryptedValueProcessor    | Processor   | final readonly class   |
| ValueProcessorChain        | Composite   | final class            |
| SystemEnvironmentResolver  | Service     | final readonly class   |
| InMemoryEnvironmentResolver| Service     | final class            |
| CompositeEnvironmentResolver| Service    | final readonly class   |
| CacheBridge                | Integration | final readonly class  |
| EventDispatcherBridge      | Integration | final readonly class  |
| ConfigurationProcessor     | Integration | final readonly class   |
| Psr11Bridge                | Integration | final class            |
| ReloadableConfiguration    | Decorator   | final class            |
| TomlDefinition             | Enum (internal) | enum (pure)        |
| Utf8Encoding               | Trait (internal)| trait              |
| ConfigLoaded               | Event (DPO) | final readonly class   |
| ConfigLoadError            | Event (DPO) | final readonly class   |
| ConfigSealed               | Event (DPO) | final readonly class   |
| ConfigReloaded             | Event (DPO) | final readonly class   |
| ConfigurationException     | Exception   | class (extensible)     |
| LoaderException            | Exception   | final class            |
| ValidationException        | Exception   | final class            |
| MergeException             | Exception   | final class            |

## 4. Schema Validation Format

```php
$schema = [
    'app.name'    => 'string',      // required string
    'app.debug'   => 'bool',        // required bool
    'app.port'    => 'int',         // required int
    'app.rate'    => '?float',      // optional float (nullable)
    'db.host'     => 'string',      // required string
    'db.options'  => '?array',      // optional array
];
```

Leading `?` marks a key as optional. Without `?`, the key must
exist in the configuration data.

## 5. Lifecycle

```
Construction → load()* → merge()* → seal() → get()*
              ├─ mutable phase ──┤  ├─ immutable ─┤

seal() pipeline:
  raw data → ValueProcessors → Validation → freeze
```

After seal(), load() and merge() throw ConfigurationException.
ConfigurationBuilder.build() calls seal() automatically.

## 6. Value Processor Chain

Processors apply at seal() time, before validation. Order matters.

```
${DB_HOST:localhost} → EnvironmentProcessor → "db.prod.example.com"
int:8080             → TypeCastProcessor    → 8080 (int)
ENC(base64...)       → EncryptedValueProcessor → "plaintext_secret"
```

### 6.1 Environment Interpolation Syntax

| Pattern                | Behavior                                |
|------------------------|-----------------------------------------|
| `${VAR}`               | Resolve VAR; throw if undefined (strict)|
| `${VAR:default}`       | Resolve VAR; fall back to 'default'     |
| `${VAR:-default}`      | Same (bash-style default operator)      |
| `${A:${B:fallback}}`   | Nested defaults — recursive resolution  |

### 6.2 Type Cast Syntax

| Prefix   | Output type | Example                          |
|----------|-------------|----------------------------------|
| `int:`   | int         | `int:8080` → 8080                |
| `float:` | float       | `float:3.14` → 3.14             |
| `bool:`  | bool        | `bool:true` → true               |
| `json:`  | array/mixed | `json:{"a":1}` → ['a' => 1]     |
| `csv:`   | array       | `csv:a,b,c` → ['a','b','c']     |
| `base64:`| string      | `base64:dGVzdA==` → 'test'       |
| `file:`  | string      | `file:/path/key` → file contents |
| `trim:`  | string      | `trim:  hello  ` → 'hello'       |
| `lower:` | string      | `lower:HELLO` → 'hello'          |
| `upper:` | string      | `upper:hello` → 'HELLO'          |

Custom casters: `$processor->registerCaster('hex', fn($v) => hexdec($v))`

### 6.3 Encrypted Values

Envelope: `ENC(base64(nonce || ciphertext || tag))`
Algorithm: AES-256-GCM (NIST SP 800-38D)

## 7. Config Providers

```php
// Ecosystem contract (kariricode/contract)
interface ConfigProviderInterface {
    public function namespace(): string;       // e.g. 'cache'
    public function defaults(): array;         // default values
    public function schema(): array;           // validation schema
}
```

Loading priority: Provider defaults → directory files → explicit
files → programmatic overrides. Higher priority wins on conflicts.

## 8. Dot-Notation Resolution

Algorithm: iteratively descend nested array using each segment.
Complexity: O(d) where d = dot-depth of the path.

```
'database.connections.default.host'
 → $data['database']['connections']['default']['host']
```

## 9. Built-in Parser Capabilities

The YAML, TOML and INI parsers have no third-party dependency (ADR-002,
ADR-008) and fail loudly: unsupported features raise
LoaderException (CFG_2822 YAML, CFG_2823 TOML); malformed documents raise
LoaderException CFG_2820 naming the file (and, for TOML, the line).

### 9.1 YAML (subset of YAML 1.2)

Parsing runs in two phases. The lexical phase consumes block scalars and
multi-line flow collections from the raw text and rewrites each as a
single-line equivalent before comments and blank lines are removed. The
structural phase builds mappings and sequences from indentation and
rejects any line it cannot place.

Supported:
- Block mappings and sequences: sequences of mappings (`- host: a` with
  continuation keys), bare `-` items, nested sequences (`- - x`), and
  sequences written at the parent key's indentation.
- Flow collections (`[a, b]`, `{k: v}`) on one or several lines, trailing
  comma, JSON-style keys (`{"k":"v"}`).
- Plain, single-quoted (`''`) and double-quoted scalars with the YAML
  escape set, including `\xHH`, `\uHHHH`, `\UHHHHHHHH`; multi-line plain
  scalars fold with spaces.
- Block scalars `|` and `>` with chomping (`-` strip, `+` keep, default
  clip) and indentation indicators (`|2`); blank lines and `#` inside a
  block are content.
- Comments, UTF-8 BOM, `---` / `...` markers, CRLF line endings.
- Scalars: null (`~`, `null`), booleans (`true/false/yes/no/on/off`,
  case-insensitive), integers in four radixes (`42`, `0xff`, `0o17`,
  `0b1010`; a leading zero is decimal per YAML 1.2), floats including
  `.inf`, `-.inf`, `.nan`.

Behaviour change in 4.2: `|` and `>` blocks end with one line break (clip
chomping, as YAML 1.2 specifies and PyYAML does). Earlier versions
dropped it; `|-` / `>-` give the previous result.

Unsupported (LoaderException CFG_2822): anchors and aliases, merge
keys (checked before aliases), type tags (`!!str`), tab indentation,
quoted scalars spanning several lines.

Known limitation: a blank line inside a multi-line *plain* scalar folds
into a space; YAML would turn it into a line break.

Verification: differential test of the PHP loader against PyYAML 6.0.3
on 42 documents (37 identical parses, 5 required rejections), plus
PHPUnit regression tests.

### 9.2 TOML (subset of TOML 1.0)

Supported, with the specification's strictness: bare, quoted and dotted
keys (whitespace around dots; quoted segments may be empty); tables
`[a.b]`; basic and literal strings with the TOML 1.0 escape set;
integers (decimal without leading zeros, `0x` / `0o` / `0b`, underscores
only between digits) checked for 64-bit overflow; floats including
`inf` / `nan`; booleans; arrays (nested, multi-line, trailing comma);
single-line inline tables.

Rejected with LoaderException CFG_2820 and the line number: unquoted
strings, malformed numbers, invalid escapes and surrogate code points,
empty bare key segments, duplicate keys or tables, keys that extend a
value, a header that redeclares a table created by dotted keys (and
dotted keys that reopen a header-declared table), trailing comma in an
inline table.

Unsupported (LoaderException CFG_2823): arrays of tables `[[t]]`,
multi-line strings, date/time values (quote them as strings).

Verification: differential test of the PHP loader against Python's
tomllib on 33 documents (17 identical parses, 16 required rejections).

### 9.3 INI

Delegates to `parse_ini_string()` with sections and `INI_SCANNER_TYPED`
(booleans, null, integers and floats are typed). Dotted keys and dotted
section names expand into nested arrays. Parse warnings become
LoaderException CFG_2820 through a scoped error handler (no `@`).

## 10. Hot Reload

`ReloadableConfiguration` decorator monitors config files via
filemtime() polling. Configurable interval (default 5s). Atomic
rebuild ensures readers see consistent state. Emits ConfigReloaded
event for cache invalidation and observability.

## 11. ARFA 1.3 Compliance

| Principle | Implementation                                         |
|-----------|--------------------------------------------------------|
| P1        | Seal pattern — immutable after loading; stateless services are final readonly classes |
| P2        | Ecosystem contracts in kariricode/contract; Dotenv composability; ConfigurationProcessor for reactive flows |
| P3        | Environment-aware cascade loading + Dotenv integration  |
| P4        | Protocol-agnostic (no HTTP/CLI coupling)                |
| P5        | EventDispatcherBridge + ConfigReloaded events            |

## 12. Ecosystem Integration

### 12.1 Bridges

| Bridge                  | Requires                          | Purpose                                                   |
|-------------------------|-----------------------------------|-----------------------------------------------------------|
| CacheBridge             | PSR-16 CacheInterface             | Raw-data caching; key = ordered file list + merge strategy |
| EventDispatcherBridge   | PSR-14 EventDispatcherInterface   | Lifecycle events (observe-and-rethrow on load errors)     |
| ConfigurationProcessor  | ProcessorInterface (contract 4.x) | Pipeline flow step                                        |
| Psr11Bridge             | — (returns a factory closure)     | DI container registration                                 |
| ReloadableConfiguration | (optional PSR-14)                 | Hot reload for long-running processes                     |

### 12.2 Dotenv Integration (ADR-010)

The builder reads a `.env` store through `Environment\EnvironmentResolver`
of kariricode/contract; kariricode/dotenv 4.5 and later implement it over a
loaded store:

```php
$config = ConfigurationBuilder::create()
    ->withDotenv(new DotenvEnvironmentResolver($dotenv))
    ->withDirectory('/app/config')
    ->build();
```

withDotenv(EnvironmentResolver $resolver, bool $strict = true,
bool $systemFallback = true) composes the resolver with
SystemEnvironmentResolver unless $systemFallback is false: the store's
values take priority, the process environment fills the gaps.
withEnvInterpolation() takes a resolver without the fallback.

### 12.3 Contract Ownership (ADR-007)

```
kariricode/contract ^4.1
├── Environment\EnvironmentResolverInterface   ← canonical
├── Configuration\ValueProcessorInterface      ← canonical
└── Configuration\ConfigProviderInterface      ← canonical

kariricode/dotenv ^4.x
└── Bridge\DotenvEnvironmentResolver           ← implements above

kariricode/configurator ^4.2
├── Contract\EnvironmentResolver               ← deprecated alias
├── Contract\ValueProcessor                    ← deprecated alias
├── Contract\ConfigProvider                    ← deprecated alias
└── All implementations → typed on ecosystem contracts
```

## 13. Error Codes

Every exception implements the marker `Exception\ConfigurationException`,
extends the kariricode/exception base of the Configuration family, and is
created by a static named constructor with a code from block 2800–2899
under the prefix CFG (ADR-011): the message starts with `[CFG_<code>]`,
`getCode()` returns the integer and `$errorCode` the ConfigCode case.
Messages name files, keys, variables and types, never configuration
values. Rows marked *custom* are never raised by built-in components: they
are the vocabulary for user implementations of the extension contracts
(Loader, ConfigValidator, MergeStrategy).

| Code     | Named constructor                            | Raised by                           |
|----------|----------------------------------------------|-------------------------------------|
| CFG_2800 | AccessException::typeMismatch()              | Configuration typed getters         |
| CFG_2801 | AccessException::immutableViolation()        | Configuration after seal()          |
| CFG_2810 | LoaderException::unsupportedFormat()         | Configuration::load()               |
| CFG_2811 | LoaderException::fileNotFound()              | all loaders                         |
| CFG_2812 | LoaderException::fileUnreadable()            | all loaders                         |
| CFG_2813 | LoaderException::pathTraversal()             | *custom* loaders that confine paths |
| CFG_2820 | LoaderException::parseError()                | JSON, YAML, TOML and INI loaders    |
| CFG_2821 | LoaderException::invalidReturnType()         | PHP and JSON loaders                |
| CFG_2822 | LoaderException::unsupportedYamlFeature()    | YamlLoader                          |
| CFG_2823 | LoaderException::unsupportedTomlFeature()    | TomlLoader                          |
| CFG_2830 | ProcessorException::undefinedVariable()      | EnvironmentProcessor (strict mode)  |
| CFG_2831 | ProcessorException::typeCastFailed()         | TypeCastProcessor                   |
| CFG_2840 | ProcessorException::invalidEncryptionKey()   | EncryptedValueProcessor             |
| CFG_2841 | ProcessorException::decryptionFailed()       | EncryptedValueProcessor             |
| CFG_2842 | ProcessorException::encryptionFailed()       | EncryptedValueProcessor::encrypt()  |
| CFG_2850 | ValidationException::schemaViolations()      | SchemaValidator                     |
| CFG_2851 | ValidationException::requiredMissing()       | *custom* validators                 |
| CFG_2852 | ValidationException::typeConstraint()        | *custom* validators                 |
| CFG_2860 | MergeException::conflict()                   | *custom* merge strategies           |

