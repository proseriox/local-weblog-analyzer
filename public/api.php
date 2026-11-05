<?php

declare(strict_types=1);

use LocalWeblogAnalyzer\Db;
use LocalWeblogAnalyzer\Filter;
use LocalWeblogAnalyzer\Geo;

require __DIR__ . '/../vendor/autoload.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
set_time_limit(300);

function out(mixed $data, int $code = 200): never
{
    http_response_code($code);
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    $file = $GLOBALS['cacheFile'] ?? null;
    if ($file !== null && $code === 200 && $json !== false) {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $ver = strstr(basename($file), '-', true);
        $files = glob($dir . '/*.json') ?: [];
        foreach ($files as $old) {
            if (count($files) > 400 || !str_starts_with(basename($old), $ver . '-')) {
                @unlink($old);
            }
        }
        $tmp = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $json) !== false) {
            @rename($tmp, $file);
        }
    }
    echo $json;
    exit;
}

try {
    $action = (string) ($_GET['action'] ?? '');
    if ($action === 'datasets') {
        out(Db::datasets());
    }
    if ($action === 'delete') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            out(['error' => 'POST erforderlich'], 405);
        }
        Db::delete((string) ($_GET['ds'] ?? ''));
        out(['ok' => true]);
    }

    $hasFilters = array_filter($_GET, static fn($v, $k): bool => !in_array($k, ['action', 'ds', '_'], true) && trim((string) $v) !== '', ARRAY_FILTER_USE_BOTH) !== [];
    $needGeo = trim((string) ($_GET['country'] ?? '')) !== '';
    if ($action === 'countries') {
        $needGeo = true;
        if (!$hasFilters && ($_GET['ds'] ?? '') !== '' && is_file(Db::path((string) $_GET['ds']))) {
            $peek = Db::open((string) $_GET['ds'])->query("SELECT k, v FROM meta WHERE k IN ('rday','lines')")->fetchAll(PDO::FETCH_KEY_PAIR);
            if (isset($peek['rday'], $peek['lines']) && $peek['rday'] === $peek['lines']) {
                $needGeo = false;
            }
        }
    }
    if ($needGeo) {
        Db::ensureGeo((string) ($_GET['ds'] ?? ''));
    }
    if (in_array($action, ['kpi', 'timeline'], true)) {
        Db::ensureRollup((string) ($_GET['ds'] ?? ''));
    }
    $dsPath = Db::path((string) ($_GET['ds'] ?? ''));
    if ($action !== 'rows' && ($_GET['ds'] ?? '') !== '' && is_file($dsPath)) {
        clearstatcache(true, $dsPath);
        $q = $_GET;
        ksort($q);
        $ver = filemtime($dsPath) . filesize($dsPath);
        $cf = Db::dir() . '/cache/' . Db::safeName((string) $_GET['ds']) . '/' . $ver . '-' . sha1(json_encode($q)) . '.json';
        if (is_file($cf)) {
            header('X-Cache: hit');
            readfile($cf);
            exit;
        }
        $GLOBALS['cacheFile'] = $cf;
    }
    $db = Db::open((string) ($_GET['ds'] ?? ''));
    $metaRows = $db->query('SELECT k, v FROM meta')->fetchAll(PDO::FETCH_KEY_PAIR);
    $kind = ($metaRows['kind'] ?? 'access') === 'error' ? 'error' : 'access';
    $table = $kind === 'error' ? 'events' : 'requests';
    $f = Filter::fromRequest($_GET, $kind);
    $W = $f->where;
    $P = $f->params;
    $useRollup = $W === '1' && isset($metaRows['rollup'], $metaRows['lines']) && $metaRows['rollup'] === $metaRows['lines'];
    $useDay = $W === '1' && isset($metaRows['rday'], $metaRows['lines']) && $metaRows['rday'] === $metaRows['lines'];
    $dayDims = [
        'ips' => 'ip', 'vhosts' => 'vhost', 'status' => 'status', 'paths' => 'path', 'referers' => 'referer',
        'browsers' => 'browser', 'os' => 'os', 'devices' => 'device', 'params' => 'param', 'countries' => 'country',
        'levels' => 'level', 'codes' => 'code', 'modules' => 'module', 'messages' => 'msg_key', 'targets' => 'target',
    ];
    if ($useDay && isset($dayDims[$action])) {
        $stmt = $db->prepare('SELECT val AS label, SUM(cnt) AS count, SUM(bytes) AS bytes FROM rollup_day WHERE dim = ? GROUP BY val ORDER BY count DESC LIMIT 15');
        $stmt->execute([$dayDims[$action]]);
        $list = $stmt->fetchAll();
        foreach ($list as &$li) {
            $li['count'] = (int) $li['count'];
            $li['bytes'] = (int) $li['bytes'];
            if ($action === 'status') {
                $li['label'] = (int) $li['label'];
            }
            if ($action === 'ips') {
                $li['cc'] = Geo::country((string) $li['label']);
            }
        }
        unset($li);
        out($list);
    }

    $all = static function (string $sql, array $params) use ($db): array {
        $st = $db->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    };

    $top = static function (string $expr, string $join = '', string $extraWhere = '', int $limit = 15) use ($all, $W, $P, $table, $kind): array {
        $bytes = $kind === 'access' ? 'SUM(r.bytes)' : '0';
        return $all(
            "SELECT $expr AS label, COUNT(*) AS count, $bytes AS bytes FROM $table r $join
             WHERE $W $extraWhere GROUP BY label ORDER BY count DESC LIMIT $limit",
            $P
        );
    };
    $uaJoin = 'JOIN user_agents u ON u.id = r.ua_id';
    $json = static fn(string $k): array => json_decode($metaRows[$k] ?? '[]', true) ?: [];
    $serious = "r.level IN ('error','crit','alert','emerg')";

    switch ($action) {
        case 'meta':
            $range = $db->query("SELECT MIN(ts) AS min, MAX(ts) AS max FROM $table")->fetch();
            $range['total'] = isset($metaRows['lines']) ? (int) $metaRows['lines'] : (int) $db->query("SELECT COUNT(*) FROM $table")->fetchColumn();
            $base = [
                'kind' => $kind,
                'format' => $metaRows['format'] ?? 'Access-Log (Apache/nginx)',
                'file' => $metaRows['file'] ?? '',
                'bad' => (int) ($metaRows['bad'] ?? 0),
                'vhosts' => $json('vhosts'),
                'min' => $range['min'] ? gmdate('Y-m-d\TH:i', (int) $range['min']) : null,
                'max' => $range['max'] ? gmdate('Y-m-d\TH:i', (int) $range['max']) : null,
                'total' => (int) $range['total'],
            ];
            if ($kind === 'error') {
                $levels = $json('levels');
                sort($levels);
                $modules = $json('modules');
                sort($modules);
                out($base + ['levels' => $levels, 'modules' => $modules]);
            }
            $col = static fn(string $c): array => array_column($db->query("SELECT DISTINCT $c AS v FROM user_agents ORDER BY v")->fetchAll(), 'v');
            sort($base['vhosts']);
            out($base + ['browsers' => $col('browser'), 'os' => $col('os'), 'devices' => $col('device')]);

        case 'kpi':
            if ($useRollup) {
                $s = $db->query('SELECT COALESCE(SUM(total),0) AS total, COALESCE(SUM(bytes),0) AS bytes, COALESCE(SUM(errors),0) AS errors, COALESCE(SUM(errors5),0) AS errors5 FROM rollup_hour')->fetch();
                if ($kind === 'error') {
                    out(['total' => (int) $s['total'], 'ips' => (int) $metaRows['rollup_ips'], 'messages' => (int) $metaRows['rollup_msgs'], 'errors' => (int) $s['errors']]);
                }
                out(['total' => (int) $s['total'], 'bytes' => (int) $s['bytes'], 'ips' => (int) $metaRows['rollup_ips'], 'errors' => (int) $s['errors'], 'errors5' => (int) $s['errors5']]);
            }
            if ($kind === 'error') {
                out($all(
                    "SELECT COUNT(*) AS total, COUNT(DISTINCT r.ip) AS ips, COUNT(DISTINCT r.msg_key) AS messages,
                            COALESCE(SUM($serious),0) AS errors
                     FROM events r WHERE $W",
                    $P
                )[0]);
            }
            out($all(
                "SELECT COUNT(*) AS total, COALESCE(SUM(r.bytes),0) AS bytes, COUNT(DISTINCT r.ip) AS ips,
                        COALESCE(SUM(r.status >= 400),0) AS errors, COALESCE(SUM(r.status >= 500),0) AS errors5
                 FROM requests r WHERE $W",
                $P
            )[0]);

        case 'timeline':
            $r = $all("SELECT MIN(r.ts) AS a, MAX(r.ts) AS b FROM $table r WHERE $W", $P)[0];
            if ($r['a'] === null) {
                out(['bucket' => 3600, 'rows' => []]);
            }
            $span = max(1, (int) $r['b'] - (int) $r['a']);
            $bucket = 86400;
            foreach ([1, 5, 10, 30, 60, 300, 900, 1800, 3600, 10800, 21600, 86400] as $b) {
                if ($span / $b <= 240) {
                    $bucket = $b;
                    break;
                }
            }
            if ($span / 86400 > 240) {
                $bucket = $span / 604800 <= 240 ? 604800 : 0;
            }
            $tExpr = $bucket === 0
                ? "CAST(strftime('%s', r.ts, 'unixepoch', 'start of month') AS INTEGER)"
                : "(r.ts / $bucket) * $bucket";
            if ($useRollup && ($bucket === 0 || $bucket >= 3600)) {
                $rt = $bucket === 0
                    ? "CAST(strftime('%s', h, 'unixepoch', 'start of month') AS INTEGER)"
                    : "(h / $bucket) * $bucket";
                $rows = $all("SELECT $rt AS t, SUM(total) AS total, SUM(errors) AS errors FROM rollup_hour GROUP BY t ORDER BY t", []);
                out(['bucket' => $bucket, 'rows' => $rows]);
            }
            $errExpr = $kind === 'error' ? $serious : 'r.status >= 400';
            $rows = $all(
                "SELECT $tExpr AS t, COUNT(*) AS total, SUM($errExpr) AS errors
                 FROM $table r WHERE $W GROUP BY t ORDER BY t",
                $P
            );
            out(['bucket' => $bucket, 'rows' => $rows]);

        case 'countries':
            $rows = $all(
                "SELECT g.cc AS label, COUNT(*) AS count, " . ($kind === 'access' ? 'SUM(r.bytes)' : '0') . " AS bytes
                 FROM $table r JOIN ip_geo g ON g.ip = r.ip
                 WHERE $W GROUP BY g.cc ORDER BY count DESC LIMIT 15",
                $P
            );
            out($rows);

        case 'ips':
            $list = $top('r.ip', '', "AND r.ip <> ''");
            foreach ($list as &$li) {
                $li['cc'] = Geo::country((string) $li['label']);
            }
            unset($li);
            out($list);
        case 'params':
            $counts = [];
            $stmt = $db->prepare("SELECT r.query AS q, COUNT(*) AS c FROM requests r WHERE $W AND r.query <> '' GROUP BY r.query ORDER BY c DESC LIMIT 200000");
            $stmt->execute($P);
            while ($row = $stmt->fetch()) {
                $seen = [];
                foreach (explode('&', (string) $row['q']) as $pair) {
                    $name = urldecode(explode('=', $pair, 2)[0]);
                    if ($name === '' || isset($seen[$name])) {
                        continue;
                    }
                    $seen[$name] = true;
                    $counts[$name] = ($counts[$name] ?? 0) + (int) $row['c'];
                }
            }
            arsort($counts);
            $rows = [];
            foreach (array_slice($counts, 0, 15, true) as $name => $c) {
                $rows[] = ['label' => (string) $name, 'count' => $c, 'bytes' => 0];
            }
            out($rows);

        case 'vhosts':
            out($top('r.vhost', '', "AND r.vhost <> ''"));
        case 'status':
            out($top('r.status'));
        case 'paths':
            out($top('r.path'));
        case 'referers':
            out($top('r.referer', '', "AND r.referer <> ''"));
        case 'browsers':
            out($top('u.browser', $uaJoin));
        case 'os':
            out($top('u.os', $uaJoin));
        case 'devices':
            out($top('u.device', $uaJoin));
        case 'levels':
            out($top('r.level', '', "AND r.level <> ''"));
        case 'codes':
            out($top('r.code', '', "AND r.code <> ''"));
        case 'modules':
            out($top('r.module', '', "AND r.module <> ''"));
        case 'messages':
            out($top('r.msg_key'));
        case 'targets':
            out($top('r.target', '', "AND r.target <> ''"));

        case 'rows':
            $before = (int) ($_GET['before'] ?? 0);
            $beforeTs = isset($_GET['before_ts']) && $_GET['before_ts'] !== '' ? (int) $_GET['before_ts'] : null;
            $limit = min(500, max(1, (int) ($_GET['limit'] ?? 100)));
            $order = ($_GET['order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
            $op = $order === 'asc' ? '>' : '<';
            if ($before > 0 && $beforeTs !== null) {
                $cursor = " AND (r.ts, r.id) $op (?, ?)";
                $params = [...$P, $beforeTs, $before];
            } elseif ($before > 0) {
                $cursor = " AND r.id $op ?";
                $params = [...$P, $before];
            } else {
                $cursor = '';
                $params = $P;
            }
            if ($kind === 'error') {
                $rows = $all(
                    "SELECT r.id, r.ts, r.ip, r.level, r.module, r.code, r.message, r.target, r.vhost
                     FROM events r WHERE $W $cursor ORDER BY r.ts $order, r.id $order LIMIT $limit",
                    $params
                );
            } else {
                $rows = $all(
                    "SELECT r.id, r.ts, r.ip, r.method, r.path, r.query, r.status, r.bytes, r.referer, r.vhost,
                            u.ua, u.browser, u.os, u.device, u.is_bot
                     FROM requests r JOIN user_agents u ON u.id = r.ua_id
                     WHERE $W $cursor ORDER BY r.ts $order, r.id $order LIMIT $limit",
                    $params
                );
            }
            foreach ($rows as &$row) {
                $row['time'] = gmdate('Y-m-d H:i:s', (int) $row['ts']);
                $row['cc'] = Geo::country((string) $row['ip']);
            }
            out(['rows' => $rows, 'last' => $rows ? end($rows)['id'] : null, 'last_ts' => $rows ? end($rows)['ts'] : null, 'more' => count($rows) === $limit]);

        default:
            out(['error' => 'Unbekannte Aktion'], 400);
    }
} catch (Throwable $e) {
    out(['error' => $e->getMessage()], 500);
}
