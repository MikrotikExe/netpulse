<?php require __DIR__ . '/auth.php'; require_login(); ?>
<!doctype html>
<html lang="sk"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars(cfg('APP_NAME') ?: 'NetPulse') ?></title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<script>try{document.documentElement.dataset.theme=localStorage.getItem('np-theme')||'auto';}catch(e){document.documentElement.dataset.theme='auto';}</script>
<link rel="stylesheet" href="assets/style.css?v=<?= @filemtime(__DIR__.'/assets/style.css') ?>">
</head>
<body>
<header id="topbar">
  <div class="brand"><img src="favicon.svg" class="logo" alt=""><span class="brand-dude"><?= htmlspecialchars(cfg('APP_NAME') ?: 'NetPulse') ?></span></div>
  <div id="summary" class="summary"></div>
  <div class="top-right">
    <button id="theme-toggle" class="theme-btn" title="Prepnúť tému">🌙</button>
    <span class="user"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21a8 8 0 0 0-16 0"/><circle cx="12" cy="7" r="4"/></svg> <?= htmlspecialchars(current_user()) ?></span>
    <a class="logout" href="logout.php">Odhlásiť</a>
  </div>
</header>

<div id="app">
  <nav id="sections">
    <button data-sec="maps" class="active"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m3 6 6-3 6 3 6-3v15l-6 3-6-3-6 3z"/><path d="M9 3v15M15 6v15"/></svg><span>Mapy</span></button>
    <button data-sec="devices"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg><span>Zariadenia</span></button>
    <button data-sec="services"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg><span>Služby</span></button>
    <button data-sec="faults"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/></svg><span>Poruchy</span></button>
    <button data-sec="events"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg><span>Udalosti</span></button>
    <button data-sec="graphs"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><rect x="7" y="11" width="3" height="7" rx="0.5"/><rect x="12" y="7" width="3" height="11" rx="0.5"/><rect x="17" y="13" width="3" height="5" rx="0.5"/></svg><span>Grafy</span></button>
    <button data-sec="settings"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg><span>Nastavenia</span></button>
  </nav>

  <aside id="sidebar">
    <div class="side-head"><span id="side-title">Mapy</span>
      <button id="btn-add-map" class="edit-only" title="Nová mapa">＋</button></div>
    <ul id="map-list"></ul>
  </aside>

  <main id="main">
    <!-- MAPY -->
    <section id="view-maps" class="view active">
      <div id="map-tools" class="tools">
        <button id="btn-maps-toggle" title="Zoznam máp"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M3 12h18M3 18h18"/></svg></button>
        <button id="btn-add-node" class="edit-only"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg><span>Uzol</span></button>
        <button id="btn-new-device" class="edit-only"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg><span>Zariadenie</span></button>
        <button id="btn-link" class="edit-only">🔗 Spoj</button>
        <button id="btn-delete" class="edit-only">🗑️ Zmazať</button>
        <button id="btn-refresh">↻ Obnoviť</button>
        <span id="mode" class="mode"></span>
      </div>
      <div id="canvas-wrap">
        <svg id="canvas" xmlns="http://www.w3.org/2000/svg"></svg>
        <div id="tooltip" class="tooltip"></div>
      </div>
    </section>
    <!-- TABUĽKOVÉ SEKCIE -->
    <section id="view-devices" class="view"><div class="table-wrap"><table id="tbl-devices"></table></div></section>
    <section id="view-services" class="view"><div class="table-wrap"><table id="tbl-services"></table></div></section>
    <section id="view-faults" class="view"><div class="table-wrap"><table id="tbl-faults"></table></div></section>
    <section id="view-events" class="view"><div class="table-wrap"><table id="tbl-events"></table></div></section>
    <section id="view-graphs" class="view">
      <div class="tools">
        <span style="color:var(--muted);font-size:13px">Zdroj:</span>
        <select id="graph-src" style="min-width:220px"></select>
        <span class="sep"></span>
        <div id="graph-ranges" class="range-btns">
          <button data-r="hour" class="active">Hodina</button>
          <button data-r="day">Deň</button>
          <button data-r="week">Týždeň</button>
          <button data-r="month">Mesiac</button>
          <button data-r="year">Rok</button>
        </div>
      </div>
      <div class="graph-wrap"><canvas id="graph-canvas"></canvas><div id="graph-empty" class="empty"></div></div>
    </section>
    <section id="view-settings" class="view"><div id="settings-body"></div></section>
  </main>

  <aside id="inspector" class="hidden">
    <div class="insp-head"><span id="insp-title">Detail</span>
      <button id="insp-close">✕</button></div>
    <div id="insp-body"></div>
    <div class="insp-alerts">
      <div class="insp-alerts-head">⚠️ Aktívne poruchy</div>
      <ul id="alert-list"></ul>
    </div>
  </aside>
</div>

<!-- MODÁL: okno zariadenia -->
<div id="dlg-device" class="modal hidden">
  <div class="modal-card">
    <div class="modal-head"><span id="dlg-title">Zariadenie</span><button id="dlg-close">✕</button></div>
    <div class="tabs">
      <button data-tab="obecne" class="active">Obecné</button>
      <button data-tab="sluzby">Služby</button>
      <button data-tab="poruchy">Poruchy</button>
      <button data-tab="historia">História</button>
      <button data-tab="snmp">SNMP</button>
      <button data-tab="nastroje">Nástroje</button>
    </div>
    <div class="modal-body">
      <div class="tab-pane active" id="tab-obecne"></div>
      <div class="tab-pane" id="tab-sluzby"></div>
      <div class="tab-pane" id="tab-poruchy"></div>
      <div class="tab-pane" id="tab-historia"><canvas id="rtt-chart" width="620" height="260"></canvas><div id="hist-empty" class="empty"></div></div>
      <div class="tab-pane" id="tab-snmp"></div>
      <div class="tab-pane" id="tab-nastroje"></div>
    </div>
  </div>
</div>


<!-- MODÁL: upraviť spoj -->
<div id="dlg-link" class="modal hidden">
  <div class="modal-card small">
    <div class="modal-head"><span>Upraviť spoj</span><button id="link-close">✕</button></div>
    <div class="modal-body">
      <label>Typ spoja<select id="link-type"></select></label>
      <label>Meranie toku – zariadenie (SNMP)<select id="link-dev"><option value="">— žiadne —</option></select></label>
      <label>Rozhranie
        <div class="row-inline"><select id="link-if" style="flex:1"><option value="">—</option></select>
        <button class="btn ghost" id="link-if-load" type="button">Načítať</button></div></label>
      <div id="link-msg" style="font-size:12px;margin:2px 0 6px"></div>
      <div class="modal-actions">
        <button class="btn danger" id="link-del">Zmazať</button>
        <button class="btn" id="link-save">Uložiť</button>
      </div>
    </div>
  </div>
</div>

<!-- MODÁL: pridať zariadenie -->
<div id="dlg-add" class="modal hidden">
  <div class="modal-card small">
    <div class="modal-head"><span>Pridať zariadenie</span><button id="add-close">✕</button></div>
    <div class="modal-body">
      <label>Adresa (IP alebo DNS)<input id="add-ip"></label>
      <label>Názov<input id="add-name"></label>
      <label>Typ zariadenia<select id="add-type"></select></label>
      <label>Používateľské meno<input id="add-user" value="admin"></label>
      <label>Heslo<input id="add-pass" type="password"></label>
      <label class="chk"><input type="checkbox" id="add-ros"> RouterOS</label>
      <div class="modal-actions"><button id="add-save">Pridať</button></div>
      <div id="add-msg"></div>
    </div>
  </div>
</div>

<script src="assets/app.js?v=<?= @filemtime(__DIR__.'/assets/app.js') ?>"></script>
</body></html>
