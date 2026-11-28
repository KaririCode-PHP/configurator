# ADR-009: Quality Gates through KaririCode Devkit, with the Root Configs

## Status

Accepted — V4.2.0

## Date

2026-11-28

## Context

The 4.2 tarball did not install: it required `kariricode/contract` ^4.1,
a line that was never published (the contract is at 2.x), and named the
contract's types with an `Interface` suffix the published line does not
use. It shipped no PHPStan, Psalm, PHPUnit or style config; the metrics in
`docs/DEEP-REVIEW-V4.2.md` came from a verification harness kept outside
the package (PHPStan at level max on `src` only, and PHPUnit). The
repository's own workflow, from the 1.x line, ran `kcode quality` with
the toolchain's configs, actions pinned by tag, kcode taken from
`latest` and no lock file.

KaririCode Specification V4.3.3 §29.1 names eight gates: tests, the
conformance suite, PHPStan at level max, Psalm at error level 1, PER Coding
Style, the §13 complexity budgets (cyclomatic ≤ 10, cognitive ≤ 15,
nesting ≤ 3, parameters ≤ 4), mutation testing with MSI ≥ 80, and
security. The ecosystem runs them through KaririCode Devkit: kcode 1.2.x
installs the versions pinned in `devkit.php` into `.kcode/`, with a lock of
its own, and runs the configs a component commits when `devkit.php` sets
`'root_configs' => true`.

### Prior Art

- KaririCode Devkit 1.2.2 (`kcode init`, `kcode test`, `kcode run`).
- kariricode/dotenv ADR-009, kariricode/event-dispatcher ADR-009,
  kariricode/i18n ADR-I18N-007 and kariricode/runtime ADR-007: the same
  move, with the shared `tools/complexity.php`, `tools/sbom.php` and
  secret scan.

## Decision

| Item | Before | Now |
|---|---|---|
| Runtime | `kariricode/contract` ^4.1, unpublished | `kariricode/contract` ^2.11, the published line, which has `Configuration\ConfigProvider`, `Configuration\ValueProcessor` and `Environment\EnvironmentResolver` |
| Tools | `kariricode/devkit` in `require-dev`; the harness outside the package | `devkit.php`: PHPUnit 11.5.56, PHPStan 2.3.7 with phpstan-strict-rules 2.1.2, phpstan-phpunit 2.1.4 and tomasvotruba/cognitive-complexity 1.2.0, Psalm 6.19.1, PHP-CS-Fixer 3.95.27, Infection 0.35.5 |
| `require-dev` | `kariricode/devkit` | `psr/container`, `psr/event-dispatcher` and `psr/simple-cache`: the bridges' interfaces, which the suites implement |
| `composer.lock` | none | committed, resolved from Packagist with a clean Composer home; `export-ignore` |
| Style | none committed | PER Coding Style 3.0 (`@PER-CS3.0`), the version php-cs-fixer implements of the 3.1 the Spec pins (E-046), on `src`, `tests` and `tools` |
| PHPStan | level max on `src`, in the harness | level max on `src` and `tests`, with the strict, PHPUnit and cognitive rules; tests call PHPUnit's assertions statically |
| Psalm | none | error level 1 on `src`, no baseline, `#[\Override]` required; two suppressions, each with its reason in place |
| Complexity | none | `tools/complexity.php` over `src`, with an empty baseline |
| Mutation testing | none | `composer mutation` through kcode: MSI ≥ 80 and covered MSI ≥ 80 (the Spec's floor) |
| `composer security` | `composer audit` in the workflow | `composer validate --strict`, the audit of the runtime lock and of `.kcode/composer.lock`, the secret scan and its self-test |
| CI | one workflow, actions by tag, kcode from `latest` | one: the gates on PHP 8.4 with pcov, kcode pinned by version and SHA-256, actions by commit SHA; the suites on PHP 8.5 with the lowest versions the constraints allow; dependency review on pull requests |
| `extra` | none | `kariricode`: `arfa` 1.3 level 1, `asvs` 5.0 level 2, `spec` 4.3.3; `branch-alias` `4.x-dev` |

The first measurement of the tarball's code with these configs found 456
PHPStan reports (217 of them PHPUnit assertions called on `$this`, and 44
references to contract types that do not exist), 97 Psalm errors (37
missing `#[\Override]`, 28 `MixedAssignment`, 20 undefined contract types),
18 of 68 files off PER-CS 3.0 and 13 breaches of §13. The commit that
records this decision fixes them:

- The code names the contract's published types. The three deprecated
  local aliases (`Contract\ConfigProvider`, `Contract\EnvironmentResolver`,
  `Contract\ValueProcessor`), which only extended the 4.x names, are
  removed: no release shipped them (ADR-007).
- The 37 methods that implement a contract carry `#[\Override]`.
- Configuration values are typed where the code knows the type: the YAML
  and TOML parsers return `string|int|float|bool|array|null` instead of
  `mixed`; `RecursiveMerge` builds its result with `array_replace()`, the
  INI loader merges one tree per key with `array_replace_recursive()` and
  the processor chain walks the tree with `array_map()`, so no array
  element receives an untyped value; reads of untyped leaves say so in an
  `@var mixed` with the reason.
- §13: the YAML loader's line filter, feature detection, block scalars,
  plain-scalar continuation, quote masking and key search move into named
  predicates and helpers, and its scalars resolve through a `match`;
  TOML's definition rules move to an internal `Loader\TomlDocument`,
  whose `assign()` takes three parameters instead of five; bracket depth
  is a `match`; `ConfigurationBuilder` lists, sorts and loads a
  directory's files in helpers instead of one nested loop.
- The YAML edge-case suite moves from `tests/Conformance` to
  `tests/Unit/Loader`, since it tests the loader, and the fixture
  directories become `Config` and `Invalid`, so every directory is a
  namespace segment; `tests/Conformance` starts with the structural ARFA
  1.3 checks.

Psalm's two suppressions: `PropertyNotSetInConstructor` for
`Configuration::$count`, `$isEmpty` and `$keys`, get-only hooks with no
backing store that Psalm 6.19 does not model (`psalm.xml`), and
`UnresolvableInclude` on the `require` of `PhpLoader`, whose purpose is to
include a file the caller names.

## Consequences

### Positive

- The package installs, and runs the same gates, commands and versions as
  the other components; both locks are audited.
- The metrics are reproducible from the repository; the harness is no
  longer needed.

### Negative

- `composer install` alone does not bring the tools; contributors install
  kcode once (CONTRIBUTING.md).
- The YAML loader has more, smaller methods; reading one construct means
  following a few calls.

## Alternatives Considered

| Alternative | Why not |
|---|---|
| Wait for a `kariricode/contract` 4.x | nothing plans it; the published 2.x line has every type the package uses |
| Keep the local aliases as deprecated | they extended types that do not exist; deprecating a name no release shipped only adds an upgrade step |
| Keep the tools in `require-dev`, or the harness outside the package | their dependencies enter the component's lock and audit surface, and metrics nobody can reproduce from the repository are claims |
| Keep `kcode quality` | it runs the toolchain's own configs, which differ from the ones the component commits and the Spec names |
| Suppress `MixedAssignment` for the whole package | it would also hide the places where a type is lost; the parsers know their value types, and the remaining reads of untyped data say why in place |
| Baseline the 13 §13 breaches | each split is mechanical and covered by the existing suites; a baseline entry needs a reason none of them has |

## References

- KaririCode Specification V4.3.3 §13, §19, §24, §25, §29, §31, §32; errata E-045, E-046.
- KaririCode Devkit 1.2.2: https://github.com/KaririCode-PHP/devkit/releases/tag/v1.2.2
- kariricode/dotenv ADR-009, kariricode/event-dispatcher ADR-009, kariricode/i18n ADR-I18N-007, kariricode/runtime ADR-007.
- ADR-002, ADR-007, ADR-008.
