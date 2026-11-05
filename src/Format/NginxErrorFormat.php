<?php

declare(strict_types=1);

namespace LocalWeblogAnalyzer\Format;

/** 2026/11/05 00:05:11 [error] 123#0: *1 msg, client: 1.2.3.4, server: host, request: "GET /x HTTP/1.1", host: "h" */
final class NginxErrorFormat implements LineFormat
{
    private const REGEX = '/^(\d{4})\/(\d{2})\/(\d{2}) (\d{2}):(\d{2}):(\d{2}) \[(\w+)\] \d+#\d+: (?:\*\d+ )?(.*)$/s';

    public function label(): string
    {
        return 'Error-Log (nginx)';
    }

    public function kind(): string
    {
        return 'error';
    }

    public function parse(string $line): ?array
    {
        if (!preg_match(self::REGEX, rtrim($line), $m)) {
            return null;
        }
        $rest = $m[8];
        $ip = '';
        $vhost = '';
        $target = '';
        if (preg_match('/, client: ([^,]+)/', $rest, $c)) {
            $ip = Util::ip(trim($c[1])) ?? '';
        }
        if (preg_match('/, server: ([^,]+)/', $rest, $c)) {
            $vhost = strtolower(trim($c[1]));
        }
        if (preg_match('/, request: "\S+ (\S+)/', $rest, $c)) {
            $target = $c[1];
        }
        if ($vhost === '' && preg_match('/, host: "([^"]+)"/', $rest, $c)) {
            $vhost = strtolower($c[1]);
        }
        $message = preg_replace('/, (?:client|server|request|upstream|host|referrer): .*$/s', '', $rest) ?? $rest;
        return [
            'ts' => Util::ts((int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4], (int) $m[5], (int) $m[6]),
            'ip' => $ip,
            'level' => Util::level($m[7]),
            'module' => '',
            'code' => '',
            'message' => trim($message),
            'target' => $target,
            'vhost' => $vhost,
        ];
    }
}
