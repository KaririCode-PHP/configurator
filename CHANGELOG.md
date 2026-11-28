# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package
follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [4.2.0] — 2026-11-28

The first release of the 4.x line, which was developed outside this
repository and delivered as tarballs (4.0, 4.1, 4.2); none of them was
published, so 4.2.0 follows 1.0.0 directly and replaces its API. The
sections below record what changed from the 4.2 tarball to this release,
then the tarball's own review.

### Spec V4.3.3 conformance (2026-11-28)

**Breaking**

- The package installs from the published `kariricode/contract` ^2.11
  instead of an unpublished ^4.1, and names its types without the
  `Interface` suffix: `Environment\EnvironmentResolver`,
  `Configuration\ValueProcessor`, `Configuration\ConfigProvider`. The
  local aliases `Contract\EnvironmentResolver`, `Contract\ValueProcessor`
  and `Contract\ConfigProvider` are removed (ADR-009).
- `ConfigurationBuilder::withDotenv()` takes an `EnvironmentResolver`;
  objects with an `all()` method are no longer accepted, and
  `Integration\DotenvBridge` is removed. kariricode/dotenv 4.5 ships
  `Integration\DotenvEnvironmentResolver` (ADR-010).
- Every exception carries a code from block 2800–2899 under the prefix
  CFG (ADR-011): `Exception\ConfigurationException` is now the interface
  every exception implements; `typeMismatch()` and `immutableViolation()`
  move to the new `AccessException`; `unsupportedFormat()` and the two
  unsupported-feature factories to `LoaderException`; interpolation,
  casts and encryption to the new `ProcessorException`. All 19 codes
  changed (1000–1399, which kariricode/async-primitives holds, became
  CFG_2800–CFG_2860); `pathNotFound()` is removed.
- An encryption key of the wrong size raises
  `ProcessorException::invalidEncryptionKey()` (CFG_2840) instead of an
  uncoded `InvalidArgumentException`.
- `ValidationException::getViolations()` becomes the readonly property
  `$violations`, and `EventDispatcherBridge::getInner()` the readonly
  property `$inner`.
- In a directory, only files without a dot before the extension are base
  files; `<name>.<env>.<ext>` is an overlay that loads only when
  `withEnvironment()` selects `<env>`.
- `int:` and `float:` reject payloads that are not numbers (CFG_2831).
- Parse errors no longer repeat the broken line: YAML errors name the
  key and the indentation, TOML errors the line.

**Added**

- `Exception\ConfigCode`, the code table, checked against the registry of
  kariricode/exception.
- `Validator\CompositeValidator`, which reports the violations of several
  validators at once.
- `Event\ConfigReloadFailed`, dispatched when a hot reload cannot rebuild
  the configuration (ADR-012).
- `#[\SensitiveParameter]` on keys, plaintexts and processed values, so
  stack traces do not carry them.
- A conformance suite for the ARFA 1.3 and Spec V4.3.3 rules, the error
  code registry and the documentation layout; integration tests for the
  four bridges that had none; YAML and TOML corpora.
- SECURITY.md, CONTRIBUTING.md, ADR-009 to ADR-012 and the QD-V433
  record.

**Changed**

- The gates run through KaririCode Devkit with the root configs:
  PHPStan level max on `src` and `tests`, Psalm level 1, PER-CS 3.0,
  the §13 budgets with an empty baseline, Infection with MSI ≥ 80, the
  secret scan and both audits; `composer.lock` is committed and CI pins
  actions by SHA and kcode by SHA-256 (ADR-009).
- The YAML and TOML parsers return typed values instead of `mixed`;
  their scanners split into named predicates, and TOML's definition rules
  live in the internal `Loader\TomlDocument`.
- Parsers raise `LoaderException::invalidSyntax()`, which the loaders
  report as `parseError()` for the file; `src` throws no SPL exception.
- Every type documents its preconditions, thread safety, side effects,
  an example and its references; calls with three or more arguments name
  them; patterns anchor their end with `\z`.
- ADR-001 to ADR-008 follow the canonical layout and record their
  amendments.

**Fixed**

- Overlays of other environments loaded as base files:
  `app.production.php` merged over `app.php` with no environment, or with
  another one, selected.
- `int:abc` became 0, and an integer past 64 bits saturated.
- A failed hot reload threw from the read that triggered it and rebuilt
  the same broken files every interval; the last good configuration now
  stays and the failure is dispatched once.
- A config provider's schema was dropped when the application set a
  validator other than SchemaValidator.
- `TypeCastProcessor` and `getBool()` quoted the values they rejected,
  and parse errors quoted lines of the file, so a secret could reach a
  log.
- The YAML and TOML parsers counted square brackets and braces together,
  so a collection closed by the other kind loaded silently: `{x: 1]` as
  a mapping, `a = [1, 2}` as an array. A closing bracket must now match
  the innermost open one (CFG_2820).
- YAML block scalars turned a blank line into an empty one even when it
  held spaces past the block's indentation, and accepted a leading blank
  line indented past the first line; both follow YAML 1.2 now.

### Review of the 4.2 tarball

Recorded in `docs/DEEP-REVIEW-V4.2.md` (historical, in Portuguese).

- `EncryptedValueProcessor` could not decrypt an encrypted empty string;
  the minimum payload is now nonce plus tag.
- YAML: merge keys were detected as aliases; a bare `-` followed by an
  indented mapping became an empty map; `0b`/`0o` integers became 0. A
  differential test against PyYAML (42 documents) led to the two-phase
  parser of ADR-002.
- TOML: a differential test against tomllib (33 documents) found 15
  invalid documents accepted, some with silent data loss; the parser
  enforces TOML 1.0's definition rules (ADR-008).
- Documentation stopped claiming path containment for `PhpLoader` and
  value processing for `CacheBridge`.

## [1.0.0] — 2024-09-28

First release: PHP, JSON and YAML loaders (YAML through symfony/yaml), a
tree-map storage, overwrite and strict merge strategies, and an automatic
validator, for PHP 8.3.

[Unreleased]: https://github.com/KaririCode-PHP/configurator/compare/v4.2.0...HEAD
[4.2.0]: https://github.com/KaririCode-PHP/configurator/compare/v1.0.0...v4.2.0
[1.0.0]: https://github.com/KaririCode-PHP/configurator/releases/tag/v1.0.0
