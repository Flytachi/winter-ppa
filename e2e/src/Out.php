<?php

declare(strict_types=1);

namespace Flytachi\Winter\Ppa\E2e;

/** The report: a database, its variants, their stages, a line per check. */
final class Out
{
    private static ?bool $colour = null;

    public static function title(string $text): void
    {
        echo "\n" . self::c("1;97", $text) . "\n";
    }

    public static function block(string $text): void
    {
        echo '  ' . self::c('1;36', $text) . "\n";
    }

    public static function stage(int|string $stage): void
    {
        echo '    ' . self::c('36', is_int($stage) ? "stage {$stage}" : $stage) . "\n";
    }

    public static function ok(string $what, string $note, float $ms): void
    {
        $dots = str_repeat('.', max(2, 46 - mb_strlen($what)));
        echo sprintf("      %s %s %s  %s %s\n", $what, self::c('90', $dots), self::c('32', 'OK'), self::c('90', $note), self::c('90', sprintf('%d ms', $ms)));
    }

    public static function failed(string $what, \Throwable $e): void
    {
        $dots = str_repeat('.', max(2, 46 - mb_strlen($what)));
        echo sprintf("      %s %s %s\n", $what, self::c('90', $dots), self::c('1;31', 'FAILED'));
        $why = $e instanceof Failure ? $e->getMessage() : $e::class . ': ' . $e->getMessage();
        foreach (explode("\n", $why) as $line) {
            echo '        ' . self::c('31', $line) . "\n";
        }
        if (!$e instanceof Failure) {
            echo self::c('90', '        at ' . $e->getFile() . ':' . $e->getLine()) . "\n";
        }
    }

    public static function stopped(): void
    {
        echo '      ' . self::c('33', '… the rest of this variant stands on it: not run') . "\n";
    }

    public static function result(int $failures, float $seconds): void
    {
        echo "\n" . ($failures === 0
            ? self::c('1;32', 'OK')
            : self::c('1;31', "FAILED: {$failures}")) . self::c('90', sprintf('  %.1f s', $seconds)) . "\n\n";
    }

    private static function c(string $code, string $text): string
    {
        self::$colour ??= getenv('NO_COLOR') === false && (getenv('CI') !== false || (function_exists('posix_isatty') && posix_isatty(STDOUT)));
        return self::$colour ? "\033[{$code}m{$text}\033[0m" : $text;
    }
}
