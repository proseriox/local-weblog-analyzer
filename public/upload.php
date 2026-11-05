<?php

declare(strict_types=1);

use LocalWeblogAnalyzer\Db;

require __DIR__ . '/../vendor/autoload.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
set_time_limit(300);

function out(mixed $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Dataset-Name aus dem Dateinamen (ohne .gz und Endung). */
function datasetName(string $filename): string
{
    $base = preg_replace('/\.gz$/i', '', basename($filename)) ?? $filename;
    $base = preg_replace('/\.(log|txt|out)$/i', '', $base) ?? $base;
    return Db::safeName($base);
}

try {
    $root = dirname(__DIR__);
    foreach (glob(Db::dir() . '/*.{progress.json,import.log}', GLOB_BRACE) ?: [] as $stale) {
        if (filemtime($stale) < time() - 2 * 86400) {
            @unlink($stale);
        }
    }
    $uploadDir = $root . '/logs/upload';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        out(['error' => 'Upload-Ordner nicht anlegbar'], 500);
    }

    $filename = basename((string) ($_GET['name'] ?? ''));
    if ($filename === '' || $filename === '.' || $filename === '..') {
        out(['error' => 'Dateiname fehlt'], 400);
    }
    $append = isset($_GET['target']) && $_GET['target'] !== '';
    $ds = $append ? Db::safeName((string) $_GET['target']) : (isset($_GET['as']) && $_GET['as'] !== '' ? Db::safeName((string) $_GET['as']) : datasetName($filename));
    if ($append && !is_file(Db::path($ds))) {
        out(['error' => 'Datensatz nicht gefunden: ' . $ds], 404);
    }
    $part = $uploadDir . '/' . Db::safeName($filename) . '.part';
    $progress = Db::dir() . '/' . $ds . '.' . Db::safeName($filename) . '.progress.json';

    switch ($_GET['action'] ?? '') {
        case 'chunk':
            $offset = max(0, (int) ($_GET['offset'] ?? 0));
            if ($offset === 0 && is_file($part)) {
                unlink($part);
            }
            $out = fopen($part, 'c+b');
            $in = fopen('php://input', 'rb');
            if (!$out || !$in) {
                out(['error' => 'Schreiben nicht moeglich'], 500);
            }
            fseek($out, $offset);
            $written = stream_copy_to_stream($in, $out);
            fclose($out);
            fclose($in);
            out(['ok' => true, 'written' => $written]);

        case 'finish':
            $total = (int) ($_GET['total'] ?? 0);
            if (!is_file($part) || ($total > 0 && filesize($part) !== $total)) {
                out(['error' => 'Upload unvollstaendig (' . (is_file($part) ? filesize($part) : 0) . ' von ' . $total . ' Bytes)'], 400);
            }
            $final = $uploadDir . '/' . Db::safeName($filename);
            rename($part, $final);
            file_put_contents($progress, json_encode(['phase' => 'import', 'lines' => 0, 'pct' => 0]));
            $nice = 'nice -n 10 ' . (trim((string) @shell_exec('command -v ionice 2>/dev/null')) !== '' ? 'ionice -c2 -n7 ' : '');
            $cmd = sprintf(
                'cd %s && setsid nohup ' . $nice . 'php -d memory_limit=1G bin/import.php %s %s --progress=%s --delete-source%s > %s 2>&1 &',
                escapeshellarg($root),
                escapeshellarg($final),
                escapeshellarg($ds),
                escapeshellarg($progress),
                $append ? ' --append' : '',
                escapeshellarg(Db::dir() . '/' . $ds . '.' . Db::safeName($filename) . '.import.log')
            );
            exec($cmd);
            out(['ok' => true, 'dataset' => $ds]);

        case 'status':
            $j = is_file($progress) ? json_decode((string) file_get_contents($progress), true) : null;
            out($j ?: ['phase' => 'import', 'lines' => 0, 'pct' => 0]);

        default:
            out(['error' => 'Unbekannte Aktion'], 400);
    }
} catch (Throwable $e) {
    out(['error' => $e->getMessage()], 500);
}
