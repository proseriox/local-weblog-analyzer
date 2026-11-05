<?php

declare(strict_types=1);

namespace LocalWeblogAnalyzer\Format;

/** Apache-Standard: [Thu Nov 05 00:05:11.123456 2026] [authz_core:error] [pid 1] [client 1.2.3.4:5] AH01630: ... */
final class Apache24ErrorFormat implements LineFormat
{
    private const REGEX = '/^\[[A-Za-z]{3} ([A-Za-z]{3}) (\d{1,2}) (\d{2}):(\d{2}):(\d{2})(?:\.\d+)? (\d{4})\] \[(?:([\w.]+):)?(\w+)\](?: \[pid [^\]]*\])?(?: \[client ([^\]]+)\])? ?(.*)$/s';

    public function label(): string
    {
        return 'Error-Log (Apache 2.x Standard)';
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
        $month = Util::month($m[1]);
        if ($month === null) {
            return null;
        }
        $s = Util::splitMessage($m[10], $m[7], false);
        return [
            'ts' => Util::ts((int) $m[6], $month, (int) $m[2], (int) $m[3], (int) $m[4], (int) $m[5]),
            'ip' => $m[9] !== '' ? (Util::ip($m[9]) ?? '') : '',
            'level' => Util::level($m[8]),
        ] + $s;
    }
}
