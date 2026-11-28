# QD-V433-COMPLIANCE: kariricode/configurator 4.2.0

- **Status: PENDING** (automated gates measured locally; CI run pending)
- **Date:** 2026-11-28
- **Component:** kariricode/configurator 4.2.0; kariricode/contract ^2.11 (2.12.1 locked); kariricode/exception ^2.0.2 (2.0.2 locked)
- **Standards:** KaririCode Spec V4.3.3 §29 (gates) and §32 (supply chain); ARFA 1.3 level 1 (Structural); OWASP ASVS 5.0 level 2
- **Authors:** Walmir Silva <walmir.silva@kariricode.org>

Every automated gate of §29.1 passes locally (§1). The status turns PASS
when CI confirms the gates on PHP 8.4 and the suites on PHP 8.5 with the
lowest dependencies, and FAIL if a gate fails. PASS covers the automated
gates (G1, G4, G5, G6); G3 needs a second reviewer (§8). No score is
assigned (E-006). This is the first quality record of the package: the
4.2 tarball measured itself with a harness kept outside the package
(PHPStan level max on `src`, PHPUnit), recorded in
`docs/DEEP-REVIEW-V4.2.md`.

## 1. Gates (Spec V4.3.3 §29.1)

Measured locally on 2026-11-28 (§2).

| Gate | Command | Budget | Measured | Result |
|---|---|---|---|---|
| Tests | `composer test` | all pass, no warning, notice, deprecation or risky test | 594 tests: unit 492, integration 75 (real files in a temporary directory, recording PSR-14 and PSR-16 doubles), conformance 27 | pass |
| Conformance | `composer test-conformance` | all pass | 27 tests: `ArfaConformanceTest` 24 (22 rules and 2 documentation checks, §3), `CodeBlockRegistryTest` 3 | pass |
| PHPStan | `composer phpstan` | level max on `src` and `tests`, strict and PHPUnit rules, cognitive complexity ≤ 15 | 0 errors | pass |
| Psalm | `composer psalm` | level 1 on `src`, no baseline | 0 errors; two suppressions with their reasons in place (§6); `#[\Override]` required | pass |
| Coding standard | `composer cs-check` | PER-CS (E-045, E-046) | 0 of 81 files to fix | pass |
| Complexity | `composer complexity`, `composer phpstan` | cyclomatic ≤ 10, nesting ≤ 3, parameters ≤ 4 per method of `src`; cognitive ≤ 15 (§13) | 245 methods; no accepted breach (empty baseline) | pass |
| Mutation | `composer mutation` | MSI ≥ 80 and covered-code MSI ≥ 80 (§19.4) | 2,094 mutants: 1,992 killed, 28 timed out, 2 errors, 72 escaped; MSI 96.56 %, covered-code MSI 96.56 %; mutation code coverage 100 % (§7) | pass |
| Security | `composer security` | valid manifest and lock, no advisory, no secret | manifest and lock valid; no advisories in the runtime lock or in `.kcode/composer.lock`; secret scan 0 findings (self-test ok) | pass |

Line coverage of `src` by the unit and integration suites is 99.06 %
(1,367 of 1,380 statements; PCOV, `phpunit --coverage-clover`); §29.1 sets
no coverage budget, mutation testing is the gate (§7). The 13 statements
left are the `fileUnreadable()` throws that follow `is_readable()` in the
five file loaders and the `filemtime()` failure in
`ReloadableConfiguration` (each needs the file to change between two
system calls), an OpenSSL encryption failure, and six parser branches the
corpora do not reach: in TOML, a quoted key left open, which the line
scanner rejects first, and one redefinition check of `TomlDocument`; in
YAML, two null returns for an empty value that the callers answer first,
a sequence that runs to the end of its block, and the unbalanced-collection
check, which the multi-line join leaves unreachable.

`composer sbom` wrote a CycloneDX 1.5 document with 2 runtime components
(kariricode/contract 2.12.1, kariricode/exception 2.0.2) and 118
development components marked `excluded`.

## 2. Environment and Metadata

| Item | Value |
|---|---|
| Runtime | PHP 8.4.28 CLI, PCOV, ext-openssl |
| Toolchain | KaririCode Devkit 1.2.2 with the root configs (ADR-009): PHPUnit 11.5.56, PHPStan 2.3.7 with phpstan-strict-rules 2.1.2 and phpstan-phpunit 2.1.4, Psalm 6.19.1, PHP-CS-Fixer 3.95.27, Infection 0.35.5, tomasvotruba/cognitive-complexity 1.2.0 |
| Lock | `composer.lock` resolved from Packagist with a clean Composer home; no path repository |
| `extra.kariricode` (gate G4, errata E-044) | `arfa` 1.3 level 1, `asvs` 5.0 level 2, `spec` 4.3.3, `error-code-range` CFG 2800–2899 |
| Error codes | `ConfigCode`, 19 codes in 2800–2860, prefix CFG; CODE-BLOCKS.md on kariricode/exception's main records the claim and the collision of the tarball's 1000–1399 (2c4f6c2); the installed 2.0.2 predates it (§8) |

## 3. Conformance Rules (§29.2)

`tests/Conformance/ArfaConformanceTest.php`; all 24 pass.

| Rule | Reference |
|---|---|
| Every source file is strict and declares exactly one named type | §25, PSR-4 |
| Docblocks meet the five comment criteria (C1 summary; Preconditions, Thread-safety, Side effects, Example, References) | §17 |
| Section markers are 72 columns wide (sources and tests) | §25 |
| Concrete classes are final | §5 |
| Values (the lifecycle events) are final readonly | §5.2 |
| Readonly classes declare no property hooks, nor `readonly` again inside | E-040 |
| Class constants are typed | §25 |
| Inheritance inside the package is at most two levels deep | §18 |
| No type keeps mutable static state | §5 |
| Names and methods avoid the forbidden patterns (Interface suffix, Manager/Helper/Util, toArray/fromArray/jsonSerialize, magic accessors) | §18, E-001 |
| Public methods are not bare getters (no parameter) or setters (one parameter); a typed read that takes a path is a lookup | §18 |
| Regular expressions anchor the end with `\z`, never `$` | §24 |
| Call sites with three or more arguments name them (printf family exempt) | §30 |
| Contracts are interfaces | §5, §31 |
| Enums are pure unless an external contract reads the value (`ConfigCode`), with the justification written down | E-003 |
| Every directory is a namespace segment | PSR-4 |
| Error codes are unique and inside the reserved block | §8 |
| Exceptions are built only by their named constructors; nothing throws an uncoded exception | §8 |
| Every concrete exception belongs to exactly one contract family, implements the package marker and extends a kariricode/exception base | §8.4 |
| `composer.json` declares the ARFA, ASVS and Spec versions and the code range; runtime dependencies are PHP, kariricode/contract and kariricode/exception | §29.3 G4, E-044, §31 |
| `composer.json` exposes the eight quality gates | §29.1 |
| Supply chain is pinned: lock committed, CI actions by commit SHA | §32 |

Two further tests check the documentation: 12 ADRs with the seven
canonical sections, this record's status line, the SECURITY.md sections
and the CHANGELOG headings; and that the README, SECURITY.md,
CONTRIBUTING.md, the ADRs, the SPEC, this record and the sources cite
Spec V4.3.3. CHANGELOG.md and `docs/DEEP-REVIEW-V4.2.md` keep their
record of the tarball's review, which cited the directive of its time.

`CodeBlockRegistryTest` checks that `ConfigCode` is a valid code table,
that the installed CODE-BLOCKS.md leaves 2800–2899 and the prefix CFG to
this package (and, once it lists the block, that the row matches), and
the documented sub-ranges.

## 4. Error Model (§8)

Every exception implements the marker `ConfigurationException`, which
declares `$errorCode`, and extends the kariricode/exception base of the
Configuration family (ADR-011): `AccessException` 2800–2801,
`LoaderException` 2810–2823, `ProcessorException` 2830–2842,
`ValidationException` 2850–2852, `MergeException` 2860. Every failure of
the package is a configuration an operator fixes, so the edge answers
500. `getCode()` is the `ConfigCode` number; messages start with
`[CFG_<code>]`, quote paths and keys, and carry no value, payload or key;
the parsers describe a broken line instead of repeating it. The 4.2
tarball's 1000–1399 overlapped kariricode/async-primitives; no release
threw them, so the codes moved before the first coded release.

## 5. Security Controls (ASVS 5.0, level 2 declared)

| Control | Test |
|---------|------|
| AES-256-GCM round-trips with a fresh nonce; wrong keys and tampered payloads fail | `EncryptedValueProcessorTest` |
| Messages carry no configuration values and cannot forge log lines | `SecretsStayOutOfMessagesTest`, `ExceptionTest` |
| Keys stay out of stack traces | `SecretsStayOutOfMessagesTest::testKeysAndValuesStayOutOfStackTraces` |
| Casts fail closed | `TypeCastProcessorTest::testNumericCastsRejectWhatIsNotANumber` |
| Unsupported YAML and TOML features fail; invalid documents are rejected with the line | `YamlCorpusTest`, `YamlEdgeCasesTest`, `TomlCorpusTest`, `TomlLoaderTest` |
| Overlays load only for their environment | `ConfigurationBuilderTest::testOverlaysDoNotLoadWithoutAnEnvironment`, `testOverlaysOfAnotherEnvironmentDoNotLoad` |
| A failed reload keeps the last good configuration | `ReloadableConfigurationTest` |
| No secrets committed | `tools/secret-scan.php` and its self-test |

SECURITY.md keeps the threat model, the residual risks and the supply-chain
controls.

## 6. Judgment Calls

| Topic | Decision | Reason |
|-------|----------|--------|
| Exception family | Configuration for every exception | a broken file, value, key or schema is fixed by an operator, never retried by a caller; NotFound would answer 404 and Runtime suggests a retry (ADR-011) |
| Code block | 2800–2899 under rule 3, not a 400-code block under rule 5 | the tarball's codes were never released; 19 codes fit a block of 100 (ADR-011) |
| Psalm suppressions | `PropertyNotSetInConstructor` for the three get hooks of `Configuration`; `UnresolvableInclude` in `PhpLoader` | Psalm 6.19 does not model get-only hooks; including a caller's file is the loader's purpose (ADR-009) |
| YAML error positions | the key and the indentation, not the line | the parser works on normalized lines that no longer map to the file; repeating the line would leak values (ADR-011) |
| Overlays | `<name>.<env>.<ext>` loads only for its environment; dotted base names become overlays | the tarball loaded every overlay as a base file (CHANGELOG, Fixed) |
| Hot reload failures | keep the last good configuration; rethrow only from explicit checks | a broken file must not fail requests once per interval (ADR-012) |
| `getInt()` of a float | truncates, as `(int)` does | the contract's numeric coercion, unchanged from the tarball |
| Mutation target | MSI ≥ 80 and covered MSI ≥ 80 | the Spec's floor; first measurement of the package (§7) |
| PER-CS 3.1 | checked with `@PER-CS3.0` | PHP-CS-Fixer 3.95 implements 3.0 (E-046) |

## 7. Mutation Testing

Infection 0.35.5 with `infection.json5`: every default mutator on `src`,
the unit and integration suites, only the test cases that cover each
mutant, a 5 s time-out.

| Run | Since the previous run | Mutants | Escaped | MSI | Covered-code MSI |
|---|---|---|---|---|---|
| 1 | first measurement of the package | — | 360 | 82.76 % | — |
| 2 | tests for the overlays, the casts, the hot reload and the bridges | — | — | 92.85 % | — |
| 3 | YAML and TOML corpora, `CompositeValidator` | 2,066 | 141 | 93.18 % | 93.18 % |
| 4 | bracket matching in both parsers; tests for scalar JSON documents, `getBool()` of a float, casts that pass through, empty arrays in a merge, missing directories | 2,059 | 135 | 93.44 % | 93.44 % |
| 5 | corpus documents aimed at the escaped mutants: CRLF and folded block scalars, block scalars as items, keys with brackets, look-alike anchors; TOML without spaces, with escaped quotes, with inline tables in arrays | 2,070 | 107 | 94.83 % | 94.83 % |
| 6 | blank lines of block scalars keep the spaces past the indentation (YAML 1.2); documents for flow mappings across lines, JSON-style keys, quoted hashes, comments after a tab and the folding of `>` scalars | 2,094 | 72 | 96.56 % | 96.56 % |

Run 6 is this record's: 1,992 killed, 28 timed out, 2 errors, 72 escaped;
every mutant is covered by a test. Each document added in runs 5 and 6
gives the result PyYAML gives, or YAML 1.2 where PyYAML follows 1.1 (a
tab before a comment). The escaped mutants, by file:

| File | Escaped | What they change, and why no test sees it |
|---|---|---|
| `Loader/YamlLoader.php` | 49 | offsets one character off where `skipSpaces()`, `trim()` or a later mask absorbs the difference; `trim()` calls on text that is already trimmed; guard conditions whose first operand always holds where they are reached; loop bounds and comparisons no line reaches; the depth sign in `findKeyColon()` (a balanced key reads the same with either sign); the end-of-text defaults of the quote and comment scans; the cast of the indentation indicator (PHP converts the numeric string the same way) |
| `Loader/TomlLoader.php` | 10 | `hexdec()`, `octdec()` and `bindec()` given the whole match: they skip the `0x`, `0o` and `0b` prefix, so the value is the same (equivalent); the CRLF replacement and a `trim()` of lines that are trimmed again; the depth sign of the item splitter (equivalent for balanced brackets); the trailing-comma count (`> 1` and `>= 1` differ only for an empty array, which has no comma); masking past the end of an unterminated string; the unterminated-quoted-key check, which the line scanner pre-empts |
| `Integration/ReloadableConfiguration.php` | 5 | the two `clearstatcache()` calls (equivalent inside one test process, where the test sets the file times itself), the interval comparison `<` against `<=` (the boundary instant is not observable), the `hrtime()` divisor off by one (the reported duration changes by a millionth) |
| `Loader/TomlDocument.php` | 5 | the order and separator of the path identifier (any injective encoding builds the same table index), the prefix passed to the parent claim (one segment longer, or the whole path, claims the same parent for every key of the corpora) |
| `Loader/IniLoader.php` | 2 | the warning handler's return value (the built-in handler then also sees the warning; the exception is the same), the one-item slice of a tree that always has one key (equivalent) |
| `ConfigurationBuilder.php` | 1 | `array_values()` over a variadic list (equivalent) |

## 8. Open Items

| # | Item | Closes when |
|---|---|---|
| 1 | G3: independent review for P0 findings | a second reviewer signs off |
| 2 | CODE-BLOCKS.md on kariricode/exception's main records CFG 2800–2899 as claimed (2c4f6c2); the installed 2.0.2 predates the claim | a kariricode/exception release ships it |
| 3 | YAML: a flow collection as the whole document is not supported; blank lines inside a multi-line plain scalar fold into a space | the structural phase accepts a top-level flow collection |
| 4 | Escaped mutants (§7) | each is killed by a test or stays documented as equivalent |
