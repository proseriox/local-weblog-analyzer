<?php

declare(strict_types=1);

namespace LocalWeblogAnalyzer;

use DeviceDetector\DeviceDetector;
use LocalWeblogAnalyzer\Format\Detector;
use PDO;
use RuntimeException;

final class Importer
{
    private const SAMPLE_LINES = 300;
    private const SET_CAP = 300;

    /** @var array<string,int> */
    private array $uaCache = [];
    private PDO $pdo;
    private \PDOStatement $uaFind;
    private \PDOStatement $uaInsert;
    /** @var array<string,array<string,true>> */
    private array $seen = ['vhosts' => [], 'levels' => [], 'modules' => []];

    /** @var array<string,array{string,string,string,int}> bereits erkannte User-Agents des Zieldatensatzes */
    private array $known = [];

    /** @param array<string,array{string,string,string,int}> $known */
    public function setKnownAgents(array $known): void
    {
        $this->known = $known;
    }

    /**
     * @param callable(string):void $log
     * @param (callable(string,int,float,string):void)|null $progress phase, Zeilen, Fortschritt 0..1, Formatname
     * @return array{lines:int,ok:int,bad:int,seconds:float,kind:string,format:string}
     */
    public function run(string $file, string $name, callable $log, ?callable $progress = null, bool $keep = false): array
    {
        if (!is_file($file)) {
            throw new RuntimeException('Datei nicht gefunden: ' . $file);
        }
        $gz = str_ends_with($file, '.gz');
        $h = fopen($gz ? 'compress.zlib://' . $file : $file, 'rb');
        if ($h === false) {
            throw new RuntimeException('Datei nicht lesbar: ' . $file);
        }
        $size = $gz ? self::gzipSize($file) : (int) filesize($file);

        $head = [];
        while (count($head) < self::SAMPLE_LINES && ($l = fgets($h)) !== false) {
            $head[] = $l;
        }
        $format = Detector::detect($head);
        $kind = $format->kind();
        $label = $format->label();
        $log('Format erkannt: ' . $label);
        if ($progress) {
            $progress('import', 0, 0.0, $label);
        }

        $this->pdo = Db::create($name, $kind);
        if ($kind === 'access') {
            $this->uaFind = $this->pdo->prepare('SELECT id FROM user_agents WHERE ua = ?');
            $this->uaInsert = $this->pdo->prepare('INSERT INTO user_agents(ua,browser,os,device,is_bot) VALUES(?,?,?,?,?)');
            $ins = $this->pdo->prepare('INSERT INTO requests(ts,ip,method,path,query,status,bytes,referer,ua_id,vhost) VALUES(?,?,?,?,?,?,?,?,?,?)');
        } else {
            $ins = $this->pdo->prepare('INSERT INTO events(ts,ip,level,module,code,message,msg_key,target,vhost) VALUES(?,?,?,?,?,?,?,?,?)');
        }

        $start = microtime(true);
        $lines = $ok = $bad = 0;
        $headCount = count($head);
        $this->pdo->beginTransaction();
        while (true) {
            if ($lines < $headCount) {
                $line = $head[$lines];
            } elseif (($line = fgets($h)) === false) {
                break;
            }
            $lines++;
            if ($lines === $headCount) {
                $head = [];
            }
            if (trim($line) === '') {
                continue;
            }
            $r = $format->parse($line);
            if ($r === null) {
                $bad++;
                if ($bad <= 5) {
                    $log('  nicht parsebar (Zeile ' . $lines . '): ' . mb_substr(trim($line), 0, 160));
                }
                continue;
            }
            if ($kind === 'access') {
                $this->remember('vhosts', $r['vhost']);
                $ins->execute([$r['ts'], $r['ip'], $r['method'], $r['path'], $r['query'], $r['status'], $r['bytes'], $r['referer'], $this->uaId($r['ua']), $r['vhost']]);
            } else {
                $this->remember('vhosts', $r['vhost']);
                $this->remember('levels', $r['level']);
                $this->remember('modules', $r['module']);
                $msg = mb_substr($r['message'], 0, 2000);
                $key = $r['target'] !== '' ? str_replace($r['target'], '', $msg) : $msg;
                $key = mb_substr(rtrim((string) preg_replace('/\d+/', '#', $key), " :\t"), 0, 300);
                $ins->execute([$r['ts'], $r['ip'], $r['level'], $r['module'], $r['code'], $msg, $key, mb_substr($r['target'], 0, 1000), $r['vhost']]);
            }
            $ok++;
            if ($ok % 100000 === 0) {
                $this->pdo->commit();
                $this->pdo->beginTransaction();
                $el = max(0.001, microtime(true) - $start);
                $pct = $size > 0 ? sprintf('%5.1f %%', ftell($h) / $size * 100) : '';
                if ($progress) {
                    $progress('import', $ok, $size > 0 ? ftell($h) / $size : 0.0, $label);
                }
                $log(sprintf('  %s Zeilen  %s  %s Zeilen/s', number_format($ok, 0, ',', '.'), $pct, number_format($ok / $el, 0, ',', '.')));
            }
        }
        fclose($h);
        $this->pdo->commit();

        if (!$keep) {
            $log('Indizes werden erstellt ...');
            if ($progress) {
                $progress('index', $ok, 1.0, $label);
            }
            foreach (Db::indexSql($kind) as $sql) {
                $this->pdo->exec($sql);
            }
        }
        $meta = $this->pdo->prepare('INSERT INTO meta VALUES(?,?)');
        foreach ([
            'file' => basename($file),
            'lines' => (string) $ok,
            'imported_at' => (string) time(),
            'kind' => $kind,
            'format' => $label,
            'bad' => (string) $bad,
        ] + array_map(static fn(array $s): string => json_encode(array_keys($s), JSON_UNESCAPED_UNICODE), $this->seen) as $k => $v) {
            $meta->execute([$k, $v]);
        }
        if (!$keep) {
            Db::buildRollup($this->pdo, $kind);
            Db::buildDayRollup($this->pdo, $kind);
            $this->pdo->exec('ANALYZE');
        }
        unset($ins, $meta);
        if ($kind === 'access') {
            unset($this->uaFind, $this->uaInsert);
        }
        $this->pdo = new PDO('sqlite::memory:');
        if (!$keep) {
            Db::finalize($name);
        }

        return ['lines' => $lines, 'ok' => $ok, 'bad' => $bad, 'seconds' => microtime(true) - $start, 'kind' => $kind, 'format' => $label];
    }

    /** Entpackte Groesse aus dem gzip-Trailer (ISIZE, mod 2^32); 0 wenn nicht lesbar. */
    private static function gzipSize(string $file): int
    {
        $f = fopen($file, 'rb');
        if ($f === false || fseek($f, -4, SEEK_END) !== 0) {
            return 0;
        }
        $b = fread($f, 4);
        fclose($f);
        $n = is_string($b) && strlen($b) === 4 ? (int) unpack('V', $b)[1] : 0;
        return $n >= (int) filesize($file) ? $n : 0;
    }

    private function remember(string $set, string $value): void
    {
        if ($value !== '' && count($this->seen[$set]) < self::SET_CAP) {
            $this->seen[$set][$value] = true;
        }
    }

    private function uaId(string $ua): int
    {
        if (isset($this->uaCache[$ua])) {
            return $this->uaCache[$ua];
        }
        if (count($this->uaCache) > 300000) {
            $this->uaCache = [];
        }
        $ua = mb_substr($ua, 0, 1000);
        $this->uaFind->execute([$ua]);
        $id = $this->uaFind->fetchColumn();
        if ($id === false) {
            [$browser, $os, $device, $bot] = $this->known[$ua] ?? $this->detect($ua);
            $this->uaInsert->execute([$ua, $browser, $os, $device, $bot]);
            $id = (int) $this->pdo->lastInsertId();
        }
        return $this->uaCache[$ua] = (int) $id;
    }

    /** @return array{string,string,string,int} */
    private function detect(string $ua): array
    {
        if ($ua === '' || $ua === '-') {
            return ['Unbekannt', 'Unbekannt', 'unbekannt', 0];
        }
        $dd = new DeviceDetector($ua);
        $dd->parse();
        if ($dd->isBot()) {
            $bot = $dd->getBot();
            $name = is_array($bot) && !empty($bot['name']) ? (string) $bot['name'] : 'Bot';
            return [$name, '-', 'bot', 1];
        }
        $browser = $dd->getClient('name');
        $os = $dd->getOs('name');
        $device = $dd->getDeviceName();
        return [
            is_string($browser) && $browser !== '' ? $browser : 'Unbekannt',
            is_string($os) && $os !== '' ? $os : 'Unbekannt',
            $device !== '' ? $device : 'unbekannt',
            0,
        ];
    }
}
