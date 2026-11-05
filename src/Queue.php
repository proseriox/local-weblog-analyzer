<?php

declare(strict_types=1);

namespace LocalWeblogAnalyzer;

/**
 * Meldet laufende Anhaenge pro Datensatz an (Prozess-IDs), damit nur der letzte die Nacharbeit macht,
 * und vergibt Einlese-Plaetze, damit nicht zu viele Dateien gleichzeitig geparst werden.
 */
final class Queue
{
    private static function file(string $name): string
    {
        return Db::dir() . '/' . Db::safeName($name) . '.queue';
    }

    private static function alive(int $pid): bool
    {
        return $pid > 0 && is_dir('/proc/' . $pid);
    }

    /** @param callable(list<int>):list<int> $change */
    private static function update(string $name, callable $change): int
    {
        $f = fopen(self::file($name), 'c+');
        if ($f === false) {
            return 0;
        }
        flock($f, LOCK_EX);
        $pids = array_values(array_filter(
            array_map('intval', preg_split('/\s+/', trim((string) stream_get_contents($f))) ?: []),
            static fn(int $p): bool => self::alive($p)
        ));
        $pids = $change($pids);
        ftruncate($f, 0);
        rewind($f);
        fwrite($f, implode("\n", $pids));
        flock($f, LOCK_UN);
        fclose($f);
        return count($pids);
    }

    public static function join(string $name): void
    {
        $me = (int) getmypid();
        self::update($name, static fn(array $p): array => array_values(array_unique([...$p, $me])));
    }

    /** Meldet diesen Prozess ab und liefert die Zahl der anderen noch aktiven. */
    public static function leave(string $name): int
    {
        $me = (int) getmypid();
        return self::update($name, static fn(array $p): array => array_values(array_filter($p, static fn(int $x): bool => $x !== $me)));
    }

    /** Zahl der aktiven Anhaenge (ohne etwas zu aendern). */
    public static function active(string $name): int
    {
        if (!is_file(self::file($name))) {
            return 0;
        }
        return self::update($name, static fn(array $p): array => $p);
    }

    private static function slotCount(): int
    {
        $env = getenv('LOG_STAGE_SLOTS');
        if ($env !== false && (int) $env > 0) {
            return (int) $env;
        }
        $info = @file_get_contents('/proc/cpuinfo');
        $cores = $info ? preg_match_all('/^processor\s*:/m', $info) : 1;
        return max(1, min(4, (int) $cores - 1));
    }

    /**
     * Wartet auf einen freien Einlese-Platz und liefert den gehaltenen Dateihandle (zum Freigeben fclose()).
     *
     * @param (callable():void)|null $onWait wird einmal aufgerufen, wenn gewartet werden muss
     * @return resource
     */
    public static function acquireSlot(?callable $onWait = null)
    {
        $n = self::slotCount();
        $waited = false;
        while (true) {
            for ($i = 0; $i < $n; $i++) {
                $h = fopen(Db::dir() . '/stage.slot.' . $i, 'c');
                if ($h !== false && flock($h, LOCK_EX | LOCK_NB)) {
                    return $h;
                }
                if ($h !== false) {
                    fclose($h);
                }
            }
            if (!$waited && $onWait !== null) {
                $onWait();
                $waited = true;
            }
            usleep(300000);
        }
    }
}
