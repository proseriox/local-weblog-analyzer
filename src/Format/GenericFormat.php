<?php

declare(strict_types=1);

namespace LocalWeblogAnalyzer\Format;

/**
 * Fallback fuer unbekannte Formate: sucht Zeitstempel, Level, IP und Fehlercode in jeder Zeile.
 * Zeilen ohne Zeitstempel (z.B. Stacktraces) erben den der vorigen Zeile.
 */
final class GenericFormat implements LineFormat
{
    private int $lastTs = 0;

    public function label(): string
    {
        return 'Unbekanntes Format (generisch)';
    }

    public function kind(): string
    {
        return 'error';
    }

    public function parse(string $line): ?array
    {
        $line = rtrim($line);
        if ($line === '') {
            return null;
        }
        $ts = $this->time($line);
        if ($ts !== null) {
            $this->lastTs = $ts;
        }
        $level = '';
        if (preg_match('/\b(debug|info|notice|warn(?:ing)?|error|crit(?:ical)?|alert|emerg|fatal|severe)\b/i', $line, $m)) {
            $level = Util::level($m[1]);
        }
        $ip = '';
        if (preg_match('/\b(\d{1,3}(?:\.\d{1,3}){3})\b/', $line, $m)) {
            $ip = $m[1];
        }
        $code = preg_match('/\b(AH\d{4,5})\b/', $line, $m) ? $m[1] : '';
        return [
            'ts' => $this->lastTs,
            'ip' => $ip,
            'level' => $level,
            'module' => '',
            'code' => $code,
            'message' => $line,
            'target' => '',
            'vhost' => '',
        ];
    }

    private function time(string $l): ?int
    {
        if (preg_match('/(\d{4})[-\/](\d{2})[-\/](\d{2})[T ](\d{2}):(\d{2}):(\d{2})/', $l, $m)) {
            return Util::ts((int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4], (int) $m[5], (int) $m[6]);
        }
        if (preg_match('/(\d{2})\/([A-Za-z]{3})\/(\d{4}):(\d{2}):(\d{2}):(\d{2})/', $l, $m) && ($mo = Util::month($m[2]))) {
            return Util::ts((int) $m[3], $mo, (int) $m[1], (int) $m[4], (int) $m[5], (int) $m[6]);
        }
        if (preg_match('/[A-Za-z]{3} ([A-Za-z]{3}) (\d{1,2}) (\d{2}):(\d{2}):(\d{2})(?:\.\d+)? (\d{4})/', $l, $m) && ($mo = Util::month($m[1]))) {
            return Util::ts((int) $m[6], $mo, (int) $m[2], (int) $m[3], (int) $m[4], (int) $m[5]);
        }
        if (preg_match('/\b([A-Za-z]{3}) {1,2}(\d{1,2}) (\d{2}):(\d{2}):(\d{2})\b/', $l, $m) && ($mo = Util::month($m[1]))) {
            return Util::ts((int) gmdate('Y'), $mo, (int) $m[2], (int) $m[3], (int) $m[4], (int) $m[5]);
        }
        return null;
    }
}
