<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Exception;

use KaririCode\Exception\CodeBlock;
use KaririCode\Exception\ErrorCode;

// ── Exception: ConfigCode ────────────────────────────────────────────
// The error codes of the package: block 2800–2899, prefix CFG.
// ─────────────────────────────────────────────────────────────────────

/**
 * The error codes of kariricode/configurator: block 2800–2899 of the
 * ecosystem registry under the prefix CFG, so a log line that starts with
 * `[CFG_2820]` names this package and one failure. Backed enum — ARFA 1.3
 * E-003 justification: the ErrorCode contract of kariricode/exception
 * reads the integer as `getCode()`.
 *
 * Error code ranges:
 *   2800-2809  Access to a built configuration (AccessException)
 *   2810-2819  Formats and files (LoaderException)
 *   2820-2829  Parsing (LoaderException)
 *   2830-2839  Interpolation and casts (ProcessorException)
 *   2840-2849  Encryption (ProcessorException)
 *   2850-2859  Schema validation (ValidationException)
 *   2860-2869  Merging (MergeException)
 *   2870-2899  reserved
 *
 * 1.0.0 threw uncoded exceptions, and the unreleased 4.2 tarball used
 * 1000–1399, which kariricode/async-primitives holds; 2800–2899 is the
 * lowest free block of 100 codes in the registry (rule 3), claimed before
 * the first coded release (ADR-011).
 *
 * Preconditions: a code is stable API (registry rule 4): a case is never
 * renumbered and a retired value is never reused.
 *
 * Thread-safety: an enum; immutable.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     try {
 *         $config = ConfigurationBuilder::create()->withFile('/app/config/app.yaml')->build();
 *     } catch (LoaderException $e) {
 *         $syntax = $e->errorCode === ConfigCode::ParseError;
 *     }
 *
 * References: ADR-011; Spec V4.3.3 §8.4; the registry of record,
 * CODE-BLOCKS.md of kariricode/exception. ARFA 1.3 P5: stable,
 * machine-readable codes.
 *
 * @see https://github.com/KaririCode-PHP/exception/blob/main/CODE-BLOCKS.md
 */
enum ConfigCode: int implements ErrorCode
{
    case TypeMismatch = 2800;
    case ImmutableViolation = 2801;
    case UnsupportedFormat = 2810;
    case FileNotFound = 2811;
    case FileUnreadable = 2812;
    case PathTraversal = 2813;
    case ParseError = 2820;
    case InvalidReturnType = 2821;
    case UnsupportedYamlFeature = 2822;
    case UnsupportedTomlFeature = 2823;
    case UndefinedVariable = 2830;
    case TypeCastFailed = 2831;
    case InvalidEncryptionKey = 2840;
    case DecryptionFailed = 2841;
    case EncryptionFailed = 2842;
    case SchemaViolations = 2850;
    case RequiredMissing = 2851;
    case TypeConstraint = 2852;
    case MergeConflict = 2860;

    #[\Override]
    public static function prefix(): string
    {
        return 'CFG';
    }

    #[\Override]
    public static function block(): CodeBlock
    {
        return new CodeBlock(2800, 2899);
    }
}
