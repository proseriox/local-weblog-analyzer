# Local Weblog Analyzer (LWA)

**Webserver-Logs auf dem eigenen Rechner auswerten – schnell, privat, ohne Server.**

LWA importiert Apache-/nginx-Logdateien (auch mehrere GB) in lokale SQLite-Datenbanken und zeigt
sie in einem interaktiven Dashboard im Browser. Typische Fragen, die sich damit beantworten lassen:

- *Woher kommt mein Traffic?* Länder, Referer, Browser, Betriebssysteme, Geräte, Bots vs. Menschen
- *Was wird angefragt?* Top-Pfade, Query-Parameter (z. B. `utm_source`), Hosts/vHosts, Methoden
- *Was läuft schief?* 4xx-/5xx-Quote im Zeitverlauf, häufigste Fehler, Fehlercodes, betroffene Pfade, Module
- *Wer greift auf mich zu?* Top-IPs, Scanner und Bots, auffällige Spitzen in einem Zeitraum
- *Was war um 03:12 los?* Nach Zeit filtern und bis auf einzelne Requests oder Fehlereinträge hinunterbohren

Es wird nichts hochgeladen: Die Verarbeitung findet komplett auf deinem Rechner statt.

> [!WARNING]
> **LWA nicht auf einem (öffentlichen) Server betreiben.** Es ist ein *lokales Desktop-Werkzeug* für
> `localhost` bzw. eine lokale DDEV-/Docker-Umgebung. Es hat **keine Authentifizierung**, nimmt
> Datei-Uploads an, kann Datensätze löschen, und Logs enthalten personenbezogene Daten
> (IP-Adressen, User-Agents). Auf einem Webserver wäre all das offen zugänglich.
> Lade deine Logs stattdessen herunter und werte sie lokal aus.

🇬🇧 [English version](README.md)

## Was sich auswerten lässt

| Log-Typ | Formate | Dashboard |
|---------|---------|-----------|
| **Access-Logs** | Apache / nginx *combined* (und common) | Requests im Zeitverlauf (OK vs. Fehler), Kennzahlen (Requests, eindeutige IPs, Traffic, Fehlerquote, 5xx), Top Länder / IPs / Pfade / Statuscodes / Browser / OS / Geräte / Referer / Hosts / Query-Parameter, Request-Tabelle mit Details |
| **Error-Logs** | Apache 2.2, Apache 2.4, nginx | Ereignisse im Zeitverlauf, Kennzahlen (Ereignisse, eindeutige IPs, verschiedene Meldungen, schwere Fehler), Level, Fehlercodes, häufigste Meldungen, betroffene Ziele, Module, Länder, IPs |

Das Format wird automatisch erkannt. Ein Klick auf einen Listeneintrag setzt ihn als Filter. Verfügbare
Filter: Zeitraum, IP (Wildcards wie `10.0.*`), Land, Host, Status (`404`, `5xx`), Methode, Pfad
(mit `!` zum Ausschließen), Browser/OS/Gerät, Bot oder Mensch, User-Agent, Referer, Query-Parameter,
Fehlerlevel/-code/-modul und Meldungstext.

## So funktioniert es

1. Im Browser eine oder mehrere Logdateien wählen (normal oder `.gz`). Große Dateien werden fortsetzbar in Chunks hochgeladen.
2. Jede Datei wird ein eigener Tab – oder mehrere Dateien (z. B. rotierte Logs) werden in einem Tab zusammengeführt; Duplikate entfallen.
3. Das Log wird nach `data/<name>.sqlite` geparst, mit vorberechneten Statistiken, damit das Dashboard auch bei Millionen Zeilen schnell bleibt.
4. User-Agents werden mit [matomo/device-detector](https://github.com/matomo-org/device-detector) klassifiziert, IPs mit der freien DB-IP-Lite-Datenbank Ländern zugeordnet.

Außerdem: heller/dunkler Modus, Akzentfarben, deutsche und englische Oberfläche.

## Mit Beispieldaten ausprobieren

Der Ordner `samples/` enthält synthetische Access- und Error-Logs (Apache 2.2/2.4, nginx, `.gz`,
überlappende Dateien für das Zusammenführen, 5xx-Spitze, Bots und Scanner). Siehe
[samples/README.md](samples/README.md) (englisch).

## Voraussetzungen

- PHP ≥ 8.1 mit `pdo_sqlite`
- [Composer](https://getcomposer.org/)
- Optional: [DDEV](https://ddev.com/) als fertige Umgebung

## Installation

```bash
composer install
php bin/geoip-update.php      # optional: GeoIP-Länderdatenbank laden
```

### Starten

Mit DDEV:

```bash
ddev start
ddev launch
```

Ohne DDEV:

```bash
php -S 127.0.0.1:8080 -t public \
  -d upload_max_filesize=0 -d post_max_size=0 -d memory_limit=1G
```

Dann <http://127.0.0.1:8080> öffnen, Logdateien wählen und auswerten.

### Import per Kommandozeile

```bash
php bin/import.php logs/access.log.gz [name] [--append] [--delete-source] [--progress=datei]
# DDEV-Kurzbefehl
ddev logimport logs/access.log
```

Das Ergebnis liegt als `data/<name>.sqlite` vor.

## Projektstruktur

| Pfad | Zweck |
|------|-------|
| `public/` | Web-Root: `index.html` (UI), `api.php` (Abfragen), `upload.php` (Chunk-Upload) |
| `public/assets/` | Frontend-JS/CSS, i18n, gebündeltes Chart.js, Icons |
| `src/` | PHP-Bibliothek (`LocalWeblogAnalyzer\`): Importer, Merger, Queue, Filter, DB, GeoIP |
| `src/Format/` | Log-Parser und Formaterkennung |
| `bin/` | CLI-Werkzeuge: `import.php`, `geoip-update.php` |
| `data/` | Erzeugte SQLite-Datensätze, Caches, GeoIP-DB (git-ignoriert) |
| `samples/` | Synthetische Beispiel-Logs zum Ausprobieren |
| `logs/` | Eigene Logdateien und Uploads (git-ignoriert) |
| `.ddev/` | DDEV-Konfiguration |

## Sicherheitshinweis

LWA ist ein **lokales Werkzeug und darf nicht auf einem Server laufen**: keine
Authentifizierung, dafür Upload- und Lösch-Endpunkte. Nur an localhost binden. Logs enthalten personenbezogene Daten (IP-Adressen) – gemäß
Datenschutzrecht behandeln.

## Mitwirken

Siehe [CONTRIBUTING.md](CONTRIBUTING.md).

## Lizenz

MIT, siehe [LICENSE](LICENSE). Drittkomponenten und Lizenzen: siehe [README.md](README.md#license-and-third-party).
