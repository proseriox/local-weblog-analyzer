<?php

declare(strict_types=1);

namespace LocalWeblogAnalyzer\Format;

/** "47.128.20.0:52782 error Thu Nov 05 00:05:11 2026 mod_authz_core.c(879): AH01630: ... host.tld" */
final class ApacheErrorFormat implements LineFormat
{
    private const REGEX = '/^(\S+) (' . Util::LEVELS . ') [A-Za-z]{3} ([A-Za-z]{3}) (\d{1,2}) (\d{2}):(\d{2}):(\d{2})(?:\.\d+)? (\d{4}) (.*)$/is';

    public function label(): string
    {
        return 'Error-Log (Apache, IP-Level-Zeit)';
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
        $ip = Util::ip($m[1]);
        $month = Util::month($m[3]);
        if ($ip === null || $month === null) {
            return null;
        }
        $s = Util::splitMessage($m[9]);
        return ['ts' => Util::ts((int) $m[8], $month, (int) $m[4], (int) $m[5], (int) $m[6], (int) $m[7]), 'ip' => $ip, 'level' => Util::level($m[2])] + $s;
    }
}
