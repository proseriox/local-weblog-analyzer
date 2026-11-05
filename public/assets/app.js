(() => {
  const $ = (s) => document.querySelector(s);
  const nf = new Intl.NumberFormat(LOCALE);
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const fmtBytes = (b) => {
    b = Number(b) || 0;
    const u = ['B', 'KB', 'MB', 'GB', 'TB'];
    let i = 0;
    while (b >= 1024 && i < u.length - 1) { b /= 1024; i++; }
    return (i ? b.toFixed(1) : b) + ' ' + u[i];
  };
  const opts = (list) => (list || []).map((v) => [v, v]);

  const levelClass = (l) => (['error', 'crit', 'alert', 'emerg'].includes(l) ? 's5' : l === 'warn' ? 's4' : l === 'debug' || l === 'info' || l === 'notice' ? 's3' : 's0');
  const regionNames = (() => { try { return new Intl.DisplayNames([LANG], { type: 'region' }); } catch { return null; } })();
  const flag = (cc) => (cc && /^[A-Z]{2}$/.test(cc)
    ? `<span class="flag" title="${esc((regionNames && regionNames.of(cc)) || cc)}">${String.fromCodePoint(...[...cc].map((c) => 0x1F1E6 + c.charCodeAt(0) - 65))}</span> `
    : '');
  const ipCell = (r) => (r.ip ? `${flag(r.cc)}<span class="ip" data-ip="${esc(r.ip)}">${esc(r.ip)}</span>` : '<span class="mut">–</span>');
  const kv = (k, v) => `<b>${k}:</b> ${esc(v) || '–'}`;

  const CFG = {
    access: {
      title: T('Requests im Zeitverlauf'),
      rowsTitle: T('Einzelne Requests'),
      tl: [T('OK (<400)'), T('Fehler (≥400)')],
      filters: [
        { k: 'from', label: T('Von (Logzeit)'), type: 'datetime-local' },
        { k: 'to', label: T('Bis'), type: 'datetime-local' },
        { k: 'ip', label: T('IP'), hint: T('(a, b, 10.0.*)'), ph: T('1.2.3.4 oder 10.0.*') },
        { k: 'country', label: T('Land'), hint: T('(DE, US)'), ph: T('DE oder DE, US') },
        { k: 'vhost', label: T('Host'), opts: (m) => opts(m.vhosts) },
        { k: 'status', label: T('Status'), hint: T('(404, 5xx)'), ph: T('404,5xx') },
        { k: 'method', label: T('Methode'), ph: T('GET,POST') },
        { k: 'path', label: T('Pfad enthält'), hint: T('(! = nicht)'), ph: T('/api oder !/assets') },
        { k: 'browser', label: T('Browser'), opts: (m) => opts(m.browsers) },
        { k: 'os', label: T('Betriebssystem'), opts: (m) => opts(m.os) },
        { k: 'device', label: T('Gerät'), opts: (m) => opts(m.devices) },
        { k: 'bot', label: T('Bots'), opts: () => [['0', T('Nur Menschen')], ['1', T('Nur Bots')]] },
        { k: 'ua', label: T('User-Agent enthält'), ph: T('curl') },
        { k: 'referer', label: T('Referer enthält'), ph: T('google') },
        { k: 'param', label: T('Query-Parameter'), hint: T('(a, b)'), ph: T('utm_source oder id') },
      ],
      lists: [
        ['countries', T('Top Länder'), 'country'], ['ips', T('Top IPs'), 'ip'], ['paths', T('Top Pfade'), 'path'], ['status', T('Statuscodes'), 'status'],
        ['browsers', T('Browser'), 'browser'], ['os', T('Betriebssysteme'), 'os'], ['devices', T('Geräte'), 'device'],
        ['referers', T('Referer'), 'referer'], ['vhosts', T('Hosts'), 'vhost'], ['params', T('Häufigste Query-Parameter'), 'param'],
      ],
      kpis: (k) => {
        const total = Number(k.total) || 0;
        const rate = total ? (k.errors / total * 100).toFixed(1).replace('.', LANG === 'de' ? ',' : '.') + ' %' : '–';
        return [
          [T('Requests'), nf.format(total), ''], [T('Eindeutige IPs'), nf.format(k.ips), ''], [T('Traffic'), fmtBytes(k.bytes), ''],
          [T('Fehler (4xx/5xx)'), nf.format(k.errors), 'bad'], [T('Fehlerquote'), rate, 'bad'], [T('Serverfehler (5xx)'), nf.format(k.errors5), 'bad'],
        ];
      },
      cols: [
        { h: T('Zeit'), c: 'c-time', f: (r) => r.time },
        { h: T('IP'), c: 'c-ip', f: ipCell },
        { h: T('Meth.'), c: 'c-meth', f: (r) => esc(r.method) },
        { h: T('Pfad'), c: 'c-path', t: (r) => r.path + (r.query ? '?' + r.query : ''), f: (r) => esc(r.path) + (r.query ? '<span class="mut">?' + esc(r.query.length > 40 ? r.query.slice(0, 40) + '…' : r.query) + '</span>' : '') },
        { h: T('Status'), c: 'c-st', f: (r) => statusBadge(r.status, false) },
        { h: T('Bytes'), c: 'c-num', f: (r) => nf.format(r.bytes) },
        { h: T('Browser'), c: 'c-br', f: (r) => iconFor('browsers', r.browser || '') + esc(r.browser) },
        { h: T('OS'), c: 'c-os', f: (r) => iconFor('os', r.os || '') + esc(r.os) },
        { h: T('Referer'), c: 'c-ref', t: (r) => r.referer, f: (r) => esc(r.referer) },
      ],
      detail: (r) => [kv(T('URL'), r.path + (r.query ? '?' + r.query : '')), kv(T('Host'), r.vhost), kv(T('User-Agent'), r.ua), kv(T('Referer'), r.referer)].join('<br>'),
    },
    error: {
      title: T('Ereignisse im Zeitverlauf'),
      rowsTitle: T('Einzelne Fehlereinträge'),
      tl: [T('Warnungen / Info'), T('Fehler (error, crit …)')],
      filters: [
        { k: 'from', label: T('Von (Logzeit)'), type: 'datetime-local' },
        { k: 'to', label: T('Bis'), type: 'datetime-local' },
        { k: 'ip', label: T('IP'), hint: T('(a, b, 10.0.*)'), ph: T('1.2.3.4 oder 10.0.*') },
        { k: 'country', label: T('Land'), hint: T('(DE, US)'), ph: T('DE oder DE, US') },
        { k: 'level', label: T('Level'), hint: T('(error, warn)'), ph: T('error,crit'), opts: (m) => opts(m.levels) },
        { k: 'code', label: T('Fehlercode'), ph: T('AH01630') },
        { k: 'module', label: T('Modul'), opts: (m) => opts(m.modules) },
        { k: 'vhost', label: T('Host'), opts: (m) => opts(m.vhosts) },
        { k: 'text', label: T('Meldung enthält'), hint: T('(! = nicht)'), ph: T('denied oder !favicon') },
        { k: 'target', label: T('Ziel/Pfad enthält'), hint: T('(! = nicht)'), ph: T('robots.txt') },
      ],
      lists: [
        ['levels', T('Level'), 'level'], ['codes', T('Fehlercodes'), 'code'], ['messages', T('Häufigste Meldungen'), ''],
        ['targets', T('Betroffene Ziele/Pfade'), 'target'], ['countries', T('Top Länder'), 'country'], ['ips', T('Top IPs'), 'ip'], ['modules', T('Module'), 'module'],
        ['vhosts', T('Hosts'), 'vhost'],
      ],
      kpis: (k) => {
        const total = Number(k.total) || 0;
        const rate = total ? (k.errors / total * 100).toFixed(1).replace('.', LANG === 'de' ? ',' : '.') + ' %' : '–';
        return [
          [T('Ereignisse'), nf.format(total), ''], [T('Eindeutige IPs'), nf.format(k.ips), ''], [T('Verschiedene Meldungen'), nf.format(k.messages), ''],
          [T('Schwere Fehler'), nf.format(k.errors), 'bad'], [T('Anteil schwer'), rate, 'bad'],
        ];
      },
      cols: [
        { h: T('Zeit'), c: 'c-time', f: (r) => r.time },
        { h: T('IP'), c: 'c-ip', f: ipCell },
        { h: T('Level'), c: 'c-lvl', f: (r) => (r.level ? `<span class="st ${levelClass(r.level)}">${esc(r.level)}</span>` : '') },
        { h: T('Modul'), c: 'c-mod', t: (r) => r.module, f: (r) => esc(r.module) },
        { h: T('Code'), c: 'c-code', f: (r) => (r.code ? `<span class="code" data-code="${esc(r.code)}">${esc(r.code)}</span>` : '') },
        { h: T('Meldung'), c: 'c-msg', t: (r) => r.message, f: (r) => esc(r.message) },
        { h: T('Host'), c: 'c-host', t: (r) => r.vhost, f: (r) => esc(r.vhost) },
      ],
      detail: (r) => [kv(T('Meldung'), r.message), kv(T('Ziel'), r.target), kv(T('Host'), r.vhost), kv(T('Modul'), r.module), kv(T('Code'), r.code)].join('<br>'),
    },
  };

  const state = { ds: null, kind: null, meta: null, datasets: [] };
  let gen = 0;
  let controller = null;
  let chart = null;
  let lastId = null;
  let lastTs = null;
  let pending = 0;
  let running = 0;
  let lastTimeline = null;
  const zoomStack = [];
  const bucketName = (b) => (b === 0 ? T('Monat') : b >= 604800 ? T('Woche') : b >= 86400 ? T('Tag') : b >= 3600 ? b / 3600 + ' h' : b >= 60 ? b / 60 + ' min' : b + ' s');
  const isoSec = (s) => new Date(s * 1000).toISOString().slice(0, 19);
  const updateBack = () => {
    const top = zoomStack[zoomStack.length - 1];
    const btn = $('#tlBack');
    if (top && top.ds !== state.ds) zoomStack.length = 0;
    const t = zoomStack[zoomStack.length - 1];
    btn.hidden = !t;
    if (t) btn.textContent = T('← Zurück zu {b}', { b: bucketName(t.bucket) });
  };
  const rowCache = new Map();

  const cfg = () => CFG[state.kind];
  const css = (v) => getComputedStyle(document.documentElement).getPropertyValue(v).trim();
  const root = document.documentElement;
  const mq = matchMedia('(prefers-color-scheme: dark)');

  function applyMode(mode, save) {
    root.dataset.mode = mode;
    root.dataset.theme = mode === 'dark' || (mode === 'system' && mq.matches) ? 'dark' : 'light';
    if (save) document.cookie = 'theme=' + mode + '; max-age=31536000; path=/; SameSite=Lax';
    document.querySelectorAll('#theme button').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.mode === mode)));
    if (lastTimeline && state.ds) renderTimeline(lastTimeline);
  }
  $('#theme').addEventListener('click', (e) => { const b = e.target.closest('button'); if (b) applyMode(b.dataset.mode, true); });
  function applyAccent(a, save) {
    root.dataset.accent = a;
    if (save) document.cookie = 'accent=' + a + '; max-age=31536000; path=/; SameSite=Lax';
    document.querySelectorAll('#accents button').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.accent === a)));
    if (lastTimeline && state.ds) renderTimeline(lastTimeline);
  }
  $('#accents').addEventListener('click', (e) => { const b = e.target.closest('button'); if (b) applyAccent(b.dataset.accent, true); });
  applyAccent(root.dataset.accent || 'orange', false);
  mq.addEventListener('change', () => { if (root.dataset.mode === 'system') applyMode('system', false); });

  const fieldKeys = () => cfg().filters.map((f) => f.k);
  const fieldEl = (k) => document.getElementById('f-' + k);
  const clearFilters = () => { zoomStack.length = 0; fieldKeys().forEach((k) => { const el = fieldEl(k); if (el) el.value = ''; }); };

  const params = (extra = {}) => {
    const p = new URLSearchParams({ ds: state.ds });
    if (state.kind) fieldKeys().forEach((k) => { const el = fieldEl(k); const v = el ? el.value.trim() : ''; if (v) p.set(k, v); });
    Object.entries(extra).forEach(([k, v]) => p.set(k, v));
    return p;
  };

  async function api(action, extra = {}, signal) {
    const r = await fetch('api.php?' + params({ action, ...extra }), { signal });
    const j = await r.json();
    if (!r.ok || j.error) throw new Error(j.error || r.statusText);
    return j;
  }

  function updateLoading() {
    const el = $('#loading');
    el.classList.toggle('hidden', pending <= 0 && running <= 0);
    el.textContent = running > 0 ? T('Import läuft … ({n} offen)', { n: running }) : T('lädt …');
    el.setAttribute('role', 'status');
  }
  function busy(d) {
    pending += d;
    updateLoading();
  }

  function task(myGen, signal, action, render, extra = {}) {
    busy(1);
    return api(action, extra, signal)
      .then((d) => { if (myGen === gen) render(d); })
      .catch((e) => { if (e.name !== 'AbortError' && myGen === gen) console.error(action, e); })
      .finally(() => busy(-1));
  }

  const STATUS_TXT = {
    200: 'OK', 201: 'Erstellt', 202: 'Angenommen', 204: 'Kein Inhalt', 206: 'Teilinhalt',
    301: 'Dauerhaft verschoben', 302: 'Weiterleitung', 304: 'Nicht geändert', 307: 'Temp. Weiterleitung', 308: 'Perm. Weiterleitung',
    400: 'Ungültige Anfrage', 401: 'Nicht autorisiert', 403: 'Verboten', 404: 'Nicht gefunden', 405: 'Methode nicht erlaubt',
    408: 'Zeitüberschreitung', 410: 'Entfernt', 413: 'Zu groß', 429: 'Zu viele Anfragen', 499: 'Client abgebrochen',
    500: 'Serverfehler', 501: 'Nicht implementiert', 502: 'Bad Gateway', 503: 'Nicht verfügbar', 504: 'Gateway-Timeout',
  };
  const STATUS_CLS = { 1: 'Info', 2: 'Erfolg', 3: 'Weiterleitung', 4: 'Client-Fehler', 5: 'Server-Fehler' };
  const statusBadge = (s, withText = true) => {
    const n = Number(s);
    const txt0 = STATUS_TXT[n] || STATUS_CLS[String(s)[0]] || '';
    const txt = txt0 ? T(txt0) : '';
    return `<span class="stb s${String(s)[0]}"${txt ? ` title="${esc(String(s) + ' ' + txt)}"` : ''}>${esc(String(s))}${withText && txt ? ' <small>' + esc(txt) + '</small>' : ''}</span>`;
  };
  const ICONS = {
    browsers: [[/firefox/i, '🦊'], [/safari/i, '🧭'], [/edge/i, '🌀'], [/opera/i, '🅾️'], [/samsung/i, '📱'], [/chrom/i, '🟡'], [/curl|wget|python|java|go-http|bot|spider|crawl|uptime|monitor|scrapy|http/i, '🕷️']],
    os: [[/windows/i, '🪟'], [/android/i, '🤖'], [/ios|mac|apple|iphone|ipad/i, '🍎'], [/linux|ubuntu|debian|fedora|gnu|centos|arch/i, '🐧'], [/chrome ?os/i, '💻']],
    devices: [[/smartphone|phablet|feature/i, '📱'], [/tablet/i, '📲'], [/desktop/i, '🖥️'], [/tv|television/i, '📺'], [/console/i, '🎮'], [/wearable|watch/i, '⌚'], [/car/i, '🚗'], [/bot|crawler/i, '🕷️']],
  };
  const BRANDS = [
    [/edge/i, 'edge', '#0C8CE9'], [/firefox/i, 'firefoxbrowser', '#FF7139'], [/opera/i, 'opera', '#FF1B2D'], [/samsung/i, 'samsung', '#1428A0'],
    [/brave/i, 'brave', '#FB542B'], [/vivaldi/i, 'vivaldi', '#EF3939'], [/duckduck/i, 'duckduckgo', '#DE5833'],
    [/safari/i, 'safari', '#006CFF'], [/chrom/i, 'googlechrome', '#4285F4'],
    [/curl/i, 'curl', 'currentColor'], [/python/i, 'python', '#3776AB'], [/^go|go-http/i, 'go', '#00ADD8'], [/java/i, 'openjdk', '#ED8B00'],
    [/windows/i, 'windows', '#0078D4'], [/android/i, 'android', '#3DDC84'], [/ubuntu/i, 'ubuntu', '#E95420'], [/debian/i, 'debian', '#A81D33'],
    [/fedora/i, 'fedora', '#51A2DA'], [/centos/i, 'centos', '#932279'], [/arch/i, 'archlinux', '#1793D1'],
    [/^mac|mac ?os|os x|macintosh/i, 'macos', 'currentColor'], [/ios|iphone|ipad|apple/i, 'ios', 'currentColor'], [/linux|gnu/i, 'linux', 'currentColor'],
  ];
  const iconFor = (name, label) => {
    if (name !== 'devices') {
      const b = BRANDS.find(([re]) => re.test(label));
      if (b) return `<span class="bi" title="${esc(label)}" style="--i:url(/assets/icons/${b[1]}.svg);background-color:${b[2]}"></span> `;
    }
    const hit = (ICONS[name] || []).find(([re]) => re.test(label));
    return hit ? `<span class="ico">${hit[1]}</span> ` : (ICONS[name] ? '<span class="ico">\u2754</span> ' : '');
  };
  const listLabel = (name, r) => {
    if (name === 'countries') {
      return flag(r.label) + esc(r.label === '??' ? T('Unbekannt / privat') : ((regionNames && regionNames.of(r.label)) || r.label));
    }
    if (name === 'status') return statusBadge(r.label);
    if (ICONS[name]) return iconFor(name, String(r.label)) + esc(r.label);
    return flag(r.cc) + esc(r.label);
  };

  const MULTI = new Set(['param', 'ip', 'country', 'status', 'method', 'vhost', 'browser', 'os', 'device', 'level', 'module', 'code']);
  const isMulti = (k) => MULTI.has(k);
  const setField = (el, v) => {
    if (el.tagName === 'SELECT' && v !== '' && ![...el.options].some((o) => o.value === v)) {
      const o = document.createElement('option');
      o.value = v; o.textContent = v.split(',').join(' + ');
      el.appendChild(o);
    }
    el.value = v;
  };
  const selectedOf = (k) => { const el = fieldEl(k); return new Set(el ? el.value.split(',').map((s) => s.trim()).filter(Boolean) : []); };

  function renderList(name, field, data) {
    const el = $('#t-' + name);
    if (!data.length) { el.innerHTML = '<div class="empty">' + T('Keine Daten') + '</div>'; return; }
    const max = data[0].count;
    const sel = field ? selectedOf(field) : new Set();
    el.innerHTML = data.map((r) => `
      <div class="top-row${field ? '' : ' static'}${sel.has(String(r.label)) ? ' sel' : ''}" data-field="${field}" data-value="${esc(r.label)}" title="${esc(r.label)}${state.kind === 'access' ? ' – ' + fmtBytes(r.bytes) : ''}">
        <div class="bar" style="width:${(r.count / max * 100).toFixed(1)}%"></div>
        <span>${listLabel(name, r)}</span><span class="n">${nf.format(r.count)}</span>
      </div>`).join('');
  }

  function renderKpi(k) {
    $('#kpis').innerHTML = cfg().kpis(k).map(([l, v, c]) => `<div class="kpi ${c}"><span>${l}</span><b>${v}</b></div>`).join('');
  }

  let dragged = false;
  function zoomTo(d, i0, i1) {
    const a = Math.min(i0, i1), b = Math.max(i0, i1);
    const t0 = d.rows[a].t, t1 = d.rows[b].t;
    const endOf = (t) => (d.bucket === 0
      ? Date.UTC(new Date(t * 1000).getUTCFullYear(), new Date(t * 1000).getUTCMonth() + 1, 1) / 1000 - 1
      : t + d.bucket - 1);
    const f = fieldEl('from'), o = fieldEl('to');
    if (!f || !o) return;
    if (d.bucket !== 0 && d.bucket <= 1) return;
    zoomStack.push({ ds: state.ds, from: f.value, to: o.value, bucket: d.bucket });
    f.value = isoSec(t0);
    o.value = isoSec(endOf(t1));
    refresh();
  }

  (function initDragSelect() {
    const cv = $('#timeline');
    const host = cv.parentElement;
    if (getComputedStyle(host).position === 'static') host.style.position = 'relative';
    const box = document.createElement('div');
    box.className = 'tl-sel';
    box.hidden = true;
    host.appendChild(box);
    let startX = null, startIdx = null, moved = false;
    const idxAt = (x) => {
      const sc = chart.scales.x;
      const n = lastTimeline.rows.length;
      return Math.max(0, Math.min(n - 1, Math.round(sc.getValueForPixel(x))));
    };
    const local = (e) => e.clientX - cv.getBoundingClientRect().left;
    cv.addEventListener('mousedown', (e) => {
      if (e.button !== 0 || !chart || !lastTimeline || !lastTimeline.rows.length) return;
      startX = local(e); startIdx = idxAt(startX); moved = false;
    });
    window.addEventListener('mousemove', (e) => {
      if (startX === null) return;
      const x = local(e);
      if (!moved && Math.abs(x - startX) < 6) return;
      moved = true;
      const sc = chart.scales.x;
      const bw = (sc.right - sc.left) / lastTimeline.rows.length;
      const i0 = Math.min(startIdx, idxAt(x)), i1 = Math.max(startIdx, idxAt(x));
      const left = sc.getPixelForValue(i0) - bw / 2, right = sc.getPixelForValue(i1) + bw / 2;
      const off = cv.getBoundingClientRect().left - host.getBoundingClientRect().left;
      box.hidden = false;
      box.style.left = off + Math.max(sc.left, left) + 'px';
      box.style.width = Math.max(2, Math.min(sc.right, right) - Math.max(sc.left, left)) + 'px';
      box.style.top = chart.chartArea.top + 'px';
      box.style.height = chart.chartArea.bottom - chart.chartArea.top + 'px';
    });
    window.addEventListener('mouseup', (e) => {
      if (startX === null) return;
      const i0 = startIdx, i1 = idxAt(local(e));
      const did = moved;
      startX = null; box.hidden = true;
      if (did) { dragged = true; setTimeout(() => { dragged = false; }, 0); zoomTo(lastTimeline, i0, i1); }
    });
  })();

  function renderTimeline(d) {
    lastTimeline = d;
    const mut = css('--mut'), line = css('--line');
    const month = d.bucket === 0;
    const fmt = month
      ? (t) => new Date(t * 1000).toLocaleDateString(LOCALE, { timeZone: 'UTC', month: 'short', year: 'numeric' })
      : d.bucket >= 86400
        ? (t) => new Date(t * 1000).toLocaleDateString(LOCALE, { timeZone: 'UTC' })
        : (t) => new Date(t * 1000).toLocaleString(LOCALE, { timeZone: 'UTC', dateStyle: 'short', timeStyle: d.bucket < 60 ? 'medium' : 'short' });
    const labels = d.rows.map((r) => fmt(r.t));
    const ok = d.rows.map((r) => r.total - r.errors);
    const err = d.rows.map((r) => Number(r.errors));
    $('#bucketInfo').textContent = d.rows.length ? T('(pro {b} – Balken anklicken oder Bereich mit gedrückter Maus aufziehen zum Hineinzoomen)', { b: bucketName(d.bucket) }) : '';
    updateBack();
    if (chart) chart.destroy();
    const tl = cfg().tl;
    chart = new Chart($('#timeline'), {
      type: 'bar',
      data: { labels, datasets: [
        { label: tl[0], data: ok, backgroundColor: css('--ch1'), stack: 's' },
        { label: tl[1], data: err, backgroundColor: css('--ch2'), stack: 's' },
      ] },
      options: {
        responsive: true, maintainAspectRatio: false, animation: false,
        interaction: { mode: 'index', intersect: false },
        onClick: (e, _a, c) => {
          if (dragged) { dragged = false; return; }
          const hit = c.getElementsAtEventForMode(e, 'index', { intersect: false }, true)[0];
          if (!hit) return;
          zoomTo(d, hit.index, hit.index);
        },
        onHover: (e, el) => { e.native.target.style.cursor = el.length ? 'pointer' : 'default'; },
        scales: { x: { stacked: true, ticks: { color: mut, maxTicksLimit: 12 }, grid: { display: false } },
                  y: { stacked: true, ticks: { color: mut }, grid: { color: line } } },
        plugins: { legend: { labels: { color: css('--text') } } },
      },
    });
  }

  function rowHtml(r) {
    rowCache.set(String(r.id), r);
    return `<tr class="r" data-id="${r.id}">` + cfg().cols.map((c) => {
      const t = c.t ? ` title="${esc(c.t(r))}"` : '';
      return `<td class="${c.c}"${t}>${c.f(r)}</td>`;
    }).join('') + '</tr>';
  }

  async function loadRows(reset, myGen, signal) {
    if (reset) { $('#rows tbody').innerHTML = ''; rowCache.clear(); lastId = null; lastTs = null; }
    busy(1);
    try {
      const d = await api('rows', lastId ? { before: lastId, before_ts: lastTs, limit: 100 } : { limit: 100 }, signal);
      if (myGen !== gen) return;
      $('#rows tbody').insertAdjacentHTML('beforeend', d.rows.map(rowHtml).join(''));
      lastId = d.last;
      lastTs = d.last_ts;
      $('#more').style.display = d.more ? '' : 'none';
    } catch (e) {
      if (e.name !== 'AbortError') console.error(e);
    } finally { busy(-1); }
  }

  function refresh() {
    if (!state.ds || !state.kind) return;
    if (controller) controller.abort();
    controller = new AbortController();
    const g = ++gen, s = controller.signal;
    fieldKeys().forEach((k) => { const el = fieldEl(k); if (el) el.classList.toggle('active', !!el.value.trim()); });
    history.replaceState(null, '', '?' + params());
    task(g, s, 'kpi', renderKpi);
    task(g, s, 'timeline', renderTimeline);
    cfg().lists.forEach(([a, , f]) => task(g, s, a, (d) => renderList(a, f, d)));
    loadRows(true, g, s);
  }

  const debounce = (fn, ms) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; };
  const refreshSoon = debounce(refresh, 400);

  function buildLayout() {
    const c = cfg(), m = state.meta;
    $('#filters').innerHTML = c.filters.map((f) => {
      const hint = f.hint ? ` <small>${esc(f.hint)}</small>` : '';
      if (f.opts) {
        const o = f.opts(m);
        if (!o.length) return '';
        return `<label>${esc(f.label)}${hint}<select id="f-${f.k}"><option value="">${T('Alle')}</option>${o.map(([v, l]) => `<option value="${esc(v)}">${esc(l)}</option>`).join('')}</select></label>`;
      }
      return `<label>${esc(f.label)}${hint}<input id="f-${f.k}" type="${f.type || 'text'}" placeholder="${esc(f.ph || '')}"></label>`;
    }).join('') + '<div class="actions"><button id="reset" type="button">' + T('Zurücksetzen') + '</button></div>';
    $('#lists').innerHTML = c.lists.map(([a, title, f]) => `<div class="card"><h2>${esc(title)}${isMulti(f) ? ' <small class="multi-hint">' + T('Shift + Klick: mehrere') + '</small>' : ''}</h2><div id="t-${a}"></div></div>`).join('');
    $('#tlTitle').textContent = c.title;
    $('#rowsTitle').textContent = c.rowsTitle;
    $('#rows thead').innerHTML = '<tr>' + c.cols.map((x) => `<th class="${x.c}">${esc(x.h)}</th>`).join('') + '</tr>';
    $('#rows').dataset.kind = state.kind;
    const bad = m.bad ? T(' · {n} Zeilen nicht erkannt', { n: nf.format(m.bad) }) : '';
    $('#formatInfo').innerHTML = `<span class="badge ${state.kind}">${state.kind === 'error' ? 'ERROR-LOG' : 'ACCESS-LOG'}</span> ${esc(m.format)}${T(' · {n} Einträge', { n: nf.format(m.total) })}${bad}` +
      (m.file ? ` ${T(' · Datei ').trim()} <code>${esc(m.file)}</code>` : '') +
      (m.format.startsWith('Unbekannt') ? ' · <span class="warnt">' + T('Format nicht bekannt – es wurden Zeit, Level, IP und Fehlercode heuristisch aus jeder Zeile gelesen.') + '</span>' : '');
  }

  function renderTabs() {
    $('#tabs').innerHTML = state.datasets.map((d) => `
      <div class="tab ${d.kind}${d.name === state.ds ? ' active' : ''}" data-ds="${esc(d.name)}" role="tab" tabindex="0" title="${esc(d.format)} · ${fmtBytes(d.size)}">
        <span class="badge ${d.kind}">${d.kind === 'error' ? 'ERR' : 'ACC'}</span>
        <span class="tn">${esc(d.name)}</span>
        <button type="button" class="add" data-add="${esc(d.name)}" title="${T('Weitere Logdateien zu diesem Datensatz hinzufügen (Duplikate werden übersprungen)')}" aria-label="${T('Daten hinzufügen')}">+</button>
        <button type="button" class="x" data-del="${esc(d.name)}" title="${T('Datensatz löschen')}" aria-label="${T('Löschen')}">×</button>
      </div>`).join('');
    const ap = $('#append');
    if (ap) ap.hidden = false;
  }

  async function selectDataset(name, keepFilters) {
    const d = state.datasets.find((x) => x.name === name);
    if (!d) return;
    const kindChanged = state.kind !== d.kind;
    const saved = keepFilters && !kindChanged ? params() : null;
    state.ds = name;
    state.kind = d.kind;
    renderTabs();
    busy(1);
    try {
      state.meta = await (await fetch('api.php?action=meta&ds=' + encodeURIComponent(name))).json();
      if (state.meta.error) throw new Error(state.meta.error);
    } catch (e) {
      busy(-1);
      alert(T('Datensatz konnte nicht geladen werden: ') + e.message);
      return;
    }
    busy(-1);
    state.kind = state.meta.kind;
    lastTimeline = null;
    buildLayout();
    const src = saved || (keepFilters === 'url' ? new URLSearchParams(location.search) : null);
    if (src) fieldKeys().forEach((k) => { const el = fieldEl(k); if (el && src.has(k)) el.value = src.get(k); });
    $('#app').style.display = '';
    $('#empty').style.display = 'none';
    refresh();
  }

  async function loadDatasets() {
    state.datasets = await (await fetch('api.php?action=datasets')).json();
    if (!state.datasets.some((d) => d.name === state.ds)) { state.ds = null; state.kind = null; }
    renderTabs();
    if (!state.datasets.length) {
      $('#app').style.display = 'none';
      $('#empty').style.display = '';
    }
  }

  async function init() {
    await loadDatasets();
    if (!state.datasets.length) return;
    const want = new URLSearchParams(location.search).get('ds');
    const name = state.datasets.some((d) => d.name === want) ? want : state.datasets[0].name;
    await selectDataset(name, 'url');
  }

  async function deleteDataset(name) {
    if (!confirm(T('Datensatz „{n}“ löschen? (Die ursprüngliche Logdatei bleibt unverändert.)', { n: name }))) return;
    await fetch('api.php?action=delete&ds=' + encodeURIComponent(name), { method: 'POST' });
    const wasActive = name === state.ds;
    await loadDatasets();
    if (wasActive && state.datasets.length) await selectDataset(state.datasets[0].name);
  }

  $('#tabs').addEventListener('click', (e) => {
    const add = e.target.closest('[data-add]');
    if (add) { e.stopPropagation(); addTarget = add.dataset.add; $('#file').click(); return; }
    const del = e.target.closest('[data-del]');
    if (del) { e.stopPropagation(); deleteDataset(del.dataset.del); return; }
    const tab = e.target.closest('.tab');
    if (tab && tab.dataset.ds !== state.ds) selectDataset(tab.dataset.ds);
  });
  $('#tabs').addEventListener('keydown', (e) => {
    const tab = e.target.closest('.tab');
    if (tab && (e.key === 'Enter' || e.key === ' ') && e.target === tab) { e.preventDefault(); selectDataset(tab.dataset.ds); }
  });

  /* ---------- Mehrfach-Upload ---------- */
  const jobsBox = $('#jobs');
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
  let uploadChain = Promise.resolve();
  const batch = { sets: new Set(), auto: false, last: null };
  const finishJob = (job) => { if (!job.fin) { job.fin = true; running--; updateLoading(); } };
  window.addEventListener('beforeunload', (e) => { if (running > 0) { e.preventDefault(); e.returnValue = ''; } });
  async function settle() {
    if (running > 0 || !batch.last) return;
    const b = { sets: new Set(batch.sets), auto: batch.auto, last: batch.last };
    batch.sets.clear(); batch.auto = false; batch.last = null;
    if (b.auto || !state.ds) await selectDataset(b.last);
    else if (b.sets.has(state.ds)) await selectDataset(state.ds, true);
  }
  let addTarget = null;

  function makeJob(file, target = addTarget, as = null, sync = false) {
    const el = document.createElement('div');
    el.className = 'job card';
    el.innerHTML = '<div class="jt"></div><div class="progress"><div></div></div><div class="uptext"></div>';
    jobsBox.appendChild(el);
    const set = (title, pct, text, err = false) => {
      el.querySelector('.jt').textContent = title;
      el.querySelector('.progress div').style.width = Math.round(pct * 100) + '%';
      const t = el.querySelector('.uptext');
      t.textContent = text;
      t.classList.toggle('err', err);
    };
    set(file.name, 0, T('wartet …'));
    running++;
    updateLoading();
    return { file, el, set, target, as, sync };
  }

  async function uploadJob(job) {
    const { file, set, target } = job;
    const CH = 8 * 1024 * 1024;
    const q = encodeURIComponent(file.name) + (target ? '&target=' + encodeURIComponent(target) : '') + (job.as ? '&as=' + encodeURIComponent(job.as) : '');
    try {
      for (let off = 0; off < file.size || off === 0; off += CH) {
        const res = await fetch(`upload.php?action=chunk&name=${q}&offset=${off}`, { method: 'POST', body: file.slice(off, off + CH) });
        const j = await res.json();
        if (!res.ok || j.error) throw new Error(j.error || res.statusText);
        const done = Math.min(file.size, off + CH);
        set(T('Upload: ') + file.name, file.size ? done / file.size : 1, T('{a} von {b}', { a: fmtBytes(done), b: fmtBytes(file.size) }));
        if (file.size === 0) break;
      }
      const fin = await (await fetch(`upload.php?action=finish&name=${q}&total=${file.size}`, { method: 'POST' })).json();
      if (fin.error) throw new Error(fin.error);
      const polled = pollJob(job, fin.dataset);
      if (job.sync) await polled;
    } catch (e) {
      set(T('Fehler: ') + file.name, 0, String(e.message || e), true);
      finishJob(job);
      await settle();
    }
  }

  async function pollJob(job, dataset) {
    const { file, set, target } = job;
    const q = encodeURIComponent(file.name) + (target ? '&target=' + encodeURIComponent(target) : '') + (job.as ? '&as=' + encodeURIComponent(job.as) : '');
    try {
      for (;;) {
        await sleep(700);
        const s = await (await fetch(`upload.php?action=status&name=${q}`)).json();
        if (s.phase === 'error') throw new Error(s.message || T('Import fehlgeschlagen'));
        const fmt = s.format ? ` · ${s.format}` : '';
        if (s.phase === 'done') {
          const extra = s.added !== undefined ? T('{a} neu, {b} Duplikate übersprungen, jetzt {c} Einträge', { a: nf.format(s.added), b: nf.format(s.dupes), c: nf.format(s.total) }) : T('{n} Zeilen importiert', { n: nf.format(s.lines) });
          set(T('Fertig: ') + file.name, 1, `${extra}${fmt} (${s.bad ? T('{n} nicht erkannt, ', { n: nf.format(s.bad) }) : ''}${s.seconds} s)`);
          break;
        }
        if (s.phase === 'queue') { set(T('Wartet: ') + file.name, 0, T('wartet auf den vorherigen Import …')); continue; }
        if (s.phase === 'merge') { set(T('Zusammenführen: ') + file.name, 1, T('{n} Zeilen werden mit „{t}“ abgeglichen …', { n: nf.format(s.lines), t: target || job.as })); continue; }
        if (s.phase === 'index') set(T('Indizes: ') + file.name, 1, `${T('{n} Zeilen', { n: nf.format(s.lines) })}${fmt}`);
        else set(T('Import: ') + file.name, s.pct || 0, T('{n} Zeilen', { n: nf.format(s.lines) }) + (s.pct ? ` · ${(s.pct * 100).toFixed(0)} %` : '') + fmt);
      }
      await loadDatasets();
      batch.sets.add(dataset);
      batch.last = dataset;
      setTimeout(() => job.el.remove(), 8000);
    } catch (e) {
      set(T('Fehler: ') + file.name, 0, String(e.message || e), true);
    } finally {
      finishJob(job);
      await settle();
    }
  }

  function addFiles(files, mode = { type: 'separate' }) {
    const list = [...files];
    if (!list.length) return;
    batch.auto = batch.auto || !addTarget;
    if (mode.type === 'merge' && !addTarget) {
      const as = mode.name;
      list.forEach((f, i) => {
        const job = i === 0 ? makeJob(f, null, as, list.length > 1) : makeJob(f, as);
        uploadChain = uploadChain.then(() => uploadJob(job));
      });
    } else {
      const taken = new Set(state.datasets.map((d) => d.name.toLowerCase()));
      list.forEach((f) => {
        const base = f.name.replace(/\.gz$/i, '').replace(/\.(log|txt|out)$/i, '').replace(/[^A-Za-z0-9._-]+/g, '_') || 'log';
        let nm = base, n = 2;
        while (taken.has(nm.toLowerCase())) nm = `${base}-${n++}`;
        taken.add(nm.toLowerCase());
        const job = addTarget ? makeJob(f) : makeJob(f, null, nm);
        uploadChain = uploadChain.then(() => uploadJob(job));
      });
    }
    addTarget = null;
  }

  function askOneTab(list) {
    return new Promise((resolve) => {
      const base = list[0].name.replace(/\.gz$/i, '').replace(/\.[^.]+$/, '').replace(/[-_.]?\d{4}[-_.]?\d{2}[-_.]?\d{2}.*$/, '') || T('zusammen');
      const opts = state.datasets.map((d) => `<option value="${esc(d.name)}"${d.name === state.ds ? ' selected' : ''}>${esc(d.name)}</option>`).join('');
      const ov = document.createElement('div');
      ov.className = 'modal-bg';
      ov.innerHTML = `<div class="modal card" role="dialog" aria-modal="true">
        <h2>${T('{n} {w} in einen Tab laden', { n: list.length, w: list.length === 1 ? T('Datei') : T('Dateien') })}</h2>
        <label class="nm">${T('Ziel')} <select id="mtarget"><option value="">${T('Neuer Tab …')}</option>${opts}</select></label>
        <label class="nm" id="mnamewrap">${T('Name des neuen Tabs')} <input type="text" id="mname" value="${esc(base)}"></label>
        <small class="mut">${T('Alle Dateien landen in einem Datensatz. Duplikate entfallen, die Zeilen werden zeitlich sortiert.')}</small>
        <div class="btns"><button type="button" data-x="cancel">${T('Abbrechen')}</button><button type="button" class="primary" data-x="ok">${T('Laden')}</button></div>
      </div>`;
      document.body.appendChild(ov);
      const sel = ov.querySelector('#mtarget'), name = ov.querySelector('#mname'), wrap = ov.querySelector('#mnamewrap');
      const sync = () => { wrap.style.display = sel.value ? 'none' : ''; };
      sel.addEventListener('change', sync); sync();
      const close = (v) => { ov.remove(); resolve(v); };
      ov.addEventListener('click', (e) => {
        const x = e.target.dataset && e.target.dataset.x;
        if (e.target === ov || x === 'cancel') close(null);
        else if (x === 'ok') close(sel.value ? { type: 'existing', target: sel.value } : { type: 'merge', name: name.value.trim() || base });
      });
    });
  }

  let oneTab = false;
  $('#append').addEventListener('click', () => { oneTab = true; addTarget = null; $('#file').click(); });
  $('#pick').addEventListener('click', () => { oneTab = false; addTarget = null; $('#file').click(); });
  async function handleFiles(list) {
    list = [...list];
    if (!list.length) return;
    if (oneTab && !addTarget) {
      oneTab = false;
      const mode = await askOneTab(list);
      if (!mode) return;
      if (mode.type === 'existing') addTarget = mode.target;
      addFiles(list, mode);
    } else addFiles(list);
  }
  $('#file').addEventListener('change', (e) => { const l = [...e.target.files]; e.target.value = ''; handleFiles(l); });
  let dragDepth = 0;
  addEventListener('dragenter', (e) => { if (e.dataTransfer?.types.includes('Files')) { dragDepth++; document.body.classList.add('dragging'); } });
  addEventListener('dragleave', () => { if (--dragDepth <= 0) { dragDepth = 0; document.body.classList.remove('dragging'); } });
  addEventListener('dragover', (e) => e.preventDefault());
  addEventListener('drop', (e) => { e.preventDefault(); dragDepth = 0; document.body.classList.remove('dragging'); addTarget = null; handleFiles(e.dataTransfer.files); });

  /* ---------- Filter & Tabelle ---------- */
  $('#filters').addEventListener('input', (e) => { if (e.target.tagName === 'INPUT') refreshSoon(); });
  $('#filters').addEventListener('change', (e) => { if (e.target.tagName === 'SELECT') refresh(); });
  $('#filters').addEventListener('click', (e) => { if (e.target.id === 'reset') { clearFilters(); refresh(); } });
  $('#filters').addEventListener('dblclick', (e) => {
    const el = e.target.closest('input, select');
    if (!el || !el.value) return;
    el.value = '';
    refresh();
  });
  $('#tlBack').addEventListener('click', () => {
    const t = zoomStack.pop();
    if (!t || t.ds !== state.ds) { updateBack(); return; }
    const f = fieldEl('from'), o = fieldEl('to');
    if (f) f.value = t.from;
    if (o) o.value = t.to;
    refresh();
  });
  $('#more').addEventListener('click', () => loadRows(false, gen, controller?.signal));

  const selPend = {};
  const markPending = () => {
    document.querySelectorAll('.top-row[data-field]').forEach((r) => {
      const p = selPend[r.dataset.field];
      const was = selectedOf(r.dataset.field).has(r.dataset.value);
      const now = p ? p.includes(r.dataset.value) : was;
      r.classList.toggle('pend', !!p && now && !was);
      r.classList.toggle('pend-off', !!p && !now && was);
    });
  };
  const applyPending = () => {
    const keys = Object.keys(selPend);
    if (!keys.length) return;
    keys.forEach((k) => { const el = fieldEl(k); if (el) setField(el, selPend[k].join(',')); delete selPend[k]; });
    refresh();
  };
  window.addEventListener('keyup', (e) => { if (e.key === 'Shift') applyPending(); });
  window.addEventListener('blur', applyPending);

  document.addEventListener('mousedown', (e) => { if (e.shiftKey && e.target.closest('.top-row')) e.preventDefault(); });
  document.addEventListener('click', (e) => {
    const row = e.target.closest('.top-row');
    if (row) {
      const el = row.dataset.field ? fieldEl(row.dataset.field) : null;
      if (el) {
        const multi = e.shiftKey && isMulti(row.dataset.field);
        const val = row.dataset.value;
        const cur = el.value.split(',').map((s) => s.trim()).filter(Boolean);
        if (multi) {
          const base = selPend[row.dataset.field] || cur.slice();
          const i = base.indexOf(val);
          if (i >= 0) base.splice(i, 1); else base.push(val);
          selPend[row.dataset.field] = base;
          markPending();
          return;
        }
        setField(el, cur.length === 1 && cur[0] === val ? '' : val);
        refresh();
      }
      return;
    }
    const ip = e.target.closest('.ip');
    if (ip) { const el = fieldEl('ip'); if (el) { el.value = el.value === ip.dataset.ip ? '' : ip.dataset.ip; refresh(); } return; }
    const code = e.target.closest('.code');
    if (code) { const el = fieldEl('code'); if (el) { el.value = el.value === code.dataset.code ? '' : code.dataset.code; refresh(); } return; }
    const tr = e.target.closest('tr.r');
    if (tr) {
      const next = tr.nextElementSibling;
      if (next && next.classList.contains('detail')) { next.remove(); return; }
      const r = rowCache.get(tr.dataset.id);
      if (r) tr.insertAdjacentHTML('afterend', `<tr class="detail"><td colspan="${cfg().cols.length}">${cfg().detail(r)}</td></tr>`);
    }
  });

  applyMode(root.dataset.mode || 'system', false);
  init();
})();
