<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Tests\Unit\Loader;

use KaririCode\Configurator\Loader\Utf8Encoding;

/**
 * Exposes the parsers' UTF-8 encoder to its tests.
 */
final class Utf8Encoder
{
    use Utf8Encoding;

    public function encode(int $codePoint): string
    {
        return $this->encodeCodePoint($codePoint);
    }
}
