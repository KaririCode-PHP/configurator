<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Loader;

use KaririCode\Configurator\Contract\Loader;
use KaririCode\Configurator\Exception\ConfigurationException;
use KaririCode\Configurator\Exception\LoaderException;

/**
 * Loads configuration from TOML files using a built-in parser.
 *
 * Implements the subset of TOML 1.0 that configuration files need, with
 * the strictness of the specification: bare, quoted and dotted keys;
 * tables ([a.b]); basic ("...") and literal ('...') strings with the
 * TOML 1.0 escape set; integers (decimal without leading zeros, 0x, 0o,
 * 0b, underscores only between digits) checked for 64-bit overflow;
 * floats including inf/nan; booleans; arrays (nested, multi-line,
 * trailing comma); and single-line inline tables.
 *
 * Invalid documents are rejected with LoaderException (code 1102) and
 * the offending line: unquoted strings, malformed numbers, duplicate
 * keys or tables, and keys that would extend a value are errors, never
 * silent reinterpretations.
 *
 * Unsupported features fail with ConfigurationException (code 1008)
 * (ADR-008, mirroring the YAML parser in ADR-002):
 *   - Arrays of tables ([[table]])
 *   - Multi-line strings (""" and ''')
 *   - Date/time types (offset datetime, local datetime, date, time)
 *
 * Zero external dependencies — no third-party TOML library.
 *
 * @package KaririCode\Configurator
 * @since   4.2.0 ARFA 1.3
 */
final readonly class TomlLoader implements Loader
{
    use Utf8Encoding;

    /** Byte that replaces string content during position-preserving scans. */
    private const string MASK = "\x00";

    /** @var array<string, string> Single-character escapes of TOML 1.0 basic strings */
    private const array ESCAPES = [
        'b' => "\x08",
        't' => "\t",
        'n' => "\n",
        'f' => "\f",
        'r' => "\r",
        '"' => '"',
        '\\' => '\\',
    ];

    #[\Override]
    public function load(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw LoaderException::fileNotFound($path);
        }

        $content = file_get_contents($path);

        if ($content === false) {
            throw LoaderException::fileUnreadable($path);
        }

        try {
            return $this->parse($content);
        } catch (\UnexpectedValueException $exception) {
            throw LoaderException::parseError($path, $exception->getMessage());
        }
    }

    #[\Override]
    public function supports(string $extension): bool
    {
        return $extension === 'toml';
    }

    // ── Document ──────────────────────────────────────────────────────

    /**
     * @return array<array-key, mixed>
     *
     * @throws ConfigurationException    When an unsupported feature is found
     * @throws \UnexpectedValueException When the document is not valid TOML
     */
    private function parse(string $toml): array
    {
        if (str_starts_with($toml, "\xEF\xBB\xBF")) {
            $toml = substr($toml, 3);
        }

        $lines = explode("\n", str_replace("\r\n", "\n", $toml));
        $document = new TomlDocument();
        $table = [];

        for ($index = 0, $count = count($lines); $index < $count; ++$index) {
            $lineNumber = $index + 1;
            $line = trim($this->stripComment($lines[$index]));

            if ($line === '') {
                continue;
            }

            $this->detectUnsupportedSyntax($line, $lineNumber);

            try {
                if ($line[0] === '[') {
                    $table = $this->parseTableHeader($line);
                    $document->declareTable($table);

                    continue;
                }

                $equals = strpos($this->maskStrings($line), '=');

                if ($equals === false) {
                    throw new \UnexpectedValueException("expected 'key = value'");
                }

                $key = $this->parseKey(substr($line, 0, $equals));
                [$valueText, $index] = $this->collectValue(trim(substr($line, $equals + 1)), $lines, $index);
                $document->assign($table, $key, $this->parseValue($valueText, $lineNumber));
            } catch (\UnexpectedValueException $exception) {
                throw new \UnexpectedValueException(
                    sprintf('%s on line %d', $exception->getMessage(), $lineNumber),
                    0,
                    $exception,
                );
            }
        }

        return $document->root;
    }

    /**
     * @throws ConfigurationException
     */
    private function detectUnsupportedSyntax(string $line, int $lineNumber): void
    {
        if (str_starts_with($line, '[[')) {
            throw ConfigurationException::unsupportedTomlFeature(
                'TOML arrays of tables ([[table]]) are not supported',
                $lineNumber,
            );
        }

        if (str_contains($line, '"""') || str_contains($line, "'''")) {
            throw ConfigurationException::unsupportedTomlFeature(
                'TOML multi-line strings (""" or \'\'\') are not supported',
                $lineNumber,
            );
        }
    }

    /**
     * Joins the continuation lines of a multi-line array.
     *
     * @param list<string> $lines
     *
     * @return array{0: string, 1: int} the value text and the index of its last line
     *
     * @throws \UnexpectedValueException When the array is never closed
     */
    private function collectValue(string $value, array $lines, int $index): array
    {
        if (!str_starts_with($value, '[')) {
            return [$value, $index];
        }

        $count = count($lines);

        while ($this->bracketDepth($value) > 0) {
            if (++$index >= $count) {
                throw new \UnexpectedValueException('unterminated array');
            }

            $value .= "\n" . trim($this->stripComment($lines[$index]));
        }

        return [$value, $index];
    }

    // ── Keys ──────────────────────────────────────────────────────────

    /**
     * @return non-empty-list<string>
     *
     * @throws \UnexpectedValueException When the header is malformed
     */
    private function parseTableHeader(string $line): array
    {
        if (!str_ends_with($line, ']')) {
            throw new \UnexpectedValueException(sprintf("malformed table header '%s'", $line));
        }

        return $this->parseKey(substr($line, 1, -1));
    }

    /**
     * Parses a bare, quoted, or dotted key into its segments. Whitespace
     * around dots is ignored; a quoted segment may be empty, a bare one
     * may not.
     *
     * @return non-empty-list<string>
     *
     * @throws \UnexpectedValueException When the key is malformed
     */
    private function parseKey(string $key): array
    {
        $segments = [];
        $length = strlen($key);
        $position = 0;

        while (true) {
            $position = $this->skipWhitespace($key, $position);
            $char = $key[$position] ?? '';

            if ($char === '"' || $char === "'") {
                $close = $this->closingQuote($key, $position);

                if ($close === -1) {
                    throw new \UnexpectedValueException(sprintf("unterminated quoted key '%s'", trim($key)));
                }

                $segments[] = $this->decodeString(substr($key, $position, $close - $position + 1));
                $position = $close + 1;
            } elseif (preg_match('/[A-Za-z0-9_-]+/A', $key, $match, 0, $position) === 1) {
                $segments[] = $match[0];
                $position += strlen($match[0]);
            } else {
                throw new \UnexpectedValueException(sprintf("invalid key '%s'", trim($key)));
            }

            $position = $this->skipWhitespace($key, $position);

            if ($position >= $length) {
                return $segments;
            }

            if ($key[$position] !== '.') {
                throw new \UnexpectedValueException(sprintf("invalid key '%s'", trim($key)));
            }

            ++$position;
        }
    }

    // ── Values ────────────────────────────────────────────────────────

    /**
     * @return string|int|float|bool|array<array-key, mixed>
     *
     * @throws ConfigurationException    When the value is a date/time
     * @throws \UnexpectedValueException When the value is not valid TOML
     */
    private function parseValue(string $value, int $lineNumber): string|int|float|bool|array
    {
        $first = $value[0] ?? '';

        if ($first === '') {
            throw new \UnexpectedValueException('missing value');
        }

        if ($first === '"' || $first === "'") {
            if ($this->closingQuote($value, 0) !== strlen($value) - 1) {
                throw new \UnexpectedValueException(sprintf('invalid string %s', $value));
            }

            return $this->decodeString($value);
        }

        if ($first === '[') {
            return $this->parseArray($value, $lineNumber);
        }

        if ($first === '{') {
            return $this->parseInlineTable($value, $lineNumber);
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}|^\d{2}:\d{2}:\d{2}/', $value) === 1) {
            throw ConfigurationException::unsupportedTomlFeature(
                'TOML date/time values are not supported — quote them as strings',
                $lineNumber,
            );
        }

        return match ($value) {
            'true' => true,
            'false' => false,
            'inf', '+inf' => INF,
            '-inf' => -INF,
            'nan', '+nan', '-nan' => NAN,
            default => $this->parseNumber($value),
        };
    }

    /**
     * @throws \UnexpectedValueException When the text is not a TOML number or overflows 64 bits
     */
    private function parseNumber(string $value): int|float
    {
        if (preg_match('/^[+-]?(?:0|[1-9](?:_?\d)*)$/', $value) === 1) {
            // The pattern guarantees an integer literal; FILTER_VALIDATE_INT
            // refuses one beyond 64 bits, which toInteger() then rejects.
            $integer = filter_var(str_replace('_', '', $value), FILTER_VALIDATE_INT);

            return $this->toInteger($integer === false ? INF : $integer, $value);
        }

        if (preg_match('/^0x([0-9A-Fa-f](?:_?[0-9A-Fa-f])*)$/', $value, $match) === 1) {
            return $this->toInteger(hexdec(str_replace('_', '', $match[1])), $value);
        }

        if (preg_match('/^0o([0-7](?:_?[0-7])*)$/', $value, $match) === 1) {
            return $this->toInteger(octdec(str_replace('_', '', $match[1])), $value);
        }

        if (preg_match('/^0b([01](?:_?[01])*)$/', $value, $match) === 1) {
            return $this->toInteger(bindec(str_replace('_', '', $match[1])), $value);
        }

        if (
            preg_match('/^[+-]?(?:0|[1-9](?:_?\d)*)(?:\.\d(?:_?\d)*)?(?:[eE][+-]?\d(?:_?\d)*)?$/', $value) === 1
            && strpbrk($value, '.eE') !== false
        ) {
            return (float) str_replace('_', '', $value);
        }

        throw new \UnexpectedValueException(sprintf("invalid value '%s' (strings must be quoted)", $value));
    }

    /**
     * @throws \UnexpectedValueException When the number does not fit in a 64-bit integer
     */
    private function toInteger(int|float $number, string $source): int
    {
        if (!is_int($number)) {
            throw new \UnexpectedValueException(sprintf("integer '%s' does not fit in 64 bits", $source));
        }

        return $number;
    }

    /**
     * Decodes a complete quoted token: '...' literally, "..." with escapes.
     *
     * @throws \UnexpectedValueException On control characters or invalid escapes
     */
    private function decodeString(string $token): string
    {
        $body = substr($token, 1, -1);

        if (preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $body) === 1) {
            throw new \UnexpectedValueException('control characters must be escaped in strings');
        }

        if ($token[0] === "'") {
            return $body;
        }

        $result = '';
        $length = strlen($body);

        for ($i = 0; $i < $length; ++$i) {
            if ($body[$i] !== '\\') {
                $result .= $body[$i];

                continue;
            }

            $escape = $body[++$i] ?? '';

            if (isset(self::ESCAPES[$escape])) {
                $result .= self::ESCAPES[$escape];

                continue;
            }

            $width = match ($escape) {
                'u' => 4,
                'U' => 8,
                default => throw new \UnexpectedValueException(sprintf("invalid escape sequence '\\%s'", $escape)),
            };

            $hex = substr($body, $i + 1, $width);

            if (preg_match('/^[0-9A-Fa-f]{' . $width . '}$/', $hex) !== 1) {
                throw new \UnexpectedValueException(sprintf("invalid escape sequence '\\%s%s'", $escape, $hex));
            }

            $result .= $this->encodeCodePoint((int) hexdec($hex));
            $i += $width;
        }

        return $result;
    }

    /**
     * @return list<string|int|float|bool|array<array-key, mixed>>
     *
     * @throws \UnexpectedValueException When the array is malformed
     */
    private function parseArray(string $value, int $lineNumber): array
    {
        $this->assertClosesAtEnd($value);

        return array_map(
            fn(string $item): string|int|float|bool|array => $this->parseValue($item, $lineNumber),
            $this->splitItems(substr($value, 1, -1), trailingComma: true),
        );
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws \UnexpectedValueException When the inline table is malformed or repeats a key
     */
    private function parseInlineTable(string $value, int $lineNumber): array
    {
        $this->assertClosesAtEnd($value);

        if (str_contains($value, "\n")) {
            throw new \UnexpectedValueException('inline tables must be on a single line');
        }

        $table = new TomlDocument();

        foreach ($this->splitItems(substr($value, 1, -1), trailingComma: false) as $entry) {
            $equals = strpos($this->maskStrings($entry), '=');

            if ($equals === false) {
                throw new \UnexpectedValueException(sprintf("expected 'key = value' in inline table, got '%s'", $entry));
            }

            $key = $this->parseKey(substr($entry, 0, $equals));
            $table->assign([], $key, $this->parseValue(trim(substr($entry, $equals + 1)), $lineNumber));
        }

        return $table->root;
    }

    /**
     * Splits collection content on top-level commas. Empty items are
     * errors, except a single trailing comma where $trailingComma allows.
     *
     * @return list<string>
     *
     * @throws \UnexpectedValueException On an empty item
     */
    private function splitItems(string $inner, bool $trailingComma): array
    {
        if (trim($inner) === '') {
            return [];
        }

        $masked = $this->maskStrings($inner);
        $pieces = [];
        $depth = 0;
        $start = 0;
        $length = strlen($masked);

        for ($i = 0; $i < $length; ++$i) {
            $char = $masked[$i];

            $depth += match ($char) {
                '[', '{' => 1,
                ']', '}' => -1,
                default => 0,
            };

            if ($char === ',' && $depth === 0) {
                $pieces[] = trim(substr($inner, $start, $i - $start));
                $start = $i + 1;
            }
        }

        $pieces[] = trim(substr($inner, $start));

        if ($trailingComma && count($pieces) > 1 && end($pieces) === '') {
            array_pop($pieces);
        }

        if (in_array('', $pieces, true)) {
            throw new \UnexpectedValueException("unexpected ',' (empty item)");
        }

        return $pieces;
    }

    /**
     * @throws \UnexpectedValueException When text follows the closing bracket
     */
    private function assertClosesAtEnd(string $value): void
    {
        $masked = $this->maskStrings($value);
        $depth = 0;
        $last = strlen($masked) - 1;

        for ($i = 0; $i <= $last; ++$i) {
            $depth += match ($masked[$i]) {
                '[', '{' => 1,
                ']', '}' => -1,
                default => 0,
            };

            if ($depth === 0 && $i < $last) {
                throw new \UnexpectedValueException(sprintf("unexpected text after '%s'", substr($value, 0, $i + 1)));
            }
        }

        if ($depth !== 0) {
            throw new \UnexpectedValueException(sprintf("unbalanced brackets in '%s'", $value));
        }
    }

    // ── Utility ───────────────────────────────────────────────────────

    /**
     * Replaces every string (quotes included) with MASK bytes, preserving
     * positions. Basic strings honour backslash escapes; literal strings
     * have none.
     */
    private function maskStrings(string $text): string
    {
        $masked = $text;
        $length = strlen($text);

        for ($i = 0; $i < $length; ++$i) {
            if ($text[$i] !== '"' && $text[$i] !== "'") {
                continue;
            }

            $close = $this->closingQuote($text, $i);
            $last = $close === -1 ? $length - 1 : $close;
            $masked = substr_replace($masked, str_repeat(self::MASK, $last - $i + 1), $i, $last - $i + 1);
            $i = $last;
        }

        return $masked;
    }

    /**
     * Position of the quote closing the string opened at $open, or -1.
     */
    private function closingQuote(string $text, int $open): int
    {
        $quote = $text[$open];
        $length = strlen($text);

        for ($i = $open + 1; $i < $length; ++$i) {
            if ($quote === '"' && $text[$i] === '\\') {
                ++$i;

                continue;
            }

            if ($text[$i] === $quote) {
                return $i;
            }
        }

        return -1;
    }

    private function stripComment(string $line): string
    {
        $hash = strpos($this->maskStrings($line), '#');

        return $hash === false ? $line : substr($line, 0, $hash);
    }

    private function bracketDepth(string $text): int
    {
        $masked = $this->maskStrings($text);

        return substr_count($masked, '[') + substr_count($masked, '{')
            - substr_count($masked, ']') - substr_count($masked, '}');
    }

    private function skipWhitespace(string $text, int $from): int
    {
        $length = strlen($text);

        while ($from < $length && ($text[$from] === ' ' || $text[$from] === "\t")) {
            ++$from;
        }

        return $from;
    }
}
