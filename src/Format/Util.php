<?php

declare(strict_types=1);

namespace LocalWeblogAnalyzer\Format;

/** Gemeinsame Helfer fuer Zeit, IP und Fehlermeldungen. */
final class Util
{
    private const MONTHS = [
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
        'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
    ];

    public const LEVELS = 'debug|info|notice|warn|warning|error|crit|alert|emerg|trace[1-8]';

    private const FILE_EXT = 'php|txt|html?|css|js|json|xml|png|jpe?g|gif|ico|svg|map|woff2?|pdf|conf|htaccess|log|ini|inc|py|rb|cgi|pl|aspx?|zip|gz|sql';

    public static function month(string $m): ?int
    {
        return self::MONTHS[strtolower(substr($m, 0, 3))] ?? null;
    }

    /** Wandzeit des Logs, Zeitzonenoffset wird ignoriert. */
    public static function ts(int $y, int $mo, int $d, int $h, int $i, int $s): int
    {
        return gmmktime($h, $i, $s, $mo, $d, $y);
    }

    /** "1.2.3.4", "1.2.3.4:555", "[::1]:80", "::1" -> reine IP oder null */
    public static function ip(string $t): ?string
    {
        if (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3})(?::\d+)?$/', $t, $m)) {
            return $m[1];
        }
        if (preg_match('/^\[([0-9a-fA-F:.]+)\](?::\d+)?$/', $t, $m)) {
            return $m[1];
        }
        if (strlen($t) > 2 && substr_count($t, ':') >= 2 && preg_match('/^[0-9a-fA-F:]+$/', $t)) {
            return $t;
        }
        return null;
    }

    public static function level(string $l): string
    {
        $l = strtolower($l);
        return match (true) {
            $l === 'warning' => 'warn',
            $l === 'critical', $l === 'fatal', $l === 'severe' => 'crit',
            default => $l,
        };
    }

    /**
     * Zerlegt den Meldungsteil: "mod_x.c(12): AH01630: text: /pfad host.tld"
     *
     * @return array{module:string,code:string,message:string,target:string,vhost:string}
     */
    public static function splitMessage(string $rest, string $module = '', bool $vhost = true): array
    {
        $rest = trim($rest);
        if (preg_match('/^([A-Za-z_][\w.]*)\(\d+\): /', $rest, $m)) {
            if ($module === '') {
                $module = (string) preg_replace('/\.c$/', '', $m[1]);
            }
            $rest = substr($rest, strlen($m[0]));
        }
        $code = '';
        if (preg_match('/^(AH\d{4,5}): /', $rest, $m)) {
            $code = $m[1];
            $rest = substr($rest, strlen($m[0]));
        } elseif (preg_match('/\b(AH\d{4,5})\b/', $rest, $m)) {
            $code = $m[1];
        }
        $rest = (string) preg_replace('/,\s*referer: .*$/s', '', $rest);

        $host = '';
        if ($vhost
            && preg_match('/^(.*\S)\s+(\S+)$/s', $rest, $m)
            && preg_match('/^(?=[^\/]*[A-Za-z])[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)+$/', $m[2])
            && !preg_match('/\.(' . self::FILE_EXT . ')$/i', $m[2])
        ) {
            $host = strtolower($m[2]);
            $rest = $m[1];
        }
        $target = '';
        if (preg_match('/(?:^|\s)(\/\S*)$/', $rest, $m)) {
            $target = $m[1];
        }
        return ['module' => $module, 'code' => $code, 'message' => $rest, 'target' => $target, 'vhost' => $host];
    }
}
