<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Loader;

use KaririCode\Configurator\Contract\Loader;
use KaririCode\Configurator\Exception\ConfigCode;
use KaririCode\Configurator\Exception\LoaderException;

/**
 * Loads YAML files with a built-in parser for the subset of YAML 1.2
 * that configuration uses: block mappings and sequences (including
 * sequences of mappings and sequences at the parent key's indentation),
 * flow collections on one or several lines, plain, single- and
 * double-quoted scalars with the YAML escape set, block scalars (| and
 * >) with chomping and indentation indicators, multi-line plain scalars,
 * comments, and the core schema's scalar types.
 *
 * Parsing runs in two phases. The lexical phase rewrites block scalars
 * and multi-line flow collections as single lines before blank lines and
 * comments go, so their content is never mistaken for structure; the
 * structural phase builds mappings and sequences from indentation and
 * rejects any line it cannot place (CFG_2820). Anchors, aliases, merge
 * keys, type tags, tab indentation and quoted scalars across lines fail
 * with CFG_2822 instead of being misread (ADR-002).
 *
 * Preconditions: the file is UTF-8 text.
 *
 * Thread-safety: stateless; one instance serves every caller.
 *
 * Side effects: load() reads the file.
 *
 * Example:
 *
 *     $data = new YamlLoader()->load('/app/config/logging.yaml');
 *
 * References: YAML 1.2.2, ADR-002, ADR-011. ARFA 1.3 P4: one format per
 * loader, no dependency.
 *
 * @since 4.2.0
 */
final readonly class YamlLoader implements Loader
{
    use Utf8Encoding;

    /** Block scalar header: style, then chomping/indentation indicators in either order. */
    private const string BLOCK_HEADER = '/^([|>])(?:([+-])([1-9])?|([1-9])([+-])?)?(?:[ \t]+#.*)?\z/';

    /** Byte that replaces quoted spans during position-preserving scans. */
    private const string MASK = "\x00";

    /** @var array<string, string> The closing bracket each flow indicator expects */
    private const array FLOW_CLOSERS = ['[' => ']', '{' => '}'];

    /** @var array<array-key, string> Single-character escapes (YAML 1.2, section 5.7); key "0" is stored as int */
    private const array ESCAPES = [
        '0' => "\0",
        'a' => "\x07",
        'b' => "\x08",
        't' => "\t",
        "\t" => "\t",
        'n' => "\n",
        'v' => "\x0B",
        'f' => "\f",
        'r' => "\r",
        'e' => "\x1B",
        ' ' => ' ',
        '"' => '"',
        '/' => '/',
        '\\' => '\\',
        'N' => "\u{85}",
        '_' => "\u{A0}",
        'L' => "\u{2028}",
        'P' => "\u{2029}",
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
        return $extension === 'yaml' || $extension === 'yml';
    }

    // ── Lexical Phase ────────────────────────────────────────────────────

    /**
     * @return array<array-key, mixed>
     */
    private function parse(string $yaml): array
    {
        $lines = $this->normalizeLines($yaml);

        if ($lines === []) {
            return [];
        }

        return $this->parseBlock($lines, $this->indentLevel($lines[0]));
    }

    /**
     * Returns the structural lines of the document.
     *
     * Block scalars and multi-line flow collections are consumed from the
     * raw text first and rewritten as single-line equivalents; only then
     * are blank lines, full-line comments, directives, and document
     * markers dropped.
     *
     * @return list<string>
     *
     * @throws LoaderException    When an unsupported feature is found
     * @throws LoaderException When a flow collection is never closed
     */
    private function normalizeLines(string $yaml): array
    {
        if (str_starts_with($yaml, "\xEF\xBB\xBF")) {
            $yaml = substr($yaml, 3);
        }

        $raw = explode("\n", str_replace(search: ["\r\n", "\r"], replace: "\n", subject: $yaml));

        // A final newline terminates the last line; it does not open one.
        if (end($raw) === '') {
            array_pop($raw);
        }

        $lines = [];
        $count = count($raw);

        for ($index = 0; $index < $count; ++$index) {
            $line = rtrim($raw[$index]);

            if ($this->isIgnorable($line)) {
                continue;
            }

            $this->detectUnsupportedFeatures($line, $index + 1);

            $node = $this->splitNode($line);

            if (preg_match(pattern: self::BLOCK_HEADER, subject: $node['value'], matches: $header) === 1) {
                [$scalar, $index] = $this->consumeBlockScalar(raw: $raw, index: $index, parentColumn: $node['column'], header: $header);
                $lines[] = $node['prefix'] . $this->quote($scalar);

                continue;
            }

            if ($this->flowDepth($this->stripInlineComment($node['value'])) > 0) {
                [$line, $index] = $this->consumeFlowCollection(raw: $raw, index: $index, line: $line);
            }

            if ($this->hasUnterminatedQuote($line)) {
                throw LoaderException::unsupportedYamlFeature(
                    'quoted scalars spanning several lines are not supported — use a block scalar (| or >)',
                    $index + 1,
                );
            }

            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * Blank lines, full-line comments, directives (%YAML) and document
     * markers (--- and ...) carry no configuration.
     */
    private function isIgnorable(string $line): bool
    {
        $stripped = ltrim($line);

        return $stripped === ''
            || $stripped[0] === '#'
            || $line[0] === '%'
            || in_array(needle: $line, haystack: ['---', '...'], strict: true);
    }

    /**
     * Detects YAML features outside the configuration subset.
     *
     * @throws LoaderException
     */
    private function detectUnsupportedFeatures(string $line, int $lineNumber): void
    {
        $feature = $this->unsupportedFeature($line);

        if ($feature !== null) {
            throw LoaderException::unsupportedYamlFeature($feature, $lineNumber);
        }
    }

    /**
     * The message for the first feature outside the subset that the line
     * uses, or null.
     */
    private function unsupportedFeature(string $line): ?string
    {
        $stripped = ltrim($line);
        $indentation = substr(string: $line, offset: 0, length: strlen($line) - strlen($stripped));

        return match (true) {
            $this->hasAnchor($stripped) => 'YAML anchors (&name) are not supported',
            // Checked before aliases: a merge line always contains an alias.
            preg_match('/^<< ?:/', $stripped) === 1 => 'YAML merge keys (<<:) are not supported',
            $this->hasAlias($stripped) => 'YAML aliases (*name) are not supported',
            preg_match('/!!\w+/', $stripped) === 1 => 'YAML type tags (!!type) are not supported',
            str_contains($indentation, "\t") => 'Tab indentation is not allowed — use spaces only',
            default => null,
        };
    }

    /**
     * An anchor (&name) ends the line, follows "key:", or follows "- ".
     */
    private function hasAnchor(string $stripped): bool
    {
        return preg_match('/&\w+\s*\z/', $stripped) === 1
            || preg_match('/:\s+&\w+/', $stripped) === 1
            || preg_match('/^-\s+&\w+/', $stripped) === 1;
    }

    /**
     * An alias (*name) is the whole value of "key:" or "- ", on a line
     * without quotes (where '*' may be literal text).
     */
    private function hasAlias(string $stripped): bool
    {
        return !str_contains($stripped, '"')
            && !str_contains($stripped, "'")
            && (preg_match('/:\s+\*\w+/', $stripped) === 1 || preg_match('/^-\s+\*\w+/', $stripped) === 1);
    }

    /**
     * Splits a line into the text that introduces its value (indentation,
     * sequence dashes, "key:") and the value itself.
     *
     * @return array{prefix: string, value: string, column: int} where
     *         column is the position of the node that owns the value
     */
    private function splitNode(string $line): array
    {
        $position = $this->indentLevel($line);
        $column = $position;
        $length = strlen($line);

        while ($position < $length && $this->isSequenceItem(substr($line, $position))) {
            $column = $position;
            $position = $this->skipSpaces($line, $position + 1);
        }

        $rest = substr($line, $position);
        $colon = $this->findKeyColon($rest);

        if ($colon === false) {
            return ['prefix' => substr(string: $line, offset: 0, length: $position), 'value' => $rest, 'column' => $column];
        }

        $valueStart = $this->skipSpaces($line, $position + $colon + 1);

        return [
            'prefix' => rtrim(substr(string: $line, offset: 0, length: $valueStart)) . ' ',
            'value' => substr($line, $valueStart),
            'column' => $position,
        ];
    }

    /**
     * Consumes the lines of a block scalar from the raw text and returns
     * its value with folding and chomping applied (YAML 1.2, section 8.1).
     *
     * @param list<string>              $raw
     * @param array<array-key, string>  $header BLOCK_HEADER match groups
     *
     * @return array{0: string, 1: int} the value and the index of its last raw line
     */
    private function consumeBlockScalar(array $raw, int $index, int $parentColumn, array $header): array
    {
        [$style, $chomping, $indicator] = $this->blockHeader($header);
        [$content, $last] = $this->blockLines(
            raw: $raw,
            index: $index,
            parentColumn: $parentColumn,
            contentIndent: $indicator > 0 ? $parentColumn + $indicator : null,
        );

        $trailing = 0;

        while ($content !== [] && end($content) === '') {
            array_pop($content);
            ++$trailing;
        }

        $text = $style === '|' ? implode("\n", $content) : $this->foldLines($content);

        $value = match ($chomping) {
            '-' => $text,
            '+' => $text . ($content === [] ? '' : "\n") . str_repeat("\n", $trailing),
            default => $content === [] ? '' : $text . "\n",
        };

        return [$value, $last];
    }

    /**
     * Reads the style (| or >), the chomping indicator (-, + or none) and
     * the indentation indicator (0 when absent) of a block scalar header;
     * the two indicators may come in either order.
     *
     * @param array<array-key, string> $header BLOCK_HEADER match groups
     *
     * @return array{0: string, 1: string, 2: int}
     */
    private function blockHeader(array $header): array
    {
        $chomping = ($header[2] ?? '') !== '' ? $header[2] : ($header[5] ?? '');
        $indicator = (int) (($header[3] ?? '') !== '' ? $header[3] : ($header[4] ?? '0'));

        return [$header[1], $chomping, $indicator];
    }

    /**
     * Collects the content lines of a block scalar: every line indented
     * past the parent and at least as far as the first content line (or
     * the explicit indentation). A blank line keeps the spaces past the
     * content indentation, as YAML 1.2 does, and is otherwise empty.
     *
     * @param list<string> $raw
     *
     * @return array{0: list<string>, 1: int} the lines, without their indentation, and the index of the last one
     *
     * @throws LoaderException When a blank line before the first content
     *                         line is indented past it (YAML 1.2, 8.1.1.1)
     */
    private function blockLines(array $raw, int $index, int $parentColumn, ?int $contentIndent): array
    {
        $content = [];
        $last = $index;
        $count = count($raw);
        $leadingWidth = 0;

        for ($i = $index + 1; $i < $count; ++$i) {
            $line = $raw[$i];

            if (trim($line) === '') {
                $leadingWidth = $contentIndent === null ? max($leadingWidth, strlen($line)) : $leadingWidth;
                $content[] = $line;

                continue;
            }

            $lineIndent = $this->indentLevel($line);

            if ($lineIndent <= $parentColumn || $lineIndent < ($contentIndent ?? $lineIndent)) {
                break;
            }

            if ($contentIndent === null && $leadingWidth > $lineIndent) {
                throw LoaderException::invalidSyntax('a blank line before the first line of a block scalar is indented past it');
            }

            $contentIndent ??= $lineIndent;
            $content[] = substr($line, $contentIndent);
            $last = $i;
        }

        return [array_map(fn(string $line): string => $this->blankLineContent(line: $line, contentIndent: $contentIndent), $content), $last];
    }

    /**
     * The content of a block scalar line: a content line as it is, and a
     * blank line as the spaces past the content indentation (often none;
     * always none in a block without content lines).
     */
    private function blankLineContent(string $line, ?int $contentIndent): string
    {
        if (trim($line) !== '') {
            return $line;
        }

        return $contentIndent !== null && strlen($line) > $contentIndent ? substr($line, $contentIndent) : '';
    }

    /**
     * Folds block scalar lines: a single line break between two ordinary
     * lines becomes a space; empty lines and more-indented lines keep
     * their line breaks.
     *
     * @param list<string> $lines
     */
    private function foldLines(array $lines): string
    {
        $text = '';
        $breaks = 0;
        $first = true;
        $previousMoreIndented = false;

        foreach ($lines as $line) {
            if ($line === '') {
                ++$breaks;

                continue;
            }

            $moreIndented = $line[0] === ' ' || $line[0] === "\t";

            if ($first) {
                $text = str_repeat("\n", $breaks) . $line;
                $first = false;
            } elseif ($moreIndented || $previousMoreIndented) {
                $text .= str_repeat("\n", $breaks + 1) . $line;
            } else {
                $text .= ($breaks === 0 ? ' ' : str_repeat("\n", $breaks)) . $line;
            }

            $breaks = 0;
            $previousMoreIndented = $moreIndented;
        }

        return $text;
    }

    /**
     * Joins the continuation lines of a flow collection opened on
     * $raw[$index] until its brackets balance.
     *
     * @param list<string> $raw
     *
     * @return array{0: string, 1: int} the joined line and the index of its last raw line
     *
     * @throws LoaderException When the collection is never closed
     */
    private function consumeFlowCollection(array $raw, int $index, string $line): array
    {
        $joined = $this->stripInlineComment($line);
        $count = count($raw);
        $start = $index + 1;

        while ($this->flowDepth($joined) > 0) {
            if (++$index >= $count) {
                throw LoaderException::invalidSyntax(sprintf('unterminated flow collection starting on line %d', $start));
            }

            $next = trim($raw[$index]);

            if ($next !== '' && $next[0] !== '#') {
                $joined .= ' ' . $this->stripInlineComment($next);
            }
        }

        return [$joined, $index];
    }

    /**
     * Encodes a string as a double-quoted scalar; the inverse of
     * unescapeDoubleQuoted for every character it emits.
     */
    private function quote(string $value): string
    {
        return '"' . strtr($value, [
            '\\' => '\\\\',
            '"' => '\\"',
            "\n" => '\\n',
            "\r" => '\\r',
            "\t" => '\\t',
        ]) . '"';
    }

    // ── Structural Phase ─────────────────────────────────────────────────

    /**
     * Parses lines that form one complete block as a mapping or a
     * sequence, chosen by the first line.
     *
     * @param non-empty-list<string> $lines
     *
     * @return array<array-key, mixed>
     *
     * @throws LoaderException When a line is indented less than the block
     */
    private function parseBlock(array $lines, int $indent): array
    {
        foreach ($lines as $line) {
            if ($this->indentLevel($line) < $indent) {
                throw LoaderException::invalidSyntax(sprintf('bad indentation at %s', $this->describe($line)));
            }
        }

        return $this->isSequenceItem(ltrim($lines[0]))
            ? $this->parseSequence(lines: $lines, start: 0, end: count($lines), indent: $indent)
            : $this->parseMapping(lines: $lines, start: 0, end: count($lines), indent: $indent);
    }

    /**
     * @param list<string> $lines
     *
     * @return array<array-key, mixed>
     *
     * @throws LoaderException When a line is not a key at this level
     */
    private function parseMapping(array $lines, int $start, int $end, int $indent): array
    {
        $result = [];
        $index = $start;

        while ($index < $end) {
            $line = $lines[$index];
            $stripped = ltrim($line);
            $colon = $this->findKeyColon($stripped);

            if ($colon === false || $this->indentLevel($line) !== $indent || $this->isSequenceItem($stripped)) {
                throw LoaderException::invalidSyntax(sprintf("expected 'key: value' at indentation %d, got %s", $indent, $this->describe($stripped)));
            }

            $key = $this->parseKey(substr(string: $stripped, offset: 0, length: $colon));
            $value = $this->stripInlineComment(trim(substr($stripped, $colon + 1)));
            ++$index;

            if ($value === '') {
                [$result[$key], $index] = $this->parseNestedValue(lines: $lines, index: $index, end: $end, keyIndent: $indent);

                continue;
            }

            $continuationEnd = $this->findBlockEnd(lines: $lines, from: $index, end: $end, minIndent: $indent + 1);
            $result[$key] = $this->parseInlineValue($value, $this->slice(lines: $lines, from: $index, to: $continuationEnd));
            $index = $continuationEnd;
        }

        return $result;
    }

    /**
     * Parses the value of a "key:" written with nothing inline: a nested
     * block, a sequence written at the key's own indentation, or null.
     *
     * @param list<string> $lines
     *
     * @return array{0: array<array-key, mixed>|null, 1: int} the value and the index after it
     */
    private function parseNestedValue(array $lines, int $index, int $end, int $keyIndent): array
    {
        if ($index >= $end) {
            return [null, $index];
        }

        $childIndent = $this->indentLevel($lines[$index]);

        if ($childIndent > $keyIndent) {
            $blockEnd = $this->findBlockEnd(lines: $lines, from: $index, end: $end, minIndent: $keyIndent + 1);
            $block = $this->slice(lines: $lines, from: $index, to: $blockEnd);

            return [$block === [] ? null : $this->parseBlock($block, $childIndent), $blockEnd];
        }

        if ($childIndent === $keyIndent && $this->isSequenceItem(ltrim($lines[$index]))) {
            $blockEnd = $this->findSequenceEnd(lines: $lines, from: $index, end: $end, indent: $keyIndent);

            return [$this->parseSequence(lines: $lines, start: $index, end: $blockEnd, indent: $keyIndent), $blockEnd];
        }

        return [null, $index];
    }

    /**
     * @param list<string> $lines
     *
     * @return list<string|int|float|bool|array<array-key, mixed>|null>
     *
     * @throws LoaderException When a line is not an item at this level
     */
    private function parseSequence(array $lines, int $start, int $end, int $indent): array
    {
        $result = [];
        $index = $start;

        while ($index < $end) {
            $line = $lines[$index];

            if ($this->indentLevel($line) !== $indent || !$this->isSequenceItem(ltrim($line))) {
                throw LoaderException::invalidSyntax(sprintf("expected a sequence item '- ' at indentation %d, got %s", $indent, $this->describe($line)));
            }

            $itemEnd = $this->findBlockEnd(lines: $lines, from: $index + 1, end: $end, minIndent: $indent + 1);
            $result[] = $this->parseSequenceItem($line, $this->slice(lines: $lines, from: $index + 1, to: $itemEnd));
            $index = $itemEnd;
        }

        return $result;
    }

    /**
     * Parses one "- ..." item and its more-indented continuation lines.
     *
     * A mapping ("- key: value") or nested sequence ("- - x") that starts
     * on the dash line is re-anchored at its own column and parsed
     * together with its continuation.
     *
     * @param list<string> $continuation
     *
     * @return string|int|float|bool|array<array-key, mixed>|null
     */
    private function parseSequenceItem(string $line, array $continuation): string|int|float|bool|array|null
    {
        $contentColumn = $this->skipSpaces($line, $this->indentLevel($line) + 1);
        $content = substr($line, $contentColumn);

        if ($content === '') {
            return $continuation === []
                ? null
                : $this->parseBlock($continuation, $this->indentLevel($continuation[0]));
        }

        if ($this->isSequenceItem($content) || $this->findKeyColon($content) !== false) {
            return $this->parseBlock([str_repeat(' ', $contentColumn) . $content, ...$continuation], $contentColumn);
        }

        return $this->parseInlineValue($content, $continuation);
    }

    /**
     * @param list<string> $continuation more-indented lines after the value
     *
     * @return string|int|float|bool|array<array-key, mixed>|null
     *
     * @throws LoaderException When a flow or quoted value has continuation lines
     */
    private function parseInlineValue(string $value, array $continuation = []): string|int|float|bool|array|null
    {
        $value = $this->stripInlineComment($value);
        $first = $value[0] ?? '';

        if ($first === '[' || $first === '{' || $first === '"' || $first === "'") {
            if ($continuation !== []) {
                throw LoaderException::invalidSyntax(sprintf('%s cannot follow a flow collection or a quoted scalar', $this->describe($continuation[0])));
            }

            return match ($first) {
                '[' => $this->parseFlowSequence($value),
                '{' => $this->parseFlowMapping($value),
                default => $this->parseScalar($value),
            };
        }

        return $this->parseScalar($this->joinPlainScalar($value, $continuation));
    }

    /**
     * Joins a plain scalar with its continuation lines, one space apart.
     *
     * @param list<string> $continuation
     *
     * @throws LoaderException When a continuation line is a key or a sequence item
     */
    private function joinPlainScalar(string $value, array $continuation): string
    {
        $parts = [$value];

        foreach ($continuation as $line) {
            $part = $this->stripInlineComment(trim($line));

            if ($this->isSequenceItem($part) || $this->findKeyColon($part) !== false) {
                throw LoaderException::invalidSyntax(sprintf('%s cannot continue a multi-line plain scalar', $this->describe($part)));
            }

            $parts[] = $part;
        }

        return implode(' ', $parts);
    }

    // ── Scalar Parsing ───────────────────────────────────────────────────

    private function parseScalar(string $value): string|int|float|bool|null
    {
        $value = trim($value);

        if ($value === '' || $value === '~') {
            return null;
        }

        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            return $this->unquote($value);
        }

        $lower = strtolower($value);

        return match (true) {
            $lower === 'null' => null,
            in_array(needle: $lower, haystack: ['true', 'yes', 'on'], strict: true) => true,
            in_array(needle: $lower, haystack: ['false', 'no', 'off'], strict: true) => false,
            default => $this->parseNumber($value, $lower) ?? $value,
        };
    }

    /**
     * A YAML 1.2 number: binary, octal, hexadecimal, decimal, float or one
     * of the special floats; null when the plain scalar is not a number.
     */
    private function parseNumber(string $value, string $lower): int|float|null
    {
        // intval(.., 0) does not recognize 0b/0o prefixes, so each radix is
        // handled explicitly. YAML 1.2 treats a leading zero as decimal.
        if (preg_match(pattern: '/^(-?)0b([01]+)\z/i', subject: $value, matches: $matches) === 1) {
            $magnitude = intval($matches[2], 2);

            return $matches[1] === '-' ? -$magnitude : $magnitude;
        }

        if (preg_match(pattern: '/^(-?)0o([0-7]+)\z/i', subject: $value, matches: $matches) === 1) {
            $magnitude = intval($matches[2], 8);

            return $matches[1] === '-' ? -$magnitude : $magnitude;
        }

        if (preg_match('/^-?0x[0-9a-fA-F]+\z/', $value) === 1) {
            return intval($value, 16);
        }

        if (preg_match('/^-?[0-9]+\z/', $value) === 1) {
            return (int) $value;
        }

        if (preg_match('/^-?(?:\d+\.\d*|\.\d+)(?:[eE][+-]?\d+)?\z/', $value) === 1) {
            return (float) $value;
        }

        return match ($lower) {
            '.inf', '+.inf' => INF,
            '-.inf' => -INF,
            '.nan' => NAN,
            default => null,
        };
    }

    private function parseKey(string $key): string
    {
        return $this->unquote($key);
    }

    private function unquote(string $value): string
    {
        $value = trim($value);

        if (strlen($value) >= 2) {
            if (str_starts_with($value, '"') && str_ends_with($value, '"')) {
                return $this->unescapeDoubleQuoted(substr(string: $value, offset: 1, length: -1));
            }

            if (str_starts_with($value, "'") && str_ends_with($value, "'")) {
                return str_replace(search: "''", replace: "'", subject: substr(string: $value, offset: 1, length: -1));
            }
        }

        return $value;
    }

    /**
     * Decodes the escape sequences of a double-quoted scalar
     * (YAML 1.2, section 5.7), including \xHH, \uHHHH and \UHHHHHHHH.
     *
     * @throws LoaderException On an unknown or truncated escape
     */
    private function unescapeDoubleQuoted(string $value): string
    {
        $result = '';
        $length = strlen($value);

        for ($i = 0; $i < $length; ++$i) {
            if ($value[$i] !== '\\') {
                $result .= $value[$i];

                continue;
            }

            $escape = $value[++$i] ?? '';

            if (isset(self::ESCAPES[$escape])) {
                $result .= self::ESCAPES[$escape];

                continue;
            }

            $width = match ($escape) {
                'x' => 2,
                'u' => 4,
                'U' => 8,
                default => throw LoaderException::invalidSyntax(sprintf("invalid escape sequence '\\%s'", $escape)),
            };

            $hex = substr(string: $value, offset: $i + 1, length: $width);

            if (preg_match('/^[0-9a-fA-F]{' . $width . '}\z/', $hex) !== 1) {
                throw LoaderException::invalidSyntax(sprintf("invalid escape sequence '\\%s': expected %d hexadecimal digits", $escape, $width));
            }

            $result .= $this->encodeCodePoint((int) hexdec($hex));
            $i += $width;
        }

        return $result;
    }

    // ── Flow Collections ─────────────────────────────────────────────────

    /**
     * @return list<string|int|float|bool|array<array-key, mixed>|null>
     */
    private function parseFlowSequence(string $value): array
    {
        $this->assertFlowClosesAtEnd($value);

        return array_map(
            fn(string $item): string|int|float|bool|array|null => $this->parseInlineValue($item),
            $this->splitFlowItems(substr(string: $value, offset: 1, length: -1)),
        );
    }

    /**
     * @return array<array-key, mixed>
     */
    private function parseFlowMapping(string $value): array
    {
        $this->assertFlowClosesAtEnd($value);
        $result = [];

        foreach ($this->splitFlowItems(substr(string: $value, offset: 1, length: -1)) as $item) {
            $colon = $this->findKeyColon($item);

            if ($colon === false) {
                $result[$this->parseKey($item)] = null;

                continue;
            }

            $result[$this->parseKey(substr(string: $item, offset: 0, length: $colon))] = $this->parseInlineValue(trim(substr($item, $colon + 1)));
        }

        return $result;
    }

    /**
     * @throws LoaderException When a closing bracket does not match the
     *                         innermost open one, or text follows the
     *                         collection's closing bracket
     */
    private function assertFlowClosesAtEnd(string $value): void
    {
        $masked = $this->maskQuoted($value);
        $expected = [];
        $last = strlen($masked) - 1;

        for ($i = 0; $i <= $last; ++$i) {
            $char = $masked[$i];

            if (isset(self::FLOW_CLOSERS[$char])) {
                $expected[] = self::FLOW_CLOSERS[$char];
            } elseif (in_array(needle: $char, haystack: self::FLOW_CLOSERS, strict: true) && array_pop($expected) !== $char) {
                throw LoaderException::invalidSyntax(sprintf("mismatched closing bracket '%s' in a flow collection", $char));
            }

            if ($expected === [] && $i < $last) {
                throw LoaderException::invalidSyntax('unexpected text after a flow collection');
            }
        }

        if ($expected !== []) {
            throw LoaderException::invalidSyntax('unbalanced flow collection');
        }
    }

    /**
     * Splits flow collection content on top-level commas, ignoring commas
     * inside quotes and nested collections. Empty items are dropped.
     *
     * @return list<string>
     */
    private function splitFlowItems(string $input): array
    {
        $masked = $this->maskQuoted($input);
        $items = [];
        $depth = 0;
        $start = 0;
        $length = strlen($masked);

        for ($i = 0; $i <= $length; ++$i) {
            $char = $masked[$i] ?? ',';

            if ($char === '[' || $char === '{') {
                ++$depth;
            } elseif ($char === ']' || $char === '}') {
                --$depth;
            } elseif ($char === ',' && $depth === 0) {
                $item = trim(substr(string: $input, offset: $start, length: $i - $start));

                if ($item !== '') {
                    $items[] = $item;
                }

                $start = $i + 1;
            }
        }

        return $items;
    }

    // ── Utility ──────────────────────────────────────────────────────────

    /**
     * Names a line for an error message without repeating its value: the
     * key of a "key:" line, or the kind of line it is.
     */
    private function describe(string $line): string
    {
        $stripped = ltrim($line);
        $colon = $this->findKeyColon($stripped);

        return match (true) {
            $this->isSequenceItem($stripped) => 'a sequence item',
            $colon !== false => sprintf("the key '%s'", $this->parseKey(substr(string: $stripped, offset: 0, length: $colon))),
            default => 'a line that is neither a key nor a sequence item',
        };
    }

    /**
     * Replaces every quoted span (delimiters included) with MASK bytes,
     * preserving positions, so structural scans ignore quoted content.
     *
     * A quote opens a scalar only where YAML allows one to start: at the
     * beginning, after a flow indicator, or after ": ", "- ", "? ". An
     * apostrophe inside plain text (it's) is literal.
     */
    private function maskQuoted(string $text): string
    {
        $masked = $text;
        $length = strlen($text);
        $canOpen = true;

        for ($i = 0; $i < $length; ++$i) {
            $char = $text[$i];

            if (($char === '"' || $char === "'") && $canOpen) {
                $close = $this->closingQuote($text, $i);
                $last = $close === -1 ? $length - 1 : $close;
                $masked = substr_replace(string: $masked, replace: str_repeat(self::MASK, $last - $i + 1), offset: $i, length: $last - $i + 1);
                $i = $last;
                $canOpen = false;

                continue;
            }

            if ($char === ' ' || $char === "\t") {
                continue;
            }

            $canOpen = $this->quoteMayFollow($char, $text[$i + 1] ?? ' ');
        }

        return $masked;
    }

    /**
     * Whether a quote may open a scalar after $char: after a flow
     * indicator, or after ":", "-" or "?" that the next byte ($next)
     * separates from the value.
     */
    private function quoteMayFollow(string $char, string $next): bool
    {
        return in_array(needle: $char, haystack: ['[', '{', ','], strict: true)
            || (
                in_array(needle: $char, haystack: [':', '-', '?'], strict: true)
                && in_array(needle: $next, haystack: [' ', "\t", '"', "'"], strict: true)
            );
    }

    /**
     * Position of the quote that closes the scalar opened at $open, or -1.
     * Double quotes honour backslash escapes; single quotes use ''.
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

            if ($text[$i] !== $quote) {
                continue;
            }

            if ($quote === "'" && ($text[$i + 1] ?? '') === "'") {
                ++$i;

                continue;
            }

            return $i;
        }

        return -1;
    }

    private function hasUnterminatedQuote(string $line): bool
    {
        $masked = $this->maskQuoted($line);
        $length = strlen($line);

        for ($i = 0; $i < $length; ++$i) {
            $char = $line[$i];

            if (($char === '"' || $char === "'") && $masked[$i] === self::MASK && ($i === 0 || $masked[$i - 1] !== self::MASK)) {
                if ($this->closingQuote($line, $i) === -1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Finds the colon that separates a key from its value: outside quotes
     * and flow collections, and followed by whitespace or end of text (or
     * directly after a quoted key, JSON-style).
     */
    private function findKeyColon(string $text): int|false
    {
        $masked = $this->maskQuoted($text);
        $length = strlen($masked);
        $depth = 0;

        for ($i = 0; $i < $length; ++$i) {
            $char = $masked[$i];
            $depth += match ($char) {
                '[', '{' => 1,
                ']', '}' => -1,
                default => 0,
            };

            if ($char === '#' && $this->startsComment($masked, $i)) {
                return false;
            }

            if ($char === ':' && $depth === 0 && $this->separatesValue($masked, $i)) {
                return $i;
            }
        }

        return false;
    }

    /**
     * Whether the '#' at $position opens a comment: at the start of the
     * text or after whitespace.
     */
    private function startsComment(string $masked, int $position): bool
    {
        return $position === 0 || in_array(needle: $masked[$position - 1], haystack: [' ', "\t"], strict: true);
    }

    /**
     * Whether the ':' at $position separates a key from its value:
     * followed by whitespace or the end of the text, or directly after a
     * quoted key (JSON-style).
     */
    private function separatesValue(string $masked, int $position): bool
    {
        return in_array(needle: $masked[$position + 1] ?? ' ', haystack: [' ', "\t"], strict: true)
            || ($position > 0 && $masked[$position - 1] === self::MASK);
    }

    /**
     * Removes a trailing "# comment" — a '#' at the start of the text or
     * preceded by whitespace, outside quotes.
     */
    private function stripInlineComment(string $value): string
    {
        $masked = $this->maskQuoted($value);
        $length = strlen($masked);

        for ($i = 0; $i < $length; ++$i) {
            if ($masked[$i] === '#' && ($i === 0 || $masked[$i - 1] === ' ' || $masked[$i - 1] === "\t")) {
                return rtrim(substr(string: $value, offset: 0, length: $i));
            }
        }

        return $value;
    }

    /**
     * Net count of open flow brackets outside quotes.
     */
    private function flowDepth(string $text): int
    {
        $masked = $this->maskQuoted($text);

        return substr_count($masked, '[') + substr_count($masked, '{')
            - substr_count($masked, ']') - substr_count($masked, '}');
    }

    private function isSequenceItem(string $stripped): bool
    {
        return $stripped === '-' || str_starts_with($stripped, '- ') || str_starts_with($stripped, "-\t");
    }

    private function indentLevel(string $line): int
    {
        return strlen($line) - strlen(ltrim($line, ' '));
    }

    private function skipSpaces(string $text, int $from): int
    {
        $length = strlen($text);

        while ($from < $length && ($text[$from] === ' ' || $text[$from] === "\t")) {
            ++$from;
        }

        return $from;
    }

    /**
     * Index of the first line in [$from, $end) indented less than $minIndent.
     *
     * @param list<string> $lines
     */
    private function findBlockEnd(array $lines, int $from, int $end, int $minIndent): int
    {
        for ($i = $from; $i < $end; ++$i) {
            if ($this->indentLevel($lines[$i]) < $minIndent) {
                return $i;
            }
        }

        return $end;
    }

    /**
     * End of a sequence written at its key's indentation: the first line
     * that is less indented, or equally indented but not an item.
     *
     * @param list<string> $lines
     */
    private function findSequenceEnd(array $lines, int $from, int $end, int $indent): int
    {
        for ($i = $from; $i < $end; ++$i) {
            $lineIndent = $this->indentLevel($lines[$i]);

            if ($lineIndent < $indent || ($lineIndent === $indent && !$this->isSequenceItem(ltrim($lines[$i])))) {
                return $i;
            }
        }

        return $end;
    }

    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private function slice(array $lines, int $from, int $to): array
    {
        return array_slice(array: $lines, offset: $from, length: $to - $from);
    }
}
