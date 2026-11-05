<?php

declare(strict_types=1);

namespace LocalWeblogAnalyzer\Format;

final class Detector
{
    /** @param list<string> $lines Stichprobe vom Dateianfang */
    public static function detect(array $lines): LineFormat
    {
        $candidates = [new AccessFormat(), new ApacheErrorFormat(), new Apache24ErrorFormat(), new NginxErrorFormat()];
        $score = array_fill(0, count($candidates), 0);
        $n = 0;
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $n++;
            foreach ($candidates as $i => $c) {
                if ($c->parse($line) !== null) {
                    $score[$i]++;
                }
            }
        }
        if ($n > 0) {
            $best = array_keys($score, max($score))[0];
            if ($score[$best] / $n >= 0.6) {
                return $candidates[$best];
            }
        }
        return new GenericFormat();
    }
}
