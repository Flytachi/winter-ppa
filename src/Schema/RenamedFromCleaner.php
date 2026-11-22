<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\Schema;

use PhpToken;

/**
 * Removes the #[RenamedFrom] a snapshot has already recorded — `call db snapshot clean`.
 *
 * It reads the file as PHP tokens and takes out only the attribute: the whole line when it
 * stands alone, the element and its comma when it shares a group (`#[Text, RenamedFrom('x')]`
 * → `#[Text]`), and the `use` of RenamedFrom once nothing in the file names it. Everything
 * else stays byte for byte.
 */
final class RenamedFromCleaner
{
    private function __construct()
    {
    }

    /**
     * @param list<string> $properties The properties whose #[RenamedFrom] goes; `@<Class>` for the
     *     one on a class (a table renamed).
     * @return array{source: string, removed: int}
     */
    public static function clean(string $source, array $properties): array
    {
        $tokens = PhpToken::tokenize($source);
        $count = count($tokens);
        $cuts = [];
        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i]->id !== T_ATTRIBUTE) {
                continue;
            }
            [$close, $elements] = self::group($tokens, $i);
            $property = self::nextProperty($tokens, $close);
            if ($property === null || !in_array($property, $properties, true)) {
                continue;
            }
            foreach ($elements as $e => [$from, $to]) {
                if (!self::isRenamedFrom($tokens, $from, $to)) {
                    continue;
                }
                $cuts[] = count($elements) === 1
                    ? self::wholeLine($source, $tokens[$i]->pos, $tokens[$close]->pos + 1)
                    : self::element($tokens, $elements, $e);
            }
        }
        usort($cuts, fn(array $a, array $b) => $b[0] <=> $a[0]);
        foreach ($cuts as [$a, $b]) {
            $source = substr($source, 0, $a) . substr($source, $b);
        }
        if ($cuts !== [] && !self::stillNamed($source)) {
            $source = (string) preg_replace('/^use\s+[^;]*\\\\RenamedFrom\s*;[ \t]*\R/m', '', $source);
        }
        return ['source' => $source, 'removed' => count($cuts)];
    }

    /** @return array{int, list<array{int, int}>} the index of the group's `]`, its elements as token ranges */
    private static function group(array $tokens, int $open): array
    {
        $depth = 1;
        $elements = [];
        $start = $open + 1;
        $count = count($tokens);
        for ($j = $open + 1; $j < $count; $j++) {
            $t = $tokens[$j]->text;
            if ($t === '[' || $t === '(' || $t === '{' || $tokens[$j]->id === T_ATTRIBUTE) {
                $depth++;
            } elseif ($t === ']' || $t === ')' || $t === '}') {
                $depth--;
                if ($depth === 0) {
                    $elements[] = [$start, $j];
                    return [$j, $elements];
                }
            } elseif ($t === ',' && $depth === 1) {
                $elements[] = [$start, $j];
                $start = $j + 1;
            }
        }
        return [$count - 1, $elements];
    }

    private static function nextProperty(array $tokens, int $from): ?string
    {
        $count = count($tokens);
        for ($k = $from + 1; $k < $count; $k++) {
            if ($tokens[$k]->id === T_VARIABLE) {
                return substr($tokens[$k]->text, 1);
            }
            if ($tokens[$k]->id === T_CLASS) {
                for ($n = $k + 1; $n < $count; $n++) {
                    if ($tokens[$n]->id === T_STRING) {
                        return '@' . $tokens[$n]->text;
                    }
                }
                return null;
            }
            if (in_array($tokens[$k]->text, ['(', '{', ';'], true) || $tokens[$k]->id === T_FUNCTION) {
                return null;                           // the attribute is on something else
            }
        }
        return null;
    }

    private static function isRenamedFrom(array $tokens, int $from, int $to): bool
    {
        for ($k = $from; $k < $to; $k++) {
            if (in_array($tokens[$k]->id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $name = $tokens[$k]->text;
                return $name === 'RenamedFrom' || str_ends_with($name, '\\RenamedFrom');
            }
        }
        return false;
    }

    /** @return array{int, int} */
    private static function wholeLine(string $src, int $start, int $end): array
    {
        $lineStart = strrpos(substr($src, 0, $start), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        $lineEnd = strpos($src, "\n", $end);
        $lineEnd = $lineEnd === false ? strlen($src) : $lineEnd;
        $before = substr($src, $lineStart, $start - $lineStart);
        $after = trim(substr($src, $end, $lineEnd - $end));
        if (trim($before) === '' && ($after === '' || str_starts_with($after, '//') || str_starts_with($after, '#'))) {
            return [$lineStart, min(strlen($src), $lineEnd + 1)];
        }
        return [$start, $end];                         // the attribute shares its line with code
    }

    /** @return array{int, int} */
    private static function element(array $tokens, array $elements, int $e): array
    {
        [$from, $to] = $elements[$e];
        if ($e === 0) {
            // `#[RenamedFrom('x'), Text]` → `#[Text]`: the element, its comma, the space after.
            $end = $tokens[$to]->pos + 1;
            $next = $elements[1][0];
            while ($next < count($tokens) && $tokens[$next]->id === T_WHITESPACE) {
                $end = $tokens[$next]->pos + strlen($tokens[$next]->text);
                $next++;
            }
            return [$tokens[$from]->pos, $end];
        }
        // `#[Text, RenamedFrom('x')]` → `#[Text]`: the comma before it and the element.
        $last = $tokens[$to - 1];
        return [$tokens[$from - 1]->pos, $last->pos + strlen($last->text)];
    }

    private static function stillNamed(string $source): bool
    {
        foreach (PhpToken::tokenize($source) as $t) {
            if ($t->id === T_STRING && $t->text === 'RenamedFrom') {
                return true;
            }
        }
        return false;
    }
}
