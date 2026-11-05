<?php

declare(strict_types=1);

namespace LocalWeblogAnalyzer\Format;

/** Apache/nginx "combined" oder "common", optional mit Vhost am Zeilenende. */
final class AccessFormat implements LineFormat
{
    private const REGEX = '/^(\S+) \S+ \S+ \[(\d{2})\/([A-Za-z]{3})\/(\d{4}):(\d{2}):(\d{2}):(\d{2})[^\]]*\] "([^"]*)" (\d{3}) (\S+)(?: "([^"]*)" "([^"]*)")?(?: (\S+))?/';

    public function label(): string
    {
        return 'Access-Log (Apache/nginx)';
    }

    public function kind(): string
    {
        return 'access';
    }

    public function parse(string $line): ?array
    {
        if (!preg_match(self::REGEX, $line, $m)) {
            return null;
        }
        $month = Util::month($m[3]);
        if ($month === null) {
            return null;
        }
        $parts = explode(' ', $m[8], 3);
        if (count($parts) >= 2) {
            $method = $parts[0];
            $uri = $parts[1];
        } else {
            $method = '-';
            $uri = $m[8];
        }
        $q = strpos($uri, '?');

        $vhost = $m[13] ?? '';
        if ($vhost !== '' && !preg_match('/^[A-Za-z0-9._:-]*[A-Za-z][A-Za-z0-9._:-]*$/', $vhost)) {
            $vhost = '';
        }

        return [
            'ts' => Util::ts((int) $m[4], $month, (int) $m[2], (int) $m[5], (int) $m[6], (int) $m[7]),
            'ip' => $m[1],
            'method' => substr($method, 0, 16),
            'path' => $q === false ? $uri : substr($uri, 0, $q),
            'query' => $q === false ? '' : substr($uri, $q + 1),
            'status' => (int) $m[9],
            'bytes' => $m[10] === '-' ? 0 : (int) $m[10],
            'referer' => ($m[11] ?? '-') === '-' ? '' : $m[11],
            'ua' => $m[12] ?? '',
            'vhost' => strtolower($vhost),
        ];
    }
}
