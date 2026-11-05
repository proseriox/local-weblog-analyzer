<?php

declare(strict_types=1);

namespace LocalWeblogAnalyzer;

use PDO;
use RuntimeException;

final class Db
{
    public static function dir(): string
    {
        return dirname(__DIR__) . '/data';
    }

    public static function safeName(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?? '';
        return trim($name, '._') ?: 'log';
    }

    public static function path(string $name): string
    {
        return self::dir() . '/' . self::safeName($name) . '.sqlite';
    }

    public static function tmpPath(string $name): string
    {
        return self::path($name) . '.tmp';
    }

    public static function finalize(string $name): void
    {
        $tmp = self::tmpPath($name);
        $path = self::path($name);
        foreach (['-wal', '-shm', '-journal'] as $s) {
            if (is_file($path . $s)) {
                unlink($path . $s);
            }
        }
        rename($tmp, $path);
    }

    /** Anzahl Zeilen laut meta (schnell); faellt auf COUNT(*) zurueck. */
    private static function lineCount(PDO $pdo, string $table): string
    {
        $v = $pdo->query("SELECT v FROM meta WHERE k='lines'")->fetchColumn();
        return $v !== false && $v !== '' ? (string) $v : (string) $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn();
    }

    /** Stundenweise Zusammenfassung (fuer KPI/Zeitverlauf ohne Filter) neu aufbauen. */
    public static function buildRollup(PDO $pdo, string $kind): void
    {
        $table = $kind === 'error' ? 'events' : 'requests';
        $pdo->exec('DROP TABLE IF EXISTS rollup_hour');
        $pdo->exec('CREATE TABLE rollup_hour (h INTEGER PRIMARY KEY, total INTEGER NOT NULL, errors INTEGER NOT NULL, errors5 INTEGER NOT NULL, bytes INTEGER NOT NULL)');
        if ($kind === 'error') {
            $pdo->exec("INSERT INTO rollup_hour SELECT ts/3600*3600, COUNT(*), SUM(level IN ('error','crit','alert','emerg')), 0, 0 FROM events GROUP BY ts/3600");
        } else {
            $pdo->exec('INSERT INTO rollup_hour SELECT ts/3600*3600, COUNT(*), SUM(status >= 400), SUM(status >= 500), SUM(bytes) FROM requests GROUP BY ts/3600');
        }
        $count = (string) $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn();
        $ips = (string) $pdo->query("SELECT COUNT(DISTINCT ip) FROM $table")->fetchColumn();
        $msgs = $kind === 'error' ? (string) $pdo->query('SELECT COUNT(DISTINCT msg_key) FROM events')->fetchColumn() : '0';
        $m = $pdo->prepare('REPLACE INTO meta VALUES(?,?)');
        foreach (['rollup' => $count, 'rollup_ips' => $ips, 'rollup_msgs' => $msgs] as $k => $v) {
            $m->execute([$k, $v]);
        }
    }

    /** Baut die Zusammenfassungen bei Bedarf (aeltere/geaenderte Datensaetze) und repariert Indizes; nie waehrend eines Imports. */
    public static function ensureRollup(string $name): void
    {
        $path = self::path($name);
        if (!is_file($path)) {
            throw new RuntimeException('Datensatz nicht gefunden: ' . $name);
        }
        if (Queue::active($name) > 0) {
            return;
        }
        $lock = fopen($path . '.rollup.lock', 'c');
        $dl = fopen(self::dir() . '/' . self::safeName($name) . '.lock', 'c');
        if ($lock === false || $dl === false) {
            return;
        }
        flock($lock, LOCK_EX);
        try {
            if (!flock($dl, LOCK_EX | LOCK_NB)) {
                return;
            }
            $pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $pdo->exec('PRAGMA busy_timeout=60000');
            $meta = $pdo->query('SELECT k, v FROM meta')->fetchAll(PDO::FETCH_KEY_PAIR);
            $kind = ($meta['kind'] ?? 'access') === 'error' ? 'error' : 'access';
            $table = $kind === 'error' ? 'events' : 'requests';
            if (($meta['idx_dropped'] ?? '0') === '1') {
                self::ensureIndexes($pdo, $kind);
            }
            $lines = self::lineCount($pdo, $table);
            $hasTable = (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name='rollup_hour'")->fetchColumn() > 0;
            if (!$hasTable || ($meta['rollup'] ?? null) !== $lines) {
                $pdo->exec('PRAGMA synchronous=OFF; PRAGMA temp_store=MEMORY');
                $pdo->beginTransaction();
                self::buildRollup($pdo, $kind);
                $cnt = $pdo->query("SELECT v FROM meta WHERE k='rollup'")->fetchColumn();
                $pdo->exec("REPLACE INTO meta VALUES('lines','" . $cnt . "')");
                self::buildDayRollup($pdo, $kind);
                $pdo->commit();
            } elseif (($meta['rday'] ?? null) !== $lines) {
                $pdo->exec('PRAGMA synchronous=OFF; PRAGMA temp_store=MEMORY');
                $pdo->beginTransaction();
                self::buildDayRollup($pdo, $kind);
                $pdo->commit();
            }
        } finally {
            flock($dl, LOCK_UN);
            fclose($dl);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Legt bei Bedarf die Tabelle ip_geo (IP -> Land) an; einmal pro Datensatz. */
    public static function ensureGeo(string $name): void
    {
        $path = self::path($name);
        if (!is_file($path)) {
            throw new RuntimeException('Datensatz nicht gefunden: ' . $name);
        }
        $lock = fopen($path . '.geo.lock', 'c');
        if ($lock === false) {
            return;
        }
        flock($lock, LOCK_EX);
        try {
            $pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $pdo->exec('PRAGMA busy_timeout=60000');
            $kind = $pdo->query("SELECT v FROM meta WHERE k='kind'")->fetchColumn();
            $table = $kind === 'error' ? 'events' : 'requests';
            $total = self::lineCount($pdo, $table);
            $done = $pdo->query("SELECT v FROM meta WHERE k='geo'")->fetchColumn();
            if ($done !== $total) {
                $pdo->exec('PRAGMA synchronous=OFF; PRAGMA temp_store=MEMORY');
                $pdo->beginTransaction();
                $pdo->exec('DROP TABLE IF EXISTS ip_geo');
                $pdo->exec('CREATE TABLE ip_geo (ip TEXT PRIMARY KEY, cc TEXT NOT NULL) WITHOUT ROWID');
                $ins = $pdo->prepare('INSERT INTO ip_geo VALUES(?,?)');
                foreach ($pdo->query("SELECT DISTINCT ip FROM $table WHERE ip <> ''")->fetchAll(PDO::FETCH_COLUMN) as $ip) {
                    $cc = Geo::country((string) $ip);
                    $ins->execute([$ip, $cc === '' ? '??' : $cc]);
                }
                $pdo->exec('CREATE INDEX i_geo_cc ON ip_geo(cc)');
                $pdo->exec("REPLACE INTO meta VALUES('geo','" . $total . "')");
                $pdo->commit();
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public static function open(string $name): PDO
    {
        $path = self::path($name);
        if (!is_file($path)) {
            throw new RuntimeException('Datensatz nicht gefunden: ' . $name);
        }
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA busy_timeout=30000; PRAGMA query_only=1; PRAGMA cache_size=-262144; PRAGMA mmap_size=4294967296; PRAGMA temp_store=MEMORY;');
        return $pdo;
    }

    public static function create(string $name, string $kind = 'access'): PDO
    {
        $path = self::tmpPath($name);
        foreach ([$path, $path . '-wal', $path . '-shm', $path . '-journal'] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA journal_mode=OFF; PRAGMA synchronous=OFF; PRAGMA locking_mode=EXCLUSIVE; PRAGMA cache_size=-400000; PRAGMA temp_store=MEMORY;');
        $pdo->exec('CREATE TABLE meta (k TEXT PRIMARY KEY, v TEXT);');
        if ($kind === 'error') {
            $pdo->exec('
                CREATE TABLE events (
                    id INTEGER PRIMARY KEY,
                    ts INTEGER NOT NULL,
                    ip TEXT NOT NULL,
                    level TEXT NOT NULL,
                    module TEXT NOT NULL,
                    code TEXT NOT NULL,
                    message TEXT NOT NULL,
                    msg_key TEXT NOT NULL,
                    target TEXT NOT NULL,
                    vhost TEXT NOT NULL
                );
            ');
            return $pdo;
        }
        $pdo->exec('
            CREATE TABLE user_agents (
                id INTEGER PRIMARY KEY,
                ua TEXT NOT NULL UNIQUE,
                browser TEXT NOT NULL,
                os TEXT NOT NULL,
                device TEXT NOT NULL,
                is_bot INTEGER NOT NULL
            );
            CREATE TABLE requests (
                id INTEGER PRIMARY KEY,
                ts INTEGER NOT NULL,
                ip TEXT NOT NULL,
                method TEXT NOT NULL,
                path TEXT NOT NULL,
                query TEXT NOT NULL,
                status INTEGER NOT NULL,
                bytes INTEGER NOT NULL,
                referer TEXT NOT NULL,
                ua_id INTEGER NOT NULL,
                vhost TEXT NOT NULL DEFAULT ""
            );
        ');
        return $pdo;
    }

    /** @return list<string> CREATE-INDEX-Statements der Haupttabelle */
    public static function indexSql(string $kind): array
    {
        return $kind === 'error'
            ? ['CREATE INDEX IF NOT EXISTS i_ts ON events(ts)', 'CREATE INDEX IF NOT EXISTS i_ip ON events(ip, ts)', 'CREATE INDEX IF NOT EXISTS i_level ON events(level, ts)', 'CREATE INDEX IF NOT EXISTS i_code ON events(code, ts)']
            : ['CREATE INDEX IF NOT EXISTS i_ts ON requests(ts)', 'CREATE INDEX IF NOT EXISTS i_ip ON requests(ip, ts)', 'CREATE INDEX IF NOT EXISTS i_status ON requests(status, ts)', 'CREATE INDEX IF NOT EXISTS i_ua ON requests(ua_id, ts)'];
    }

    /** @return list<string> Indexnamen, die waehrend eines Sammelimports entfallen (i_ts bleibt fuer Duplikatpruefung) */
    public static function droppableIndexes(string $kind): array
    {
        return $kind === 'error' ? ['i_ip', 'i_level', 'i_code'] : ['i_ip', 'i_status', 'i_ua'];
    }

    public static function ensureIndexes(PDO $pdo, string $kind): void
    {
        foreach (self::indexSql($kind) as $sql) {
            $pdo->exec($sql);
        }
        $pdo->exec("REPLACE INTO meta VALUES('idx_dropped','0')");
    }

    public static function dayTableSql(): string
    {
        return 'CREATE TABLE rollup_day (d INTEGER NOT NULL, dim TEXT NOT NULL, val TEXT NOT NULL, cnt INTEGER NOT NULL, bytes INTEGER NOT NULL, PRIMARY KEY (d, dim, val)) WITHOUT ROWID';
    }

    /**
     * Tageswerte je Dimension (Top-Werte pro Tag) fuer die Listen ohne Filter.
     * $days = null baut alles neu, sonst nur die angegebenen Tage (Tagesanfang als Unix-Zeit).
     *
     * @param list<int>|null $days
     */
    public static function buildDayRollup(PDO $pdo, string $kind, ?array $days = null): void
    {
        $table = $kind === 'error' ? 'events' : 'requests';
        $has = (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name='rollup_day'")->fetchColumn() > 0;
        if ($days === null || !$has) {
            $pdo->exec('DROP TABLE IF EXISTS rollup_day');
            $pdo->exec(self::dayTableSql());
            $hasHour = (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name='rollup_hour'")->fetchColumn() > 0;
            $days = array_map('intval', $hasHour
                ? $pdo->query('SELECT DISTINCT h/86400*86400 FROM rollup_hour ORDER BY 1')->fetchAll(PDO::FETCH_COLUMN)
                : $pdo->query("SELECT DISTINCT ts/86400*86400 FROM $table ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN));
        }

        $bytes = $kind === 'error' ? '0' : 'SUM(r.bytes)';
        $dims = $kind === 'error'
            ? [
                ['level', 'r.level', "AND r.level <> ''", '', 100],
                ['code', 'r.code', "AND r.code <> ''", '', 1000],
                ['module', 'r.module', "AND r.module <> ''", '', 500],
                ['msg_key', 'r.msg_key', '', '', 2000],
                ['target', 'r.target', "AND r.target <> ''", '', 1000],
                ['vhost', 'r.vhost', "AND r.vhost <> ''", '', 500],
            ]
            : [
                ['path', 'r.path', '', '', 2000],
                ['status', 'r.status', '', '', 200],
                ['referer', 'r.referer', "AND r.referer <> ''", '', 1000],
                ['vhost', 'r.vhost', "AND r.vhost <> ''", '', 500],
                ['browser', 'u.browser', '', 'JOIN user_agents u ON u.id = r.ua_id', 500],
                ['os', 'u.os', '', 'JOIN user_agents u ON u.id = r.ua_id', 500],
                ['device', 'u.device', '', 'JOIN user_agents u ON u.id = r.ua_id', 100],
            ];
        $stmts = [];
        foreach ($dims as [$dim, $expr, $extra, $join, $limit]) {
            $stmts[$dim] = $pdo->prepare(
                "SELECT $expr AS v, COUNT(*) AS c, $bytes AS b FROM $table r $join
                 WHERE r.ts >= ? AND r.ts < ? $extra GROUP BY v ORDER BY c DESC LIMIT $limit"
            );
        }
        $ipSt = $pdo->prepare("SELECT r.ip AS v, COUNT(*) AS c, $bytes AS b FROM $table r WHERE r.ts >= ? AND r.ts < ? AND r.ip <> '' GROUP BY r.ip");
        $qSt = $kind === 'access'
            ? $pdo->prepare("SELECT r.query AS v, COUNT(*) AS c FROM requests r WHERE r.ts >= ? AND r.ts < ? AND r.query <> '' GROUP BY r.query")
            : null;
        $del = $pdo->prepare('DELETE FROM rollup_day WHERE d = ?');
        $ins = $pdo->prepare('INSERT OR REPLACE INTO rollup_day VALUES(?,?,?,?,?)');
        /** @var array<string,string> $ccCache */
        static $ccCache = [];

        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        foreach ($days as $d) {
            $a = $d;
            $b = $d + 86400;
            $del->execute([$d]);
            foreach ($stmts as $dim => $st) {
                $st->execute([$a, $b]);
                foreach ($st->fetchAll(PDO::FETCH_NUM) as [$v, $c, $by]) {
                    $ins->execute([$d, $dim, (string) $v, (int) $c, (int) $by]);
                }
            }
            $ipSt->execute([$a, $b]);
            $ips = $ipSt->fetchAll(PDO::FETCH_NUM);
            $byCc = [];
            foreach ($ips as [$ip, $c, $by]) {
                $cc = $ccCache[$ip] ??= (Geo::country((string) $ip) ?: '??');
                $byCc[$cc] ??= [0, 0];
                $byCc[$cc][0] += (int) $c;
                $byCc[$cc][1] += (int) $by;
            }
            foreach ($byCc as $cc => [$c, $by]) {
                $ins->execute([$d, 'country', (string) $cc, $c, $by]);
            }
            usort($ips, static fn(array $x, array $y): int => $y[1] <=> $x[1]);
            foreach (array_slice($ips, 0, 5000) as [$ip, $c, $by]) {
                $ins->execute([$d, 'ip', (string) $ip, (int) $c, (int) $by]);
            }
            unset($ips, $byCc);
            if ($qSt !== null) {
                $qSt->execute([$a, $b]);
                $names = [];
                while ($row = $qSt->fetch(PDO::FETCH_NUM)) {
                    $seen = [];
                    foreach (explode('&', (string) $row[0]) as $pair) {
                        $name = urldecode(explode('=', $pair, 2)[0]);
                        if ($name === '' || isset($seen[$name])) {
                            continue;
                        }
                        $seen[$name] = true;
                        $names[$name] = ($names[$name] ?? 0) + (int) $row[1];
                    }
                }
                arsort($names);
                foreach (array_slice($names, 0, 500, true) as $name => $c) {
                    $ins->execute([$d, 'param', (string) $name, $c, 0]);
                }
            }
        }
        if ($own) {
            $pdo->commit();
        }
        $pdo->exec("REPLACE INTO meta VALUES('rday', COALESCE((SELECT v FROM meta WHERE k='lines'), ''))");
    }

    /** @return list<array{name:string,size:int,mtime:int,kind:string,format:string,lines:int}> */
    public static function datasets(): array
    {
        $out = [];
        foreach (glob(self::dir() . '/*.sqlite') ?: [] as $f) {
            $meta = [];
            try {
                $pdo = new PDO('sqlite:' . $f, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $meta = $pdo->query('SELECT k, v FROM meta')->fetchAll(PDO::FETCH_KEY_PAIR);
            } catch (\Throwable) {
            }
            $out[] = [
                'name' => basename($f, '.sqlite'),
                'size' => (int) filesize($f),
                'mtime' => (int) filemtime($f),
                'kind' => ($meta['kind'] ?? 'access') === 'error' ? 'error' : 'access',
                'format' => (string) ($meta['format'] ?? ''),
                'lines' => (int) ($meta['lines'] ?? 0),
            ];
        }
        usort($out, fn($a, $b) => strcmp($a['name'], $b['name']));
        return $out;
    }

    public static function delete(string $name): void
    {
        $path = self::path($name);
        foreach (['', '-wal', '-shm', '-journal'] as $s) {
            if (is_file($path . $s)) {
                unlink($path . $s);
            }
        }
        $base = self::dir() . '/' . self::safeName($name);
        foreach (['.progress.json', '.import.log', '.lock', '.geo.lock', '.rollup.lock', '.queue'] as $s) {
            if (is_file($base . $s)) {
                unlink($base . $s);
            }
        }
        foreach (glob(self::dir() . '/cache/' . self::safeName($name) . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir(self::dir() . '/cache/' . self::safeName($name));
        foreach (glob($base . '.*.{progress.json,import.log}', GLOB_BRACE) ?: [] as $f) {
            @unlink($f);
        }
    }
}
