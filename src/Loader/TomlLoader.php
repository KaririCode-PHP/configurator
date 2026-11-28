<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Loader;

use KaririCode\Configurator\Contract\Loader;
use KaririCode\Configurator\Exception\ConfigCode;
use KaririCode\Configurator\Exception\LoaderException;

/**
 * Loads TOML files with a built-in parser for the subset of TOML 1.0
 * that configuration needs, with the strictness of the specification:
 * bare, quoted and dotted keys; tables; basic and literal strings with
 * the TOML escape set; integers (decimal without leading zeros, 0x, 0o,
 * 0b, underscores only between digits) checked for 64-bit overflow;
 * floats with inf and nan; booleans; arrays (nested, multi-line,
 * trailing comma); and single-line inline tables.
 *
 * Invalid documents fail with LoaderException CFG_2820 and the line:
 * unquoted strings, malformed numbers, duplicate keys or tables and keys
 * that would extend a value are errors, never reinterpretations. Arrays
 * of tables, multi-line strings and date-time values fail with CFG_2823
 * (ADR-008).
 *
 * Preconditions: the file is UTF-8 text.
 *
 * Thread-safety: stateless; one instance serves every caller.
 *
 * Side effects: load() reads the file.
 *
 * Example:
 *
 *     $data = new TomlLoader()->load('/app/config/app.toml');
 *
 * References: TOML 1.0, ADR-008, ADR-011. ARFA 1.3 P4: one format per
 * loader, no dependency.
 *
 * @since 4.2.0
 */
final readonly class TomlLoader implements Loader
{
    use Utf8Encoding;

    /** Byte that replaces string content during position-preserving scans. */
    private const string MASK = "\x00";

    /** @var array<string, string> The closing bracket each opening one expects */
    private const array CLOSERS = ['[' => ']', '{' => '}'];

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
        } catch (LoaderException $exception) {
            throw $exception->errorCode === ConfigCode::ParseError
                ? LoaderException::parseError(path: $path, reason: $exception->reason, previous: $exception)
                : $exception;
        }
    }

    #[\Override]
    public function supports(string $extension): bool
    {
        return $extension === 'toml';
    }

    // ── Document ─────────────────────────────────────────────────────────

    /**
     * @return array<array-key, mixed>
     *
     * @throws LoaderException    When an unsupported feature is found
     * @throws LoaderException When the document is not valid TOML
     */
    private function parse(string $toml): array
    {
        if (str_starts_with($toml, "\xEF\xBB\xBF")) {
            $toml = substr($toml, 3);
        }

        $lines = explode("\n", str_replace(search: "\r\n", replace: "\n", subject: $toml));
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
                    throw LoaderException::invalidSyntax("expected 'key = value'");
                }

                $key = $this->parseKey(substr(string: $line, offset: 0, length: $equals));
                [$valueText, $index] = $this->collectValue(value: trim(substr($line, $equals + 1)), lines: $lines, index: $index);
                $document->assign(table: $table, key: $key, value: $this->parseValue($valueText, $lineNumber));
            } catch (LoaderException $exception) {
                throw $exception->errorCode === ConfigCode::ParseError
                    ? LoaderException::invalidSyntax(sprintf('%s on line %d', $exception->reason, $lineNumber))
                    : $exception;
            }
        }

        return $document->root;
    }

    /**
     * @throws LoaderException
     */
    private function detectUnsupportedSyntax(string $line, int $lineNumber): void
    {
        if (str_starts_with($line, '[[')) {
            throw LoaderException::unsupportedTomlFeature(
                'TOML arrays of tables ([[table]]) are not supported',
                $lineNumber,
            );
        }

        if (str_contains($line, '"""') || str_contains($line, "'''")) {
            throw LoaderException::unsupportedTomlFeature(
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
     * @throws LoaderException When the array is never closed
     */
    private function collectValue(string $value, array $lines, int $index): array
    {
        if (!str_starts_with($value, '[')) {
            return [$value, $index];
        }

        $count = count($lines);

        while ($this->bracketDepth($value) > 0) {
            if (++$index >= $count) {
                throw LoaderException::invalidSyntax('unterminated array');
            }

            $value .= "\n" . trim($this->stripComment($lines[$index]));
        }

        return [$value, $index];
    }

    // ── Keys ─────────────────────────────────────────────────────────────

    /**
     * @return non-empty-list<string>
     *
     * @throws LoaderException When the header is malformed
     */
    private function parseTableHeader(string $line): array
    {
        if (!str_ends_with($line, ']')) {
            throw LoaderException::invalidSyntax(sprintf("malformed table header '%s'", $line));
        }

        return $this->parseKey(substr(string: $line, offset: 1, length: -1));
    }

    /**
     * Parses a bare, quoted, or dotted key into its segments. Whitespace
     * around dots is ignored; a quoted segment may be empty, a bare one
     * may not.
     *
     * @return non-empty-list<string>
     *
     * @throws LoaderException When the key is malformed
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
                    throw LoaderException::invalidSyntax(sprintf("unterminated quoted key '%s'", trim($key)));
                }

                $segments[] = $this->decodeString(substr(string: $key, offset: $position, length: $close - $position + 1));
                $position = $close + 1;
            } elseif (preg_match(pattern: '/[A-Za-z0-9_-]+/A', subject: $key, matches: $match, flags: 0, offset: $position) === 1) {
                $segments[] = $match[0];
                $position += strlen($match[0]);
            } else {
                throw LoaderException::invalidSyntax(sprintf("invalid key '%s'", trim($key)));
            }

            $position = $this->skipWhitespace($key, $position);

            if ($position >= $length) {
                return $segments;
            }

            if ($key[$position] !== '.') {
                throw LoaderException::invalidSyntax(sprintf("invalid key '%s'", trim($key)));
            }

            ++$position;
        }
    }

    // ── Values ───────────────────────────────────────────────────────────

    /**
     * @return string|int|float|bool|array<array-key, mixed>
     *
     * @throws LoaderException    When the value is a date/time
     * @throws LoaderException When the value is not valid TOML
     */
    private function parseValue(string $value, int $lineNumber): string|int|float|bool|array
    {
        $first = $value[0] ?? '';

        if ($first === '') {
            throw LoaderException::invalidSyntax('missing value');
        }

        if ($first === '"' || $first === "'") {
            if ($this->closingQuote($value, 0) !== strlen($value) - 1) {
                throw LoaderException::invalidSyntax('invalid string (unterminated, or text after the closing quote)');
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
            throw LoaderException::unsupportedTomlFeature(
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
     * @throws LoaderException When the text is not a TOML number or overflows 64 bits
     */
    private function parseNumber(string $value): int|float
    {
        if (preg_match('/^[+-]?(?:0|[1-9](?:_?\d)*)\z/', $value) === 1) {
            // The pattern guarantees an integer literal; FILTER_VALIDATE_INT
            // refuses one beyond 64 bits, which toInteger() then rejects.
            $integer = filter_var(str_replace(search: '_', replace: '', subject: $value), FILTER_VALIDATE_INT);

            return $this->toInteger($integer === false ? INF : $integer, $value);
        }

        if (preg_match(pattern: '/^0x([0-9A-Fa-f](?:_?[0-9A-Fa-f])*)\z/', subject: $value, matches: $match) === 1) {
            return $this->toInteger(hexdec(str_replace(search: '_', replace: '', subject: $match[1])), $value);
        }

        if (preg_match(pattern: '/^0o([0-7](?:_?[0-7])*)\z/', subject: $value, matches: $match) === 1) {
            return $this->toInteger(octdec(str_replace(search: '_', replace: '', subject: $match[1])), $value);
        }

        if (preg_match(pattern: '/^0b([01](?:_?[01])*)\z/', subject: $value, matches: $match) === 1) {
            return $this->toInteger(bindec(str_replace(search: '_', replace: '', subject: $match[1])), $value);
        }

        if (
            preg_match('/^[+-]?(?:0|[1-9](?:_?\d)*)(?:\.\d(?:_?\d)*)?(?:[eE][+-]?\d(?:_?\d)*)?\z/', $value) === 1
            && strpbrk($value, '.eE') !== false
        ) {
            return (float) str_replace(search: '_', replace: '', subject: $value);
        }

        throw LoaderException::invalidSyntax('invalid value (strings must be quoted)');
    }

    /**
     * @throws LoaderException When the number does not fit in a 64-bit integer
     */
    private function toInteger(int|float $number, string $source): int
    {
        if (!is_int($number)) {
            throw LoaderException::invalidSyntax('integer does not fit in 64 bits');
        }

        return $number;
    }

    /**
     * Decodes a complete quoted token: '...' literally, "..." with escapes.
     *
     * @throws LoaderException On control characters or invalid escapes
     */
    private function decodeString(string $token): string
    {
        $body = substr(string: $token, offset: 1, length: -1);

        if (preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $body) === 1) {
            throw LoaderException::invalidSyntax('control characters must be escaped in strings');
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
                default => throw LoaderException::invalidSyntax(sprintf("invalid escape sequence '\\%s'", $escape)),
            };

            $hex = substr(string: $body, offset: $i + 1, length: $width);

            if (preg_match('/^[0-9A-Fa-f]{' . $width . '}\z/', $hex) !== 1) {
                throw LoaderException::invalidSyntax(sprintf("invalid escape sequence '\\%s': expected %d hexadecimal digits", $escape, $width));
            }

            $result .= $this->encodeCodePoint((int) hexdec($hex));
            $i += $width;
        }

        return $result;
    }

    /**
     * @return list<string|int|float|bool|array<array-key, mixed>>
     *
     * @throws LoaderException When the array is malformed
     */
    private function parseArray(string $value, int $lineNumber): array
    {
        $this->assertClosesAtEnd($value);

        return array_map(
            fn(string $item): string|int|float|bool|array => $this->parseValue($item, $lineNumber),
            $this->splitItems(substr(string: $value, offset: 1, length: -1), trailingComma: true),
        );
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws LoaderException When the inline table is malformed or repeats a key
     */
    private function parseInlineTable(string $value, int $lineNumber): array
    {
        $this->assertClosesAtEnd($value);

        if (str_contains($value, "\n")) {
            throw LoaderException::invalidSyntax('inline tables must be on a single line');
        }

        $table = new TomlDocument();

        foreach ($this->splitItems(substr(string: $value, offset: 1, length: -1), trailingComma: false) as $entry) {
            $equals = strpos($this->maskStrings($entry), '=');

            if ($equals === false) {
                throw LoaderException::invalidSyntax("expected 'key = value' in an inline table");
            }

            $key = $this->parseKey(substr(string: $entry, offset: 0, length: $equals));
            $table->assign(table: [], key: $key, value: $this->parseValue(trim(substr($entry, $equals + 1)), $lineNumber));
        }

        return $table->root;
    }

    /**
     * Splits collection content on top-level commas. Empty items are
     * errors, except a single trailing comma where $trailingComma allows.
     *
     * @return list<string>
     *
     * @throws LoaderException On an empty item
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
                $pieces[] = trim(substr(string: $inner, offset: $start, length: $i - $start));
                $start = $i + 1;
            }
        }

        $pieces[] = trim(substr($inner, $start));

        if ($trailingComma && count($pieces) > 1 && end($pieces) === '') {
            array_pop($pieces);
        }

        if (in_array(needle: '', haystack: $pieces, strict: true)) {
            throw LoaderException::invalidSyntax("unexpected ',' (empty item)");
        }

        return $pieces;
    }

    /**
     * @throws LoaderException When a closing bracket does not match the
     *                         innermost open one, or text follows the
     *                         value's closing bracket
     */
    private function assertClosesAtEnd(string $value): void
    {
        $masked = $this->maskStrings($value);
        $expected = [];
        $last = strlen($masked) - 1;

        for ($i = 0; $i <= $last; ++$i) {
            $char = $masked[$i];

            if (isset(self::CLOSERS[$char])) {
                $expected[] = self::CLOSERS[$char];
            } elseif (in_array(needle: $char, haystack: self::CLOSERS, strict: true) && array_pop($expected) !== $char) {
                throw LoaderException::invalidSyntax(sprintf("mismatched closing bracket '%s'", $char));
            }

            if ($expected === [] && $i < $last) {
                throw LoaderException::invalidSyntax('unexpected text after a closing bracket');
            }
        }

        if ($expected !== []) {
            throw LoaderException::invalidSyntax('unbalanced brackets');
        }
    }

    // ── Utility ──────────────────────────────────────────────────────────

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
            $masked = substr_replace(string: $masked, replace: str_repeat(self::MASK, $last - $i + 1), offset: $i, length: $last - $i + 1);
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

        return $hash === false ? $line : substr(string: $line, offset: 0, length: $hash);
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
