<?php

declare(strict_types=1);

namespace LocalWeblogAnalyzer;

final class Filter
{
    /** @param list<mixed> $params */
    private function __construct(public readonly string $where, public readonly array $params)
    {
    }

    /** @param array<string,mixed> $q */
    public static function fromRequest(array $q, string $kind = 'access'): self
    {
        $w = [];
        $p = [];
        $get = static fn(string $k): string => trim((string) ($q[$k] ?? ''));

        if (($v = $get('from')) !== '' && ($t = self::time($v)) !== null) {
            $w[] = 'r.ts >= ?';
            $p[] = $t;
        }
        if (($v = $get('to')) !== '' && ($t = self::time($v)) !== null) {
            $w[] = 'r.ts <= ?';
            $p[] = strlen($v) <= 10 ? $t + 86399 : $t;
        }

        if (($v = $get('ip')) !== '') {
            $or = [];
            foreach (self::list($v) as $item) {
                if (str_ends_with($item, '*')) {
                    $or[] = '(r.ip >= ? AND r.ip < ?)';
                    $pre = substr($item, 0, -1);
                    array_push($p, $pre, $pre . "\xff");
                } else {
                    $or[] = 'r.ip = ?';
                    $p[] = $item;
                }
            }
            if ($or) {
                $w[] = '(' . implode(' OR ', $or) . ')';
            }
        }

        if (($v = $get('country')) !== '') {
            $items = array_map('strtoupper', self::list($v));
            if ($items) {
                $w[] = 'r.ip IN (SELECT ip FROM ip_geo WHERE cc IN (' . implode(',', array_fill(0, count($items), '?')) . '))';
                array_push($p, ...$items);
            }
        }

        if (($v = $get('vhost')) !== '') {
            $items = self::list($v);
            if ($items) {
                $w[] = 'r.vhost IN (' . implode(',', array_fill(0, count($items), '?')) . ')';
                array_push($p, ...$items);
            }
        }

        if ($kind === 'error') {
            self::errorFilters($get, $w, $p);
        } else {
            self::accessFilters($get, $w, $p);
        }

        return new self($w ? implode(' AND ', $w) : '1', $p);
    }

    /** @param list<string> $w @param list<mixed> $p */
    private static function errorFilters(callable $get, array &$w, array &$p): void
    {
        if (($v = $get('level')) !== '') {
            $items = array_map('strtolower', self::list($v));
            if ($items) {
                $w[] = 'r.level IN (' . implode(',', array_fill(0, count($items), '?')) . ')';
                array_push($p, ...$items);
            }
        }
        if (($v = $get('code')) !== '') {
            $items = array_map('strtoupper', self::list($v));
            if ($items) {
                $w[] = 'r.code IN (' . implode(',', array_fill(0, count($items), '?')) . ')';
                array_push($p, ...$items);
            }
        }
        if (($v = $get('module')) !== '') {
            $items = self::list($v);
            if ($items) {
                $w[] = 'r.module IN (' . implode(',', array_fill(0, count($items), '?')) . ')';
                array_push($p, ...$items);
            }
        }
        foreach (['text' => 'message', 'target' => 'target'] as $key => $col) {
            if (($v = $get($key)) !== '') {
                if ($v[0] === '!' && strlen($v) > 1) {
                    $w[] = "instr(lower(r.$col), lower(?)) = 0";
                    $p[] = substr($v, 1);
                } else {
                    $w[] = "instr(lower(r.$col), lower(?)) > 0";
                    $p[] = $v;
                }
            }
        }
    }

    /** @param list<string> $w @param list<mixed> $p */
    private static function accessFilters(callable $get, array &$w, array &$p): void
    {
        if (($v = $get('status')) !== '') {
            $or = [];
            foreach (self::list($v) as $item) {
                if (preg_match('/^([1-5])xx$/i', $item, $m)) {
                    $or[] = '(r.status >= ? AND r.status < ?)';
                    array_push($p, (int) $m[1] * 100, (int) $m[1] * 100 + 100);
                } elseif (ctype_digit($item)) {
                    $or[] = 'r.status = ?';
                    $p[] = (int) $item;
                }
            }
            if ($or) {
                $w[] = '(' . implode(' OR ', $or) . ')';
            }
        }

        if (($v = $get('method')) !== '') {
            $items = array_map('strtoupper', self::list($v));
            if ($items) {
                $w[] = 'r.method IN (' . implode(',', array_fill(0, count($items), '?')) . ')';
                array_push($p, ...$items);
            }
        }

        if (($v = $get('path')) !== '') {
            if ($v[0] === '!' && strlen($v) > 1) {
                $w[] = 'instr(r.path, ?) = 0';
                $p[] = substr($v, 1);
            } else {
                $w[] = 'instr(r.path, ?) > 0';
                $p[] = $v;
            }
        }

        if (($v = $get('param')) !== '') {
            $or = [];
            foreach (self::list($v) as $name) {
                $or[] = "(instr('&' || r.query || '&', ?) > 0 OR instr('&' || r.query, ?) > 0)";
                $p[] = '&' . $name . '&';
                $p[] = '&' . $name . '=';
            }
            if ($or) {
                $w[] = '(' . implode(' OR ', $or) . ')';
            }
        }

        if (($v = $get('referer')) !== '') {
            $w[] = 'instr(r.referer, ?) > 0';
            $p[] = $v;
        }

        $ua = [];
        foreach (['browser', 'os', 'device'] as $col) {
            if (($v = $get($col)) !== '') {
                $items = self::list($v);
                if ($items) {
                    $ua[] = "u.$col IN (" . implode(',', array_fill(0, count($items), '?')) . ')';
                    array_push($p, ...$items);
                }
            }
        }
        if (($v = $get('bot')) === '0' || $v === '1') {
            $ua[] = 'u.is_bot = ?';
            $p[] = (int) $v;
        }
        if (($v = $get('ua')) !== '') {
            $ua[] = 'instr(lower(u.ua), lower(?)) > 0';
            $p[] = $v;
        }
        if ($ua) {
            $w[] = 'r.ua_id IN (SELECT u.id FROM user_agents u WHERE ' . implode(' AND ', $ua) . ')';
        }
    }

    private static function time(string $v): ?int
    {
        $t = strtotime($v . ' UTC');
        return $t === false ? null : $t;
    }

    /** @return list<string> */
    private static function list(string $v): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $v)), fn($s) => $s !== ''));
    }
}
