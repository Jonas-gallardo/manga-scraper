<?php require_once __DIR__ . '/config.php'; ?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Comic Scraper Pro</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono&display=swap" rel="stylesheet">
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="assets/css/app.css">
  <link rel="icon" type="image/svg+xml" href="favicon.svg">
  <link rel="icon" type="image/png" href="favicon.png">
  <link rel="apple-touch-icon" href="favicon-180.png">
  <style>
    /* ── Tailwind Extensions ── */
    .hidden { display: none !important; }
    .grid-cols-2  { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .grid-cols-3  { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    .grid-cols-4  { grid-template-columns: repeat(4, minmax(0, 1fr)); }
    .grid-cols-5  { grid-template-columns: repeat(5, minmax(0, 1fr)); }
    .grid-cols-6  { grid-template-columns: repeat(6, minmax(0, 1fr)); }
    .gap-2 { gap: 0.5rem; }
    .gap-3 { gap: 0.75rem; }
    .gap-4 { gap: 1rem; }
    .gap-6 { gap: 1.5rem; }
    .mb-2 { margin-bottom: 0.5rem; }
    .mb-3 { margin-bottom: 0.75rem; }
    .mb-4 { margin-bottom: 1rem; }
    .mb-6 { margin-bottom: 1.5rem; }
    .mt-4 { margin-top: 1rem; }
    .mt-6 { margin-top: 1.5rem; }
    .p-4 { padding: 1rem; }
    .p-6 { padding: 1.5rem; }
    .px-4 { padding-left: 1rem; padding-right: 1rem; }
    .py-2 { padding-top: 0.5rem; padding-bottom: 0.5rem; }
    .flex { display: flex; }
    .flex-1 { flex: 1; }
    .flex-wrap { flex-wrap: wrap; }
    .items-center { align-items: center; }
    .justify-between { justify-content: space-between; }
    .justify-center { justify-content: center; }
    .text-center { text-align: center; }
    .text-right { text-align: right; }
    .font-semibold { font-weight: 600; }
    .text-slate-300 { color: #cbd5e1; }
    .text-slate-400 { color: #94a3b8; }
    .text-slate-500 { color: #64748b; }
    .text-slate-600 { color: #475569; }
    .text-slate-700 { color: #334155; }
    .tracking-wide { letter-spacing: 0.025em; }
    .uppercase { text-transform: uppercase; }
    .truncate { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .w-10 { width: 2.5rem; }
    .w-24 { width: 6rem; }
    .w-40 { width: 10rem; }
    .h-2 { height: 0.5rem; }
    .rounded-full { border-radius: 9999px; }
    .overflow-hidden { overflow: hidden; }
    @media (min-width: 768px) {
      .md\:grid-cols-3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
      .md\:grid-cols-4 { grid-template-columns: repeat(4, minmax(0, 1fr)); }
    }
  </style>
</head>
<body>

  <!-- ── Background ── -->
  <div class="bg-app"></div>

  <!-- ── Main Container ── -->
  <div class="max-w-7xl mx-auto px-4 py-6">

    <!-- Header -->
    <header class="text-center mb-8">
      <h1 class="text-4xl font-bold glow-text" style="font-size:2.5rem;">Comic Scraper Pro</h1>
      <p class="text-sm text-slate-500 mt-2">Descarga y gestiona cómics desde 3hentai.net</p>
    </header>

    <!-- Navigation Tabs -->
    <nav class="glass-strong flex flex-wrap justify-center gap-1 px-4 py-2 mb-6" style="border-radius:14px;">
      <button class="nav-tab active" data-tab="download">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        Descargar
      </button>
      <button class="nav-tab" data-tab="gallery">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
        Galería
      </button>
      <button class="nav-tab" data-tab="dashboard">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 20V10"/><path d="M12 20V4"/><path d="M6 20v-6"/></svg>
        Dashboard
      </button>
      <button class="nav-tab" data-tab="dictionary">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 016.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15A2.5 2.5 0 016.5 2z"/></svg>
        Diccionario
      </button>
      <button class="nav-tab" id="settingsOpen">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>
        Configuración
      </button>
    </nav>

    <!-- ════════════════════════════════════════════════════════════ -->
    <!-- TAB: Download -->
    <!-- ════════════════════════════════════════════════════════════ -->
    <div id="tab-download" class="tab-content active">

      <!-- Mode Tabs -->
      <div class="flex flex-wrap gap-2 mb-4 justify-center">
        <button class="mode-tab active" data-mode="single">📄 Cómic Individual</button>
        <button class="mode-tab" data-mode="batch">📚 Universo / Batch</button>
      </div>

      <!-- Download Form -->
      <div class="glass-strong p-6 mb-6">
        <form id="downloadForm">
          <div id="mode-single" class="mode-container active"></div>
          <div class="mb-3">
            <label class="text-xs text-slate-400 font-semibold uppercase tracking-wide mb-1 block">URL</label>
            <input type="url" id="url" class="glass-input" placeholder="https://3hentai.net/d/123456/" required>
          </div>
          <div id="mode-batch" class="mode-container">
            <div class="form-row">
              <div class="form-group">
                <label>Página Inicial</label>
                <input type="number" id="batchStart" class="glass-input" value="1" min="1">
                <div class="hint">Desde qué página empezar</div>
              </div>
              <div class="form-group">
                <label>Máximo Cómics</label>
                <input type="number" id="batchMax" class="glass-input" value="0" min="0">
                <div class="hint">0 = sin límite</div>
              </div>
            </div>
          </div>
          <p id="hint" class="text-xs text-slate-500 mb-3">Pega el enlace de 3hentai (ej: https://3hentai.net/d/123456/)</p>
          <div class="flex flex-wrap items-center gap-3">
            <button type="submit" id="downloadBtn" class="btn-glow">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
              Descargar
            </button>
            <button type="button" id="stopBtn" class="btn-glow btn-danger hidden">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><rect x="6" y="6" width="12" height="12" rx="2"/></svg>
              Detener
            </button>
          </div>
        </form>
      </div>

      <!-- Progress Bar -->
      <div id="progressWrap" class="glass-strong p-4 mb-4 hidden">
        <div class="flex items-center justify-between mb-2">
          <span class="text-xs font-semibold text-slate-400 uppercase tracking-wide">Progreso</span>
          <span id="progressPct" class="text-xs font-bold text-slate-300">0%</span>
        </div>
        <div class="progress-track">
          <div id="progressFill" class="progress-fill" style="width:0%;"></div>
        </div>
        <div class="flex justify-between mt-2">
          <span id="progressLabel" class="text-xs text-slate-500">0 / 0</span>
        </div>
      </div>

      <!-- Log Console -->
      <div class="glass-strong p-4 mb-6">
        <div class="flex items-center justify-between mb-2">
          <span class="text-xs font-semibold text-slate-400 uppercase tracking-wide">Consola</span>
          <span class="text-xs text-slate-600">log</span>
        </div>
        <div id="log"></div>
      </div>

      <!-- Batch History -->
      <div class="glass-strong p-4 mb-6">
        <div class="flex items-center justify-between flex-wrap gap-3 mb-4">
          <h3 class="text-sm font-semibold text-slate-300 uppercase tracking-wide">📋 Historial de Batch</h3>
          <div>
            <input type="text" id="bhSearch" class="glass-input" placeholder="Buscar..." style="width:200px;max-width:100%;padding:6px 10px;font-size:.78rem;">
          </div>
        </div>
        <div class="table-wrap">
          <table id="bhTable">
            <thead>
              <tr>
                <th>Fecha</th>
                <th>Universo / URL</th>
                <th>Progreso</th>
                <th>Estado</th>
                <th>Acción</th>
              </tr>
            </thead>
            <tbody id="bhBody">
              <tr><td colspan="5" class="text-center text-sm text-slate-500" style="padding:20px;">Cargando...</td></tr>
            </tbody>
          </table>
        </div>
        <div id="bhPagination" class="mt-4"></div>
      </div>
    </div>

    <!-- ════════════════════════════════════════════════════════════ -->
    <!-- TAB: Gallery -->
    <!-- ════════════════════════════════════════════════════════════ -->
    <div id="tab-gallery" class="tab-content">
      <div class="glass-strong p-4 mb-6">
        <div class="flex flex-wrap items-end gap-3 mb-4">
          <div class="flex-1" style="min-width:160px;">
            <label class="text-xs text-slate-500 font-semibold uppercase tracking-wide mb-1 block">Buscar</label>
            <input type="text" id="gallerySearch" class="glass-input" placeholder="Título..." style="padding:6px 10px;font-size:.78rem;">
          </div>
          <div style="min-width:130px;">
            <label class="text-xs text-slate-500 font-semibold uppercase tracking-wide mb-1 block">Universo</label>
            <select id="filterUniverse" class="glass-input" style="padding:6px 10px;font-size:.78rem;">
              <option value="">Todos</option>
            </select>
          </div>
          <div style="min-width:110px;">
            <label class="text-xs text-slate-500 font-semibold uppercase tracking-wide mb-1 block">Estado</label>
            <select id="filterEstado" class="glass-input" style="padding:6px 10px;font-size:.78rem;">
              <option value="">Todos</option>
              <option value="completo">Completo</option>
              <option value="parcial">Parcial</option>
              <option value="error">Error</option>
            </select>
          </div>
          <div style="min-width:120px;">
            <label class="text-xs text-slate-500 font-semibold uppercase tracking-wide mb-1 block">Desde</label>
            <input type="date" id="filterDateFrom" class="glass-input" style="padding:6px 10px;font-size:.78rem;">
          </div>
          <div style="min-width:120px;">
            <label class="text-xs text-slate-500 font-semibold uppercase tracking-wide mb-1 block">Hasta</label>
            <input type="date" id="filterDateTo" class="glass-input" style="padding:6px 10px;font-size:.78rem;">
          </div>
          <div style="min-width:110px;">
            <label class="text-xs text-slate-500 font-semibold uppercase tracking-wide mb-1 block">Ordenar</label>
            <select id="filterSort" class="glass-input" style="padding:6px 10px;font-size:.78rem;">
              <option value="fecha_desc">Más reciente</option>
              <option value="fecha_asc">Más antiguo</option>
              <option value="titulo_asc">Título A-Z</option>
              <option value="titulo_desc">Título Z-A</option>
            </select>
          </div>
        </div>
      </div>

      <!-- Gallery Grid -->
      <div id="galleryGrid" class="grid grid-cols-2 md:grid-cols-4 gap-4"></div>

      <!-- Loading -->
      <div id="galleryLoading" class="hidden text-center py-8">
        <div class="spinner" style="width:32px;height:32px;"></div>
        <p class="text-sm text-slate-500 mt-3">Cargando galería...</p>
      </div>

      <!-- Empty State -->
      <div id="galleryEmpty" class="empty-state hidden">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
        <p>No hay cómics disponibles.</p>
      </div>

      <!-- Gallery Pagination -->
      <div id="galleryPagination" class="mt-6"></div>
    </div>

    <!-- ════════════════════════════════════════════════════════════ -->
    <!-- TAB: Dashboard -->
    <!-- ════════════════════════════════════════════════════════════ -->
    <div id="tab-dashboard" class="tab-content">
      <div id="dashboardContent"></div>
    </div>

    <!-- ════════════════════════════════════════════════════════════ -->
    <!-- TAB: Dictionary -->
    <!-- ════════════════════════════════════════════════════════════ -->
    <div id="tab-dictionary" class="tab-content">
      <div class="glass-strong" style="overflow:hidden;border-radius:16px;">
        <iframe src="dictionary.php" style="width:100%;height:75vh;border:none;" title="Diccionario de Taxonomías"></iframe>
      </div>
    </div>

    <!-- Footer -->
    <footer class="text-center mt-8 mb-4">
      <p class="text-xs text-slate-600">Comic Scraper Pro &mdash; Gestiona tus descargas de 3hentai.net</p>
    </footer>
  </div>

  <!-- ════════════════════════════════════════════════════════════════ -->
  <!-- MODAL: Viewer -->
  <!-- ════════════════════════════════════════════════════════════════ -->
  <div id="viewerModal" class="viewer-modal">
    <div class="viewer-header">
      <h3 id="viewerTitle">Visor</h3>
      <div class="flex items-center gap-3">
        <span id="viewerPageInfo" class="text-xs text-slate-400"></span>
        <button id="viewerClose" class="modal-close">&times;</button>
      </div>
    </div>
    <div class="viewer-body">
      <button id="viewerPrev" class="viewer-nav prev">‹</button>
      <img id="viewerImage" src="" alt="Page">
      <button id="viewerNext" class="viewer-nav next">›</button>
      <div class="viewer-zoom">
        <button id="zoomOut">−</button>
        <span id="zoomLevel">Ajustar</span>
        <button id="zoomIn">+</button>
        <button id="zoomReset">⟲</button>
        <button id="zoomFit">⊞</button>
      </div>
    </div>
  </div>

  <!-- ════════════════════════════════════════════════════════════════ -->
  <!-- MODAL: Settings -->
  <!-- ════════════════════════════════════════════════════════════════ -->
  <div id="settingsModal" class="modal-overlay">
    <div class="modal-content">
      <div class="modal-header">
        <h2>⚙️ Configuración</h2>
        <button id="settingsClose" class="modal-close">&times;</button>
      </div>
      <div class="modal-body">
        <form id="settingsForm">
          <h4 class="text-xs text-slate-400 font-semibold uppercase tracking-wide mb-3">Base de Datos</h4>
          <div class="form-row">
            <div class="form-group">
              <label for="cfg_db_host">Host</label>
              <input type="text" id="cfg_db_host" class="glass-input" placeholder="localhost">
            </div>
            <div class="form-group">
              <label for="cfg_db_name">Base de Datos</label>
              <input type="text" id="cfg_db_name" class="glass-input" placeholder="scrap">
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label for="cfg_db_user">Usuario</label>
              <input type="text" id="cfg_db_user" class="glass-input" placeholder="root">
            </div>
            <div class="form-group">
              <label for="cfg_db_pass">Contraseña</label>
              <input type="password" id="cfg_db_pass" class="glass-input" placeholder="••••••••">
            </div>
          </div>

          <hr>

          <h4 class="text-xs text-slate-400 font-semibold uppercase tracking-wide mb-3">Sitio</h4>
          <div class="form-group">
            <label for="cfg_site_url">URL del Sitio</label>
            <input type="text" id="cfg_site_url" class="glass-input" placeholder="http://localhost/scrap">
            <div class="hint">URL base de la aplicación</div>
          </div>
          <div class="form-group">
            <label for="cfg_download_path">Ruta de Descargas</label>
            <input type="text" id="cfg_download_path" class="glass-input" placeholder="/var/www/html/downloads">
            <div class="hint">Directorio donde se guardan los cómics</div>
          </div>

          <hr>

          <h4 class="text-xs text-slate-400 font-semibold uppercase tracking-wide mb-3">Anti-Ban</h4>
          <div class="form-row">
            <div class="form-group">
              <label for="cfg_min_delay">Delay Mínimo (ms)</label>
              <input type="number" id="cfg_min_delay" class="glass-input" value="2000" min="100">
            </div>
            <div class="form-group">
              <label for="cfg_max_delay">Delay Máximo (ms)</label>
              <input type="number" id="cfg_max_delay" class="glass-input" value="5000" min="100">
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label for="cfg_max_retries">Máx. Reintentos</label>
              <input type="number" id="cfg_max_retries" class="glass-input" value="3" min="0">
            </div>
            <div class="form-group">
              <label for="cfg_timeout">Timeout (s)</label>
              <input type="number" id="cfg_timeout" class="glass-input" value="30" min="5">
            </div>
          </div>
          <div class="form-group">
            <label for="cfg_webp_quality">Calidad WebP (1-100)</label>
            <input type="range" id="cfg_webp_quality" min="1" max="100" value="85">
            <div class="flex justify-between text-xs text-slate-600"><span>1</span><span id="webpQualityLabel">85</span><span>100</span></div>
          </div>

          <hr>

          <div class="flex items-center justify-between">
            <span id="settingsStatus" class="text-xs text-slate-500"></span>
            <button type="submit" class="btn-glow">💾 Guardar</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- ════════════════════════════════════════════════════════════════ -->
  <!-- MODAL: Taxonomy -->
  <!-- ════════════════════════════════════════════════════════════════ -->
  <div id="taxModal" class="modal-overlay">
    <div class="modal-content" style="max-width:480px;">
      <div class="modal-header">
        <h2>🏷️ Taxonomías</h2>
        <button id="taxClose" class="modal-close">&times;</button>
      </div>
      <div class="modal-body" id="taxContent">
        <p class="text-sm text-slate-500">Cargando...</p>
      </div>
    </div>
  </div>

  <!-- ════════════════════════════════════════════════════════════════ -->
  <!-- JavaScript -->
  <!-- ════════════════════════════════════════════════════════════════ -->
  <script>
    // ── PHP Config Injection ──
    window._SITE_VIEW_PATH  = '<?php echo defined('SITE_VIEW_PATH') ? SITE_VIEW_PATH : '/view'; ?>';
    window._SITE_BATCH_PATH = '<?php echo defined('SITE_BATCH_PATH') ? SITE_BATCH_PATH : '/parody'; ?>';

    // ── WebP Quality Range Label ──
    document.addEventListener('DOMContentLoaded', function() {
      const qRange = document.getElementById('cfg_webp_quality');
      const qLabel = document.getElementById('webpQualityLabel');
      if (qRange && qLabel) {
        qLabel.textContent = qRange.value;
        qRange.addEventListener('input', function() {
          qLabel.textContent = this.value;
        });
      }
    });
  </script>
  <script src="assets/js/api.js"></script>
  <script src="assets/js/app.js"></script>
</body>
</html>
