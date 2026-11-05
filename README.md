# Local Weblog Analyzer (LWA)

**Explore web server log files on your own computer – quickly, privately, without a server.**

LWA imports Apache/nginx log files (even multi-GB ones) into local SQLite databases and shows
them in an interactive browser dashboard. Typical questions it answers:

- *Where does my traffic come from?* Countries, referers, browsers, operating systems, devices, bots vs. humans
- *What is being requested?* Top paths, query parameters (e.g. `utm_source`), hosts/vhosts, methods
- *What is going wrong?* 4xx/5xx rates over time, most frequent errors, error codes, affected paths, modules
- *Who is hitting me?* Top IPs, scanners and bots, suspicious spikes in a specific time range
- *What happened at 03:12?* Filter by time, then drill down to single requests or error entries

Nothing is uploaded anywhere: all processing happens on your machine.

> [!WARNING]
> **Do not deploy LWA on a (public) server.** It is a *local desktop tool* meant for
> `localhost` or a local DDEV/Docker setup. It has **no authentication**, accepts file uploads
> and can delete datasets, and log files contain personal data (IP addresses, user agents).
> Running it on a web server would expose all of that. Download your logs and analyse them
> on your own machine instead.

🇩🇪 [Deutsche Version](README.de.md)

<img width="2537" height="2937" alt="website" src="https://github.com/user-attachments/assets/cad8f4c5-5b8b-482a-9517-6a0eac06fd53" /> <img width="2537" height="2589" alt="image" src="https://github.com/user-attachments/assets/33b86b9f-e9c9-4563-b327-2f91e5ca9c73" />




## What you can analyse

| Log type | Supported formats | Dashboard |
|----------|-------------------|-----------|
| **Access logs** | Apache / nginx *combined* (and common) | Requests over time (OK vs. errors), KPIs (requests, unique IPs, traffic, error rate, 5xx), top countries / IPs / paths / status codes / browsers / OS / devices / referers / hosts / query parameters, request table with details |
| **Error logs** | Apache 2.2, Apache 2.4, nginx | Events over time, KPIs (events, unique IPs, distinct messages, severe errors), levels, error codes, most frequent messages, affected targets, modules, countries, IPs |

The format is detected automatically. Click any list entry to use it as a filter. Available
filters include time range, IP (wildcards like `10.0.*`), country, host, status (`404`, `5xx`),
method, path (with `!` to exclude), browser/OS/device, bot or human, user agent, referer,
query parameter, error level/code/module and message text.

## How it works

1. Pick one or more log files in the browser (plain or `.gz`). Large files are uploaded in resumable chunks.
2. Each file becomes its own tab – or merge several files (e.g. rotated logs) into one tab; duplicates are dropped.
3. The log is parsed into `data/<name>.sqlite` with pre-aggregated statistics, so the dashboard stays fast on millions of lines.
4. User agents are classified with [matomo/device-detector](https://github.com/matomo-org/device-detector), IPs are mapped to countries with the free DB-IP Lite database.

Further features: light/dark theme, accent colors, German and English UI.

## Try it with sample data

The `samples/` folder contains synthetic access and error logs (Apache 2.2/2.4, nginx, `.gz`,
overlapping files for the merge feature, a 5xx spike, bots and scanners). See
[samples/README.md](samples/README.md).

## Requirements

- PHP ≥ 8.1 with `pdo_sqlite`
- [Composer](https://getcomposer.org/)
- Optional: [DDEV](https://ddev.com/) for a ready-to-run environment

## Installation

```bash
composer install
php bin/geoip-update.php      # optional: download the GeoIP country database
```

### Run

With DDEV:

```bash
ddev start
ddev launch
```

Without DDEV:

```bash
php -S 127.0.0.1:8080 -t public \
  -d upload_max_filesize=0 -d post_max_size=0 -d memory_limit=1G
```

Then open <http://127.0.0.1:8080>, pick log files and start analysing.

### Command line import

```bash
php bin/import.php logs/access.log.gz [name] [--append] [--delete-source] [--progress=file]
# DDEV shortcut
ddev logimport logs/access.log
```

The result is stored as `data/<name>.sqlite`.

## Project layout

| Path | Purpose |
|------|---------|
| `public/` | Web root: `index.html` (UI), `api.php` (queries), `upload.php` (chunked upload) |
| `public/assets/` | Frontend JS/CSS, i18n, vendored Chart.js, icons |
| `src/` | PHP library (`LocalWeblogAnalyzer\`): importer, merger, queue, filters, DB, GeoIP |
| `src/Format/` | Log format parsers and auto-detection |
| `bin/` | CLI tools: `import.php`, `geoip-update.php` |
| `data/` | Generated SQLite datasets, caches, GeoIP DB (git-ignored) |
| `samples/` | Synthetic example logs for trying the tool |
| `logs/` | Place for your own log files and uploads (git-ignored) |
| `.ddev/` | DDEV configuration |

## Security note

LWA is designed as a **local tool and must not be run on a server**. It has no
authentication and offers upload and delete endpoints. Bind it to localhost only. Log files contain personal data (IP addresses) – handle
them according to applicable privacy law.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## License and third-party

MIT, see [LICENSE](LICENSE). Third-party components:

- [Chart.js](https://www.chartjs.org/) 4.4.4 (MIT), bundled in `public/assets/`
- [matomo/device-detector](https://github.com/matomo-org/device-detector) (LGPL-3.0), via Composer
- [maxmind-db/reader](https://github.com/maxmind/MaxMind-DB-Reader-php) (Apache-2.0), via Composer
- IP geolocation data by [DB-IP.com](https://db-ip.com) (Lite, CC BY 4.0), downloaded on demand
- [Twemoji Country Flags](https://github.com/talkjs/country-flag-emoji-polyfill) font (code MIT, Twemoji graphics CC BY 4.0), so flags render on every OS
- Brand icons in `public/assets/icons/` belong to their respective owners
