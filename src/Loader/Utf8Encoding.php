<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Loader;

/**
 * Encodes a Unicode scalar value as UTF-8 for the escape sequences of
 * the built-in YAML and TOML parsers, without ext-mbstring or ext-intl.
 *
 * Surrogates (U+D800–U+DFFF) and values above U+10FFFF are rejected:
 * they are not Unicode scalar values and cannot be encoded as valid
 * UTF-8.
 *
 * @internal Shared by YamlLoader and TomlLoader.
 *
 * @package KaririCode\Configurator
 * @since   4.2.0 ARFA 1.3
 */
trait Utf8Encoding
{
    /**
     * @throws \UnexpectedValueException When $codePoint is not a Unicode scalar value
     */
    private function encodeCodePoint(int $codePoint): string
    {
        if ($codePoint < 0 || $codePoint > 0x10FFFF || ($codePoint >= 0xD800 && $codePoint <= 0xDFFF)) {
            throw new \UnexpectedValueException(sprintf('invalid Unicode scalar value U+%04X', $codePoint));
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
