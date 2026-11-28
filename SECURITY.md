# Security Policy — kariricode/configurator

Part of the KaririCode Framework. Security baseline: OWASP ASVS 5.0, target
level 2 (`extra.kariricode.asvs` in `composer.json`), KaririCode
Specification V4.3.3 §20–§24 and §32.

## Reporting a vulnerability

E-mail **walmir.silva@kariricode.org** with a description, the affected
version and a minimal reproduction. Do not open a public issue for
vulnerabilities. The fix timeline is agreed per report.

## Supported Versions

| Version | Status |
|---|---|
| 4.2.0 | Supported once tagged; QD-V433 PASS (`docs/quality/QD-V433-COMPLIANCE.md`) |
| 1.0.x | The published line; 4.2.0 replaces it |
| < 1.0 | Not supported |

## Threat Model

The configurator runs at boot, before the application serves anything,
and turns files and the environment into the values the application
trusts: credentials included. The configuration files and the directory
they live in are trusted to be what the operator deployed; the threats
are configuration values leaking out of the process (into a log, a stack
trace, a cache), a tampered encrypted value, a broken file taking a
running worker down, values that silently mean something else, and an
environment overlay applied where it does not belong.

```
config/*.php ─┐                                      ┌─ ${VAR} ◄── EnvironmentResolver (process, .env store, map)
config/*.json │                                      │
config/*.yaml ├─► Loader ─► MergeStrategy ─► seal() ─┼─ int:, bool:, json:, file: ◄── files named by the configuration
config/*.ini  │   (PhpLoader executes the file)      │
config/*.toml ┘                                      └─ ENC(...) ◄── key (environment or secrets manager), AES-256-GCM
                                                          │
                                         SchemaValidator ─┴─► sealed Configuration ─► typed reads
```

| Threat | Control in this package | Residual risk | ASVS 5.0 |
|---|---|---|---|
| Secrets committed with the configuration | Values can be written as `ENC(...)` (AES-256-GCM, a random 96-bit nonce per value) and decrypt only when the configuration is sealed; `EncryptedValueProcessor::encrypt()` produces them (ADR-005) | The key must stay out of the repository: it belongs to the environment or a secrets manager | V11 Cryptography, V13 Configuration |
| A tampered or truncated encrypted value | The GCM tag is verified on every decryption; a failure is `ProcessorException` (CFG_2841), never a wrong plaintext | A wrong key and a tampered value fail alike, on purpose | V11 Cryptography |
| Configuration values in exception messages and logs | Messages name files, keys, lines, variables and types, never values, payloads or keys: the parsers describe a broken line instead of repeating it, failed casts give their cause, typed reads describe the rejected value; the exception base escapes control and bidirectional characters, so a crafted key cannot forge a log line (ADR-011) | `get()` and `all()` return values by design, for the application's own use | V16 Security Logging and Error Handling |
| Keys and plaintexts in stack traces | Keys, plaintexts and processed values are `#[\SensitiveParameter]`, so a trace shows a placeholder even with `zend.exception_ignore_args=0` | Values the application passes elsewhere | V16 Security Logging and Error Handling |
| Decrypted values in a shared cache | `CacheBridge` caches the merged tree before the processors run, so `ENC(...)` stays encrypted and `${VAR}` unresolved in the cache | The raw tree may still hold plaintext values the files hold in plaintext; protect the cache as the files | V14 Data Protection |
| Code execution through a configuration file | Only `PhpLoader` executes its file, in a static closure that cannot reach the loader; YAML, TOML, INI and JSON are parsed, never evaluated, and YAML tags (`!!php/object` and the like) are rejected (CFG_2822) | A PHP configuration file is code: which files load, and who may write them, is the deployment's decision | V15 Secure Coding and Architecture |
| Values that silently mean something else | Unsupported YAML and TOML features fail instead of being misread (CFG_2822, CFG_2823); `int:`, `float:` and `bool:` reject what does not convert instead of becoming 0 or false; strict interpolation fails on an undefined variable (CFG_2830) | Lenient interpolation (`strict: false`) turns an undefined variable into an empty string by choice | V2 Validation and Business Logic |
| An overlay of another environment applied | A directory loads `<name>.<env>.<ext>` only when that environment is selected; base files have no dot before the extension (SPEC-CONFIGURATOR §5; fixed in 4.2.0) | A glob pattern loads every file it matches, by request | V13 Configuration |
| A broken file taking a running worker down | `ReloadableConfiguration` keeps the last configuration that built, records the file times and reports `ConfigReloadFailed`; reads never fail because of a reload (ADR-012) | Without a dispatcher or explicit checks, a failed reload is silent | V13 Configuration |
| Resource exhaustion by a crafted file | YAML anchors and aliases (billion laughs) are rejected, so a document cannot expand beyond its own size | Parsing time grows with the file size times its nesting depth, and recursion follows the nesting: configuration files are trusted not to nest thousands of levels | V2 Validation and Business Logic |

## ASVS 5.0

Level 2 is the target. The controls above map to these requirements, each
with a test:

| Control | Test |
|---|---|
| Encryption round-trips, uses a fresh nonce and rejects wrong keys and corrupt payloads | `EncryptedValueProcessorTest` |
| Messages carry no configuration values and cannot forge log lines | `SecretsStayOutOfMessagesTest`, `ExceptionTest::testIdentifiersAreQuotedWithControlCharactersNeutralised` |
| Keys stay out of stack traces | `SecretsStayOutOfMessagesTest::testKeysAndValuesStayOutOfStackTraces` |
| Casts fail closed | `TypeCastProcessorTest::testNumericCastsRejectWhatIsNotANumber`, `testBoolCastThrowsOnInvalidValue` |
| Unsupported YAML and TOML features fail | `YamlEdgeCasesTest`, `TomlLoaderTest` |
| Overlays load only for their environment | `ConfigurationBuilderTest::testOverlaysDoNotLoadWithoutAnEnvironment`, `testOverlaysOfAnotherEnvironmentDoNotLoad` |
| A failed reload keeps the last good configuration | `ReloadableConfigurationTest::testABrokenFileKeepsTheLastGoodConfigurationForReads` |
| No secrets committed | `tools/secret-scan.php` and its self-test |

## Supply Chain

- Runtime dependencies are `kariricode/contract`, interfaces only, and
  `kariricode/exception`, which builds on the contract alone; ext-openssl,
  bundled with PHP, is suggested for `ENC(...)` values. The parsers are
  part of the package (ADR-002, ADR-008).
- `composer.lock` is committed, resolved from Packagist with a clean
  Composer home, with no path repository.
- `composer security` validates the manifest and the lock, audits the
  runtime lock and the toolchain lock (`.kcode/composer.lock`) for
  advisories, and scans the tree for secrets; a reviewed sample in a test
  or a document carries the marker `secret-scan:allow` on its line.
- `composer sbom` writes a CycloneDX 1.5 document; CI keeps it as a build
  artifact.
- The toolchain is pinned in `devkit.php`; CI installs kcode with a verified
  checksum, pins every action by its full commit SHA and runs the
  dependency review on pull requests.
- Release tags are created by the maintainer (Spec V4.3.3 §32).

## Hardening checklist for applications

- Keep the `ENC(...)` key in the environment or a secrets manager, never in
  a configuration file or the repository.
- Leave interpolation strict in production.
- Treat PHP configuration files as code: review them, and restrict who may
  write the configuration directory.
- Configure a PSR-14 dispatcher for `ReloadableConfiguration`, or call
  `checkAndReload()` yourself, so a failed reload is seen.
- Do not log `all()` or `get()` results that may hold credentials.

## References

- OWASP ASVS 5.0: https://owasp.org/www-project-application-security-verification-standard/
- NIST SP 800-38D, Galois/Counter Mode (GCM): https://csrc.nist.gov/pubs/sp/800/38/d/final
- CWE-209, Generation of Error Message Containing Sensitive Information: https://cwe.mitre.org/data/definitions/209.html
- CWE-776, Improper Restriction of Recursive Entity References (billion laughs): https://cwe.mitre.org/data/definitions/776.html
