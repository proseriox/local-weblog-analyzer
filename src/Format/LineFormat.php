<?php

declare(strict_types=1);

namespace LocalWeblogAnalyzer\Format;

interface LineFormat
{
    public function label(): string;

    /** 'access' oder 'error' */
    public function kind(): string;

    /**
     * access: ts, ip, method, path, query, status, bytes, referer, ua, vhost
     * error:  ts, ip, level, module, code, message, target, vhost
     *
     * @return array<string,mixed>|null
     */
    public function parse(string $line): ?array;
}
