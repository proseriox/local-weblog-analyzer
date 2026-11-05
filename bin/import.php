<?php

declare(strict_types=1);

use LocalWeblogAnalyzer\Db;
use LocalWeblogAnalyzer\Importer;
use LocalWeblogAnalyzer\Merger;
use LocalWeblogAnalyzer\Queue;

require __DIR__ . '/../vendor/autoload.php';

$args = [];
$progressFile = null;
$deleteSource = false;
$append = false;
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--progress=')) {
        $progressFile = substr($a, 11);
    } elseif ($a === '--append') {
        $append = true;
    } elseif ($a === '--delete-source') {
        $deleteSource = true;
    } else {
        $args[] = $a;
    }
}

if (count($args) < 1) {
    fwrite(STDERR, "Nutzung: php bin/import.php <logdatei[.gz]> [name] [--progress=datei] [--delete-source] [--append]\n");
    exit(1);
}

$file = $args[0];
$name = Db::safeName($args[1] ?? pathinfo(preg_replace('/\.gz$/', '', $file) ?? $file, PATHINFO_FILENAME));
$out = static fn(string $s) => fwrite(STDOUT, $s . "\n");
$write = static function (array $data) use ($progressFile): void {
    if ($progressFile) {
        file_put_contents($progressFile . '.tmp', json_encode($data));
        rename($progressFile . '.tmp', $progressFile);
    }
};
$cb = static fn(string $phase, int $lines, float $pct, string $format = '') => $write(['phase' => $phase, 'lines' => $lines, 'pct' => $pct, 'format' => $format]);
$queued = static function () use ($write, $out, $name): void {
    $write(['phase' => 'queue', 'lines' => 0, 'pct' => 0]);
    $out('Warte auf laufenden Import für "' . $name . '" ...');
};

$out($append ? "Füge $file zu \"$name\" hinzu ..." : "Importiere $file als \"$name\" ...");

$err = null;
$r = null;
try {
    if ($append) {
        $merger = new Merger();
        Queue::join($name);
        $staged = null;

        // 1) Einlesen ohne Datensatz-Schloss, begrenzt parallel
        $slot = Queue::acquireSlot(static function () use ($write, $out): void {
            $write(['phase' => 'queue', 'lines' => 0, 'pct' => 0]);
            $out('Warte auf einen freien Einlese-Platz ...');
        });
        $write(['phase' => 'import', 'lines' => 0, 'pct' => 0]);
        try {
            $staged = $merger->stage($file, $name, $out, $cb);
        } catch (Throwable $e) {
            $err = $e;
        }
        flock($slot, LOCK_UN);
        fclose($slot);

        // 2) Zusammenfuehren unter dem Datensatz-Schloss
        $lock = fopen(Db::dir() . '/' . $name . '.lock', 'c');
        if ($lock !== false && !flock($lock, LOCK_EX | LOCK_NB)) {
            $queued();
            flock($lock, LOCK_EX);
        }
        if ($staged !== null) {
            try {
                $r = $merger->merge($staged, $name, $out, $cb);
            } catch (Throwable $e) {
                $err = $e;
                $merger->discard($staged);
            }
        }

        // 3) Nur der letzte wartende Anhang erledigt die Nacharbeit
        $others = Queue::leave($name);
        if ($others === 0 && is_file(Db::path($name))) {
            try {
                $merger->finalize($name, $out, $cb);
            } catch (Throwable $e) {
                $err ??= $e;
            }
        }
    } else {
        $lock = fopen(Db::dir() . '/' . $name . '.lock', 'c');
        if ($lock !== false && !flock($lock, LOCK_EX | LOCK_NB)) {
            $queued();
            flock($lock, LOCK_EX);
        }
        $write(['phase' => 'import', 'lines' => 0, 'pct' => 0]);
        $r = (new Importer())->run($file, $name, $out, $cb);
    }
} catch (Throwable $e) {
    $err ??= $e;
}

if ($err !== null || $r === null) {
    $msg = $err !== null ? $err->getMessage() : 'Import fehlgeschlagen';
    $write(['phase' => 'error', 'message' => $msg]);
    fwrite(STDERR, 'Fehler: ' . $msg . "\n");
    exit(1);
}
$write(['phase' => 'done', 'lines' => $r['ok'], 'bad' => $r['bad'], 'pct' => 1.0, 'seconds' => round($r['seconds'], 1), 'format' => $r['format'], 'kind' => $r['kind']] + ($append ? ['added' => $r['added'], 'dupes' => $r['dupes'], 'total' => $r['total']] : []));
if ($deleteSource && is_file($file)) {
    unlink($file);
}
$out(sprintf(
    "Fertig: %s Zeilen importiert, %s nicht parsebar, %.1f s. Datenbank: data/%s.sqlite",
    number_format($r['ok'], 0, ',', '.'),
    number_format($r['bad'], 0, ',', '.'),
    $r['seconds'],
    $name
));
