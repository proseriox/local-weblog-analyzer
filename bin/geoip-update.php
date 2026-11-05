<?php

declare(strict_types=1);

$target = dirname(__DIR__) . '/data/geoip-country.mmdb';
$url = 'https://cdn.jsdelivr.net/npm/@ip-location-db/dbip-country-mmdb/dbip-country.mmdb';

echo "Lade $url ...\n";
$in = @fopen($url, 'rb');
if ($in === false) {
    fwrite(STDERR, "Download fehlgeschlagen.\n");
    exit(1);
}
$tmp = $target . '.tmp';
$out = fopen($tmp, 'wb');
stream_copy_to_stream($in, $out);
fclose($in);
fclose($out);
if (filesize($tmp) < 100000) {
    unlink($tmp);
    fwrite(STDERR, "Datei unvollstaendig.\n");
    exit(1);
}
rename($tmp, $target);
printf("Gespeichert: %s (%.1f MB)\n", $target, filesize($target) / 1048576);
echo "IP-Geodaten: DB-IP.com Lite (CC BY 4.0)\n";
