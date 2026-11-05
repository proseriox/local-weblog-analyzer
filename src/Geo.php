<?php

declare(strict_types=1);

namespace LocalWeblogAnalyzer;

use MaxMind\Db\Reader;

final class Geo
{
    private static ?Reader $reader = null;
    private static bool $tried = false;
    /** @var array<string,string> */
    private static array $cache = [];

    public static function country(string $ip): string
    {
        if ($ip === '') {
            return '';
        }
        if (isset(self::$cache[$ip])) {
            return self::$cache[$ip];
        }
        if (!self::$tried) {
            self::$tried = true;
            $file = Db::dir() . '/geoip-country.mmdb';
            if (is_file($file)) {
                try {
                    self::$reader = new Reader($file);
                } catch (\Throwable) {
                    self::$reader = null;
                }
            }
        }
        $cc = '';
        if (self::$reader !== null && filter_var($ip, FILTER_VALIDATE_IP)) {
            try {
                $r = self::$reader->get($ip);
                $cc = is_array($r) ? (string) ($r['country_code'] ?? $r['country']['iso_code'] ?? '') : '';
            } catch (\Throwable) {
            }
        }
        return self::$cache[$ip] = strtoupper($cc);
    }
}
