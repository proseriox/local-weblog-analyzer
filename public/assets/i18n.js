(() => {
  const m = document.cookie.match(/(?:^|; )lang=(de|en)/);
  const LANG = m ? m[1] : 'en';
  window.LANG = LANG;
  window.LOCALE = LANG === 'en' ? 'en-GB' : 'de-DE';
  document.documentElement.lang = LANG;

  const EN = {
    'Dateien → getrennte Tabs …': 'Files → separate tabs …',
    'Mehrere Logdateien wählen: jede Datei bekommt einen eigenen Tab': 'Select several log files: each file gets its own tab',
    'Dateien → ein Tab …': 'Files → one tab …',
    'Mehrere Logdateien in EINEN Tab laden (neuer oder bestehender Tab, Duplikate entfallen)': 'Load several log files into ONE tab (new or existing tab, duplicates are dropped)',
    'lädt …': 'loading …',
    'Sprache': 'Language',
    'Import läuft … ({n} offen)': 'Import running … ({n} open)',
    'Akzentfarbe': 'Accent colour',
    'Matrix-Grün': 'Matrix green', 'Grün': 'Green', 'Blau': 'Blue', 'Orange': 'Orange', 'Cyan': 'Cyan', 'Magenta': 'Magenta', 'Monochrom': 'Monochrome',
    'Farbschema': 'Colour scheme', 'Hell': 'Light',
    'Datensätze': 'Datasets',
    'Noch keine Daten': 'No data yet',
    'Oben „Dateien → getrennte Tabs“ (jede Datei ein Tab) oder „Dateien → ein Tab“ (alle in einem Tab) klicken oder Dateien auf die Seite ziehen. Access- und Error-Logs werden automatisch erkannt. Alternativ: <code>ddev logimport logs/access.log</code>':
      'Click “Files → separate tabs” (one tab per file) or “Files → one tab” (all in one tab) at the top, or drag files onto the page. Access and error logs are detected automatically. Alternatively: <code>ddev logimport logs/access.log</code>',
    '<b>ℹ 100 % lokal.</b> Alle Logdateien werden ausschließlich auf diesem Rechner verarbeitet und in lokalen Datenbanken unter <code>data/</code> abgelegt. Es werden keine Daten an externe Server oder Dienste gesendet – auch die Länder-Zuordnung der IPs erfolgt offline.':
      '<b>ℹ 100 % local.</b> All log files are processed exclusively on this computer and stored in local databases under <code>data/</code>. No data is sent to external servers or services – even the country lookup for IPs works offline.',
    'Zeitverlauf': 'Timeline',
    '(neueste zuerst, Zeilen anklicken für Details)': '(newest first, click rows for details)',
    'Einträge': 'Entries',
    'Mehr laden': 'Load more',
    'Quelloffen': 'Open source',
    '100 % lokal – keine Daten verlassen diesen Rechner': '100 % local – no data leaves this machine',

    'Requests im Zeitverlauf': 'Requests over time',
    'Einzelne Requests': 'Individual requests',
    'Fehler (≥400)': 'Errors (≥400)',
    'Von (Logzeit)': 'From (log time)',
    'Bis': 'To',
    'Land': 'Country',
    'Methode': 'Method',
    'Pfad enthält': 'Path contains',
    'Betriebssystem': 'Operating system',
    'Gerät': 'Device',
    'Nur Menschen': 'Humans only',
    'Nur Bots': 'Bots only',
    'User-Agent enthält': 'User agent contains',
    'Referer enthält': 'Referer contains',
    'Query-Parameter': 'Query parameter',
    '1.2.3.4 oder 10.0.*': '1.2.3.4 or 10.0.*',
    'DE oder DE, US': 'DE or DE, US',
    '/api oder !/assets': '/api or !/assets',
    'utm_source oder id': 'utm_source or id',
    '(! = nicht)': '(! = not)',
    'denied oder !favicon': 'denied or !favicon',
    'Top Länder': 'Top countries',
    'Top Pfade': 'Top paths',
    'Statuscodes': 'Status codes',
    'Betriebssysteme': 'Operating systems',
    'Geräte': 'Devices',
    'Häufigste Query-Parameter': 'Most used query parameters',
    'Eindeutige IPs': 'Unique IPs',
    'Fehler (4xx/5xx)': 'Errors (4xx/5xx)',
    'Fehlerquote': 'Error rate',
    'Serverfehler (5xx)': 'Server errors (5xx)',
    'Zeit': 'Time',
    'Pfad': 'Path',
    'Ereignisse im Zeitverlauf': 'Events over time',
    'Einzelne Fehlereinträge': 'Individual error entries',
    'Warnungen / Info': 'Warnings / info',
    'Fehler (error, crit …)': 'Errors (error, crit …)',
    'Fehlercode': 'Error code',
    'Modul': 'Module',
    'Meldung enthält': 'Message contains',
    'Ziel/Pfad enthält': 'Target/path contains',
    'Fehlercodes': 'Error codes',
    'Häufigste Meldungen': 'Most frequent messages',
    'Betroffene Ziele/Pfade': 'Affected targets/paths',
    'Module': 'Modules',
    'Ereignisse': 'Events',
    'Verschiedene Meldungen': 'Distinct messages',
    'Schwere Fehler': 'Severe errors',
    'Anteil schwer': 'Severe share',
    'Meldung': 'Message',
    'Ziel': 'Target',
    'Alle': 'All',
    'Zurücksetzen': 'Reset',
    'Shift + Klick: mehrere': 'Shift + click: several',

    'Erstellt': 'Created', 'Angenommen': 'Accepted', 'Kein Inhalt': 'No content', 'Teilinhalt': 'Partial content',
    'Dauerhaft verschoben': 'Moved permanently', 'Weiterleitung': 'Redirect', 'Nicht geändert': 'Not modified',
    'Temp. Weiterleitung': 'Temp. redirect', 'Perm. Weiterleitung': 'Perm. redirect',
    'Ungültige Anfrage': 'Bad request', 'Nicht autorisiert': 'Unauthorized', 'Verboten': 'Forbidden', 'Nicht gefunden': 'Not found',
    'Methode nicht erlaubt': 'Method not allowed', 'Zeitüberschreitung': 'Timeout', 'Entfernt': 'Gone', 'Zu groß': 'Too large',
    'Zu viele Anfragen': 'Too many requests', 'Client abgebrochen': 'Client closed request', 'Serverfehler': 'Server error',
    'Nicht implementiert': 'Not implemented', 'Nicht verfügbar': 'Unavailable', 'Gateway-Timeout': 'Gateway timeout',
    'Erfolg': 'Success', 'Client-Fehler': 'Client error', 'Server-Fehler': 'Server error',
    'Unbekannt / privat': 'Unknown / private',
    'Keine Daten': 'No data',

    'Monat': 'Month', 'Woche': 'Week', 'Tag': 'Day',
    '← Zurück zu {b}': '← Back to {b}',
    '(pro {b} – Balken anklicken oder Bereich mit gedrückter Maus aufziehen zum Hineinzoomen)': '(per {b} – click a bar or drag a range with the mouse to zoom in)',

    ' · {n} Zeilen nicht erkannt': ' · {n} lines not recognised',
    ' · {n} Einträge': ' · {n} entries',
    ' · Datei ': ' · File ',
    'Format nicht bekannt – es wurden Zeit, Level, IP und Fehlercode heuristisch aus jeder Zeile gelesen.': 'Format unknown – time, level, IP and error code were read heuristically from each line.',
    'Weitere Logdateien zu diesem Datensatz hinzufügen (Duplikate werden übersprungen)': 'Add more log files to this dataset (duplicates are skipped)',
    'Daten hinzufügen': 'Add data',
    'Datensatz löschen': 'Delete dataset',
    'Löschen': 'Delete',
    'Datensatz konnte nicht geladen werden: ': 'Dataset could not be loaded: ',
    'Datensatz „{n}“ löschen? (Die ursprüngliche Logdatei bleibt unverändert.)': 'Delete dataset “{n}”? (The original log file stays untouched.)',

    'wartet …': 'waiting …',
    'Upload: ': 'Upload: ',
    '{a} von {b}': '{a} of {b}',
    'Fehler: ': 'Error: ',
    'Import fehlgeschlagen': 'Import failed',
    'Fertig: ': 'Done: ',
    '{a} neu, {b} Duplikate übersprungen, jetzt {c} Einträge': '{a} new, {b} duplicates skipped, now {c} entries',
    '{n} Zeilen importiert': '{n} lines imported',
    '{n} nicht erkannt, ': '{n} not recognised, ',
    'Wartet: ': 'Waiting: ',
    'wartet auf den vorherigen Import …': 'waiting for the previous import …',
    'Zusammenführen: ': 'Merging: ',
    '{n} Zeilen werden mit „{t}“ abgeglichen …': '{n} lines are being compared with “{t}” …',
    'Indizes: ': 'Indexes: ',
    '{n} Zeilen': '{n} lines',
    'Import: ': 'Import: ',
    'Datei': 'file', 'Dateien': 'files',
    '{n} {w} in einen Tab laden': 'Load {n} {w} into one tab',
    'Neuer Tab …': 'New tab …',
    'Name des neuen Tabs': 'Name of the new tab',
    'Alle Dateien landen in einem Datensatz. Duplikate entfallen, die Zeilen werden zeitlich sortiert.': 'All files end up in one dataset. Duplicates are dropped and the rows are sorted chronologically.',
    'Abbrechen': 'Cancel',
    'Laden': 'Load',
    'zusammen': 'combined',
  };

  window.T = (de, vars) => {
    let s = LANG === 'en' && Object.prototype.hasOwnProperty.call(EN, de) ? EN[de] : de;
    if (vars) s = s.replace(/\{(\w+)\}/g, (_, k) => (k in vars ? vars[k] : '{' + k + '}'));
    return s;
  };

  if (LANG === 'en') {
    document.querySelectorAll('[data-t]').forEach((el) => { el.textContent = T(el.textContent.trim()); });
    document.querySelectorAll('[data-th]').forEach((el) => { el.innerHTML = T(el.innerHTML.trim()); });
    document.querySelectorAll('[data-tt]').forEach((el) => { el.title = T(el.title); });
    document.querySelectorAll('[data-ta]').forEach((el) => { el.setAttribute('aria-label', T(el.getAttribute('aria-label'))); });
  }
  const sel = document.getElementById('lang');
  if (sel) {
    sel.value = LANG;
    sel.addEventListener('change', () => {
      document.cookie = 'lang=' + sel.value + '; max-age=31536000; path=/; SameSite=Lax';
      location.reload();
    });
  }
})();
