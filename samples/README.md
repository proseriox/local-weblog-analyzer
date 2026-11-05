# Sample logs

Synthetic logs (no real data) to try LWA right away: pick them in the browser or run
`php bin/import.php samples/access-day1.log`.

| File | Format | What to try |
|------|--------|-------------|
| `access-day1.log` | Apache/nginx combined | Typical traffic: browsers, mobile, bots (Googlebot, bingbot), `curl`/`python-requests`, query parameters (`utm_source`, `id`), referers, a scanner IP probing `/wp-login.php`, `/.env` … |
| `access-day2.log` | Apache/nginx combined | Continues day 1 and **repeats its last 25 lines** → load day 1 + day 2 with “Files → one tab” to see duplicates being dropped. Contains a **5xx spike between 15:00 and 17:00**. |
| `access-day3.log.gz` | combined, gzip | Tests `.gz` import (scanner traffic only) |
| `error-apache24.log` | Apache 2.4 error log | Levels, `AH…` error codes, modules, PHP errors |
| `error-apache22.log` | Apache 2.x error log | Older format without module/pid |
| `error-nginx.log` | nginx error log | Upstream timeouts, missing files, forbidden access |

## About the IP addresses

- `192.0.2.0/24`, `198.51.100.0/24`, `203.0.113.0/24` are reserved documentation ranges and
  `10.0.0.15` is private → shown as “Unknown / private” in the country list.
- A few well-known public resolver/crawler addresses (e.g. `8.8.8.8`, `1.1.1.1`, `9.9.9.9`,
  `66.249.66.1`) are included so the country statistics and flags have something to show
  (requires the GeoIP database: `php bin/geoip-update.php`).
- `203.0.113.200` is the “scanner” in the logs.
