<?php

declare(strict_types=1);

namespace LocalWeblogAnalyzer;

use PDO;
use RuntimeException;

/** Haengt weitere Logdateien an einen bestehenden Datensatz an (ohne Duplikate). */
final class Merger
{
    private const SET_CAP = 300;
    private const KNOWN_UA_CAP = 300000;

    private static function connect(string $path): PDO
    {
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA busy_timeout=60000');
        return $pdo;
    }

    /** @return list<string> */
    private static function sqliteFiles(string $base): array
    {
        return [$base, $base . '-wal', $base . '-shm', $base . '-journal'];
    }

    /**
     * Schritt 1 (ohne Datensatz-Schloss, darf parallel laufen): Datei parsen und in eine eigene
     * Zwischen-Datenbank schreiben. Bereits bekannte User-Agents des Ziels werden wiederverwendet.
     *
     * @param callable(string):void $log
     * @param (callable(string,int,float,string):void)|null $progress
     * @return array{r:array<string,mixed>,tmp:string,file:string,kind:string}
     */
    public function stage(string $file, string $name, callable $log, ?callable $progress = null): array
    {
        $target = Db::path($name);
        if (!is_file($target)) {
            throw new RuntimeException('Datensatz nicht gefunden: ' . $name);
        }
        $old = Db::open($name);
        $oldKind = (string) $old->query("SELECT v FROM meta WHERE k='kind'")->fetchColumn();
        $oldKind = $oldKind === 'error' ? 'error' : 'access';
        $known = [];
        if ($oldKind === 'access') {
            $st = $old->query('SELECT ua, browser, os, device, is_bot FROM user_agents LIMIT ' . self::KNOWN_UA_CAP);
            foreach ($st as $row) {
                $known[$row['ua']] = [$row['browser'], $row['os'], $row['device'], (int) $row['is_bot']];
            }
        }
        unset($old, $st);

        $stageName = Db::safeName($name . '__neu' . getmypid());
        $importer = new Importer();
        $importer->setKnownAgents($known);
        unset($known);
        try {
            $r = $importer->run($file, $stageName, $log, $progress, true);
        } catch (\Throwable $e) {
            self::cleanup(Db::tmpPath($stageName));
            throw $e;
        }
        if ($r['kind'] !== $oldKind) {
            self::cleanup(Db::tmpPath($stageName));
            throw new RuntimeException(sprintf(
                'Die Datei ist ein %s-Log, der Datensatz ein %s-Log. Nur gleiche Arten lassen sich zusammenführen.',
                $r['kind'] === 'error' ? 'Error' : 'Access',
                $oldKind === 'error' ? 'Error' : 'Access'
            ));
        }
        return ['r' => $r, 'tmp' => Db::tmpPath($stageName), 'file' => $file, 'kind' => $oldKind];
    }

    private static function cleanup(string $tmp): void
    {
        foreach (self::sqliteFiles($tmp) as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
    }

    /**
     * Schritt 2 (unter dem Datensatz-Schloss): die Zwischen-Datenbank in den Datensatz einfuegen,
     * ohne Kopie und ohne Neusortierung (macht finalize() einmal am Ende).
     *
     * @param array{r:array<string,mixed>,tmp:string,file:string,kind:string} $staged
     * @param callable(string):void $log
     * @param (callable(string,int,float,string):void)|null $progress
     * @return array{lines:int,ok:int,bad:int,seconds:float,kind:string,format:string,added:int,dupes:int,total:int}
     */
    public function merge(array $staged, string $name, callable $log, ?callable $progress = null): array
    {
        $start = microtime(true);
        $r = $staged['r'];
        $newTmp = $staged['tmp'];
        $kind = $staged['kind'];
        $target = Db::path($name);
        $pdo = null;
        try {
            if (!is_file($target)) {
                throw new RuntimeException('Datensatz nicht gefunden: ' . $name);
            }
            $log('Zusammenführen mit "' . $name . '" ...');
            if ($progress) {
                $progress('merge', (int) $r['ok'], 1.0, (string) $r['format']);
            }

            $pdo = self::connect($target);
            $pdo->exec('PRAGMA synchronous=NORMAL; PRAGMA cache_size=-400000; PRAGMA temp_store=MEMORY;');
            $pdo->exec('ATTACH DATABASE ' . $pdo->quote($newTmp) . ' AS nw');

            $table = $kind === 'error' ? 'events' : 'requests';
            $pdo->beginTransaction();
            $oldMeta = $pdo->query('SELECT k, v FROM meta')->fetchAll(PDO::FETCH_KEY_PAIR);
            $before = isset($oldMeta['lines']) ? (int) $oldMeta['lines'] : (int) $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn();
            $oldRange = $pdo->query("SELECT MIN(ts) AS a, MAX(ts) AS b FROM main.$table")->fetch();
            $newRange = $pdo->query("SELECT MIN(ts) AS a, MAX(ts) AS b FROM nw.$table")->fetch();
            $oldMin = $oldRange['a'] !== null ? (int) $oldRange['a'] : null;
            $oldMax = $oldRange['b'] !== null ? (int) $oldRange['b'] : null;
            $newMin = $newRange['a'] !== null ? (int) $newRange['a'] : null;
            $newMax = $newRange['b'] !== null ? (int) $newRange['b'] : null;
            $disjoint = $before === 0 || $oldMin === null || $newMin === null || $newMin > $oldMax || $newMax < $oldMin;

            foreach (Db::droppableIndexes($kind) as $ix) {
                $pdo->exec("DROP INDEX IF EXISTS $ix");
            }

            $days = $newMin === null ? [] : array_map('intval', $pdo->query("SELECT DISTINCT ts/86400*86400 FROM nw.$table")->fetchAll(PDO::FETCH_COLUMN));

            if ($kind === 'error') {
                $cols = 'ts,ip,level,module,code,message,msg_key,target,vhost';
                if ($disjoint) {
                    $added = (int) $pdo->exec("INSERT INTO events($cols) SELECT $cols FROM nw.events ORDER BY ts, id");
                } else {
                    $key = 'ts,ip,level,module,code,message,target,vhost';
                    $match = 'm.ts=x.ts AND m.ip=x.ip AND m.level=x.level AND m.module=x.module AND m.code=x.code AND m.message=x.message AND m.target=x.target AND m.vhost=x.vhost';
                    $added = (int) $pdo->exec(
                        "INSERT INTO events($cols)
                         SELECT $cols FROM (
                            SELECT n.*, ROW_NUMBER() OVER (PARTITION BY $key ORDER BY n.id) AS rn FROM nw.events n
                         ) x
                         WHERE x.rn > (SELECT COUNT(*) FROM main.events m WHERE $match)
                         ORDER BY x.ts, x.rn"
                    );
                }
            } else {
                $pdo->exec('INSERT OR IGNORE INTO user_agents(ua,browser,os,device,is_bot) SELECT ua,browser,os,device,is_bot FROM nw.user_agents');
                $cols = 'ts,ip,method,path,query,status,bytes,referer,ua_id,vhost';
                if ($disjoint) {
                    $added = (int) $pdo->exec(
                        "INSERT INTO requests($cols)
                         SELECT n.ts,n.ip,n.method,n.path,n.query,n.status,n.bytes,n.referer,mu.id,n.vhost
                         FROM nw.requests n
                         JOIN nw.user_agents nu ON nu.id = n.ua_id
                         JOIN main.user_agents mu ON mu.ua = nu.ua
                         ORDER BY n.ts, n.id"
                    );
                } else {
                    $key = 'n.ts,n.ip,n.method,n.path,n.query,n.status,n.bytes,n.referer,mu.id,n.vhost';
                    $match = 'm.ts=x.ts AND m.ip=x.ip AND m.method=x.method AND m.path=x.path AND m.query=x.query AND m.status=x.status AND m.bytes=x.bytes AND m.referer=x.referer AND m.ua_id=x.ua_id AND m.vhost=x.vhost';
                    $added = (int) $pdo->exec(
                        "INSERT INTO requests($cols)
                         SELECT $cols FROM (
                            SELECT n.ts,n.ip,n.method,n.path,n.query,n.status,n.bytes,n.referer,mu.id AS ua_id,n.vhost,
                                   ROW_NUMBER() OVER (PARTITION BY $key ORDER BY n.id) AS rn
                            FROM nw.requests n
                            JOIN nw.user_agents nu ON nu.id = n.ua_id
                            JOIN main.user_agents mu ON mu.ua = nu.ua
                         ) x
                         WHERE x.rn > (SELECT COUNT(*) FROM main.requests m WHERE $match)
                         ORDER BY x.ts, x.rn"
                    );
                }
            }

            $total = $before + $added;
            $dupes = (int) $r['ok'] - $added;
            $log(sprintf('  %d neu, %d Duplikate übersprungen%s', $added, $dupes, $disjoint ? ' (Zeiträume überschneiden sich nicht)' : ''));

            $newMeta = $pdo->query('SELECT k, v FROM nw.meta')->fetchAll(PDO::FETCH_KEY_PAIR);
            $merge = static function (string $k) use ($oldMeta, $newMeta): string {
                $a = json_decode($oldMeta[$k] ?? '[]', true) ?: [];
                $b = json_decode($newMeta[$k] ?? '[]', true) ?: [];
                return json_encode(array_slice(array_values(array_unique([...$a, ...$b])), 0, self::SET_CAP), JSON_UNESCAPED_UNICODE);
            };
            $files = array_values(array_unique(array_filter([...explode(', ', $oldMeta['file'] ?? ''), basename($staged['file'])])));
            $dirtyDays = json_decode($oldMeta['dirty_days'] ?? '[]', true);
            $dirtyDays = is_array($dirtyDays) ? array_values(array_unique([...$dirtyDays, ...$days])) : 'all';
            if (is_array($dirtyDays) && count($dirtyDays) > 4000) {
                $dirtyDays = 'all';
            }
            $meta = $pdo->prepare('REPLACE INTO meta VALUES(?,?)');
            foreach ([
                'lines' => (string) $total,
                'file' => implode(', ', $files),
                'imported_at' => (string) time(),
                'bad' => (string) ((int) ($oldMeta['bad'] ?? 0) + (int) $r['bad']),
                'vhosts' => $merge('vhosts'),
                'levels' => $merge('levels'),
                'modules' => $merge('modules'),
                'order_dirty' => '0',
                'idx_dropped' => '1',
                'dirty_days' => json_encode($dirtyDays),
            ] as $k => $v) {
                $meta->execute([$k, $v]);
            }
            $pdo->commit();
            unset($meta);
            $pdo->exec('DETACH DATABASE nw');
            $pdo = null;
        } catch (\Throwable $e) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        } finally {
            $pdo = null;
            self::cleanup($newTmp);
        }

        return $r + ['added' => $added, 'dupes' => $dupes, 'total' => $total, 'seconds' => (float) $r['seconds'] + (microtime(true) - $start)];
    }

    /** Wirft die Zwischen-Datenbank weg, wenn schon das Einlesen oder die Pruefung scheiterte. */
    public function discard(array $staged): void
    {
        self::cleanup($staged['tmp']);
    }

    /**
     * Einmal nach dem letzten angehaengten Import: Indizes wieder anlegen, bei Bedarf zeitlich neu
     * sortieren (in eine neue Datei, die Groesse bleibt dadurch 1x), Zusammenfassungen und Statistiken
     * erneuern.
     *
     * @param callable(string):void $log
     * @param (callable(string,int,float,string):void)|null $progress
     */
    public function finalize(string $name, callable $log, ?callable $progress = null): void
    {
        $target = Db::path($name);
        $pdo = self::connect($target);
        $meta = $pdo->query('SELECT k, v FROM meta')->fetchAll(PDO::FETCH_KEY_PAIR);
        $kind = ($meta['kind'] ?? 'access') === 'error' ? 'error' : 'access';
        $table = $kind === 'error' ? 'events' : 'requests';
        $lines = (int) ($meta['lines'] ?? 0);
        $format = (string) ($meta['format'] ?? '');
        $dirtyDays = json_decode($meta['dirty_days'] ?? '[]', true);
        if (($meta['idx_dropped'] ?? '0') !== '1' && $dirtyDays === []) {
            return;
        }
        if ($progress) {
            $progress('index', $lines, 1.0, $format);
        }
        $days = is_array($dirtyDays) ? array_map('intval', $dirtyDays) : null;
        $hasDayTable = (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name='rollup_day'")->fetchColumn() > 0;
        if (!$hasDayTable) {
            $days = null;
        }

        $pdo->exec('PRAGMA synchronous=OFF; PRAGMA cache_size=-400000; PRAGMA temp_store=MEMORY;');
        Db::ensureIndexes($pdo, $kind);
        $pdo->beginTransaction();
        Db::buildRollup($pdo, $kind);
        Db::buildDayRollup($pdo, $kind, $days);
        $pdo->exec("REPLACE INTO meta VALUES('dirty_days','[]')");
        $pdo->commit();
        $pdo->exec('ANALYZE');
    }
}
