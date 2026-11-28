<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Loader;

use KaririCode\Configurator\Exception\LoaderException;

/**
 * Encodes a Unicode scalar value as UTF-8 for the escape sequences of
 * the YAML and TOML parsers, without ext-mbstring or ext-intl;
 * surrogates (U+D800–U+DFFF) and values above U+10FFFF are rejected,
 * because valid UTF-8 cannot carry them.
 *
 * Preconditions: used by the parsers on the code point of an escape
 * sequence.
 *
 * Thread-safety: stateless; one instance serves every caller.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     $snowman = $this->encodeCodePoint(0x2603); // "\u{2603}"
 *
 * References: Unicode 15 §3.9 (D76 Unicode scalar value), RFC 3629.
 *
 * @internal Shared by YamlLoader and TomlLoader.
 * @since 4.2.0
 */
trait Utf8Encoding
{
    /**
     * @throws LoaderException When $codePoint is not a Unicode scalar value
     */
    private function encodeCodePoint(int $codePoint): string
    {
        if ($codePoint < 0 || $codePoint > 0x10FFFF || ($codePoint >= 0xD800 && $codePoint <= 0xDFFF)) {
            throw LoaderException::invalidSyntax(sprintf('invalid Unicode scalar value U+%04X', $codePoint));
        }

        if ($codePoint < 0x80) {
            return chr($codePoint);
        }

        if ($codePoint < 0x800) {
            return chr(0xC0 | ($codePoint >> 6))
                . chr(0x80 | ($codePoint & 0x3F));
        }

        if ($codePoint < 0x10000) {
            return chr(0xE0 | ($codePoint >> 12))
                . chr(0x80 | (($codePoint >> 6) & 0x3F))
                . chr(0x80 | ($codePoint & 0x3F));
        }

        return chr(0xF0 | ($codePoint >> 18))
            . chr(0x80 | (($codePoint >> 12) & 0x3F))
            . chr(0x80 | (($codePoint >> 6) & 0x3F))
            . chr(0x80 | ($codePoint & 0x3F));
    }
}
