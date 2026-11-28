<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Exception;

use KaririCode\Contract\Exception\ExceptionInterface;
use KaririCode\Exception\ErrorCode;

// ── Exception: ConfigurationException ────────────────────────────────
// Marker of every exception the package throws.
// ─────────────────────────────────────────────────────────────────────

/**
 * The marker every exception of kariricode/configurator implements, so
 * one `catch` covers the package, as the base class of the same name did
 * in the 4.2 tarball; each concrete exception also extends the
 * kariricode/exception base of the Configuration family and carries a
 * ConfigCode case in `$errorCode`.
 *
 * Every failure of the package is a configuration that an operator
 * fixes: a file, a value, a key or a schema. The HTTP edge answers 500
 * and keeps the detail in the logs; messages name files, keys and types,
 * never configuration values.
 *
 * Preconditions: implemented only by the final exceptions of this
 * namespace.
 *
 * Thread-safety: an interface; no state.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     try {
 *         $config = ConfigurationBuilder::create()->withDirectory('/app/config')->build();
 *     } catch (ConfigurationException $e) {
 *         fwrite(STDERR, $e->getMessage() . "\n"); // [CFG_28xx] …, no configuration values
 *     }
 *
 * References: ADR-011; Spec V4.3.3 §8.4. ARFA 1.3 P5: one catch point,
 * coded failures.
 *
 * @see https://github.com/KaririCode-PHP/exception/blob/main/README.md
 */
interface ConfigurationException extends ExceptionInterface
{
    /** The ConfigCode case of the failure; `getCode()` is its integer. */
    public ErrorCode $errorCode { get; }
}
