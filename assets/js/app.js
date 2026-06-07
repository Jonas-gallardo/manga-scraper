/**
 * Comic Scraper Pro — Main Application Module
 * 
 * UI logic: tabs, log, scraping engine, gallery, viewer,
 * dashboard, batch history, settings, taxonomy modal.
 */
(function() {
  'use strict';

  // ════════════════════════════════════════════════════════════════
  // DOM References
  // ════════════════════════════════════════════════════════════════

  const form           = document.getElementById('downloadForm');
  const urlInput       = document.getElementById('url');
  const batchStart     = document.getElementById('batchStart');
  const batchMax       = document.getElementById('batchMax');
  const downloadBtn    = document.getElementById('downloadBtn');
  const stopBtn        = document.getElementById('stopBtn');
  const logDiv         = document.getElementById('log');
  const progressWrap   = document.getElementById('progressWrap');
  const progressFill   = document.getElementById('progressFill');
  const progressLabel  = document.getElementById('progressLabel');
  const progressPct    = document.getElementById('progressPct');

  const galleryGrid    = document.getElementById('galleryGrid');
  const galleryEmpty   = document.getElementById('galleryEmpty');
  const galleryLoading = document.getElementById('galleryLoading');
  const galleryPagination = document.getElementById('galleryPagination');

  const bhTable        = document.getElementById('bhTable');
  const bhBody         = document.getElementById('bhBody');
  const bhPagination   = document.getElementById('bhPagination');
  const bhSearch       = document.getElementById('bhSearch');

  const viewerModal    = document.getElementById('viewerModal');
  const viewerTitle    = document.getElementById('viewerTitle');
  const viewerPageInfo = document.getElementById('viewerPageInfo');
  const viewerImage    = document.getElementById('viewerImage');
  const viewerPrev     = document.getElementById('viewerPrev');
  const viewerNext     = document.getElementById('viewerNext');
  const viewerClose    = document.getElementById('viewerClose');
  const zoomIn         = document.getElementById('zoomIn');
  const zoomOut        = document.getElementById('zoomOut');
  const zoomReset      = document.getElementById('zoomReset');
  const zoomFit        = document.getElementById('zoomFit');
  const zoomLevel      = document.getElementById('zoomLevel');

  const settingsModal  = document.getElementById('settingsModal');
  const settingsOpen   = document.getElementById('settingsOpen');
  const settingsClose  = document.getElementById('settingsClose');
  const settingsForm   = document.getElementById('settingsForm');
  const settingsStatus = document.getElementById('settingsStatus');

  const taxModal       = document.getElementById('taxModal');
  const taxContent     = document.getElementById('taxContent');
  const taxClose       = document.getElementById('taxClose');

  const navTabs        = document.querySelectorAll('.nav-tab');
  const tabContents    = document.querySelectorAll('.tab-content');
  const modeTabs       = document.querySelectorAll('.mode-tab');
  const modeContainers = document.querySelectorAll('.mode-container');

  const dashboardContent = document.getElementById('dashboardContent');

  // ════════════════════════════════════════════════════════════════
  // State
  // ════════════════════════════════════════════════════════════════

  let abortController = null;
  let isRunning       = false;

  // Viewer state
  let viewerPages    = [];
  let viewerCurrent  = 0;
  let viewerZoom     = 1;
  let viewerFit      = true;

  // Gallery pagination
  let galleryPage        = 1;
  let galleryTotalPages  = 1;

  // Batch history pagination
  let bhPage       = 1;
  let bhTotalPages = 1;

  // ════════════════════════════════════════════════════════════════
  // Hints Helper
  // ════════════════════════════════════════════════════════════════

  const HINTS = {
    single: 'Pega el enlace de 3hentai (ej: https://3hentai.net/d/123456/)',
    batch:  'Indica la URL base del batch (ej: https://3hentai.net/parody/naruto/)',
  };

  // ════════════════════════════════════════════════════════════════
  // Tab Switching
  // ════════════════════════════════════════════════════════════════

  navTabs.forEach(tab => {
    tab.addEventListener('click', function() {
      const target = this.dataset.tab;
      navTabs.forEach(t => t.classList.remove('active'));
      tabContents.forEach(c => c.classList.remove('active'));
      this.classList.add('active');
      const el = document.getElementById(`tab-${target}`);
      if (el) el.classList.add('active');

      if (target === 'gallery')   cargarGaleria();
      if (target === 'dashboard') cargarDashboard();
    });
  });

  // ════════════════════════════════════════════════════════════════
  // Mode Tabs (single / batch)
  // ════════════════════════════════════════════════════════════════

  modeTabs.forEach(tab => {
    tab.addEventListener('click', function() {
      const target = this.dataset.mode;
      modeTabs.forEach(t => t.classList.remove('active'));
      modeContainers.forEach(c => c.classList.remove('active'));
      this.classList.add('active');
      const el = document.getElementById(`mode-${target}`);
      if (el) el.classList.add('active');
      const hint = document.getElementById('hint');
      if (hint) hint.textContent = HINTS[target] || '';
    });
  });

  // ════════════════════════════════════════════════════════════════
  // Log System
  // ════════════════════════════════════════════════════════════════

  function appendToLog(message, type = 'info') {
    if (!logDiv) return;
    const line = document.createElement('div');
    line.className = type;
    line.textContent = message;
    logDiv.appendChild(line);
    logDiv.scrollTop = logDiv.scrollHeight;
  }

  function updateProgress(current, total) {
    if (!progressFill || !progressLabel || !progressPct || !progressWrap) return;
    if (total <= 0) {
      progressFill.style.width = '0%';
      progressLabel.textContent = '0 / 0';
      progressPct.textContent = '0%';
      return;
    }
    const pct = Math.min(Math.round((current / total) * 100), 100);
    progressFill.style.width = pct + '%';
    progressLabel.textContent = `${current} / ${total}`;
    progressPct.textContent = pct + '%';
    if (pct >= 100) {
      progressFill.style.background = 'linear-gradient(90deg, #34d399, #a78bfa)';
    } else {
      progressFill.style.background = '';
    }
  }

  function resetProgress() {
    if (!progressWrap) return;
    progressWrap.classList.add('hidden');
    updateProgress(0, 0);
  }

  function setRunning(running) {
    isRunning = running;
    if (downloadBtn) downloadBtn.disabled = running;
    if (stopBtn) stopBtn.classList.toggle('hidden', !running);
    if (urlInput) urlInput.disabled = running;
    if (batchStart) batchStart.disabled = running;
    if (batchMax) batchMax.disabled = running;
  }

  // ════════════════════════════════════════════════════════════════
  // Scraping Engine (SSE-like streaming)
  // ════════════════════════════════════════════════════════════════

  async function startScraping(formData) {
    appendToLog('Iniciando descarga...', 'progress');

    await ScrapAPI.startScraping(formData, {
      onLine: function(data) {
        const type = data.type || 'info';
        if (type === 'progress' && typeof data.current === 'number' && typeof data.total === 'number') {
          updateProgress(data.current, data.total);
        }
        if (data.message) {
          appendToLog(data.message, type);
        }
      },
      onComplete: function() {
        appendToLog('── Proceso completado ──', 'divider');
        appendToLog('¡Descarga finalizada!', 'done');
        setRunning(false);
        resetProgress();
        cargarHistorialBatch();
      },
      onError: function(err) {
        appendToLog(`Error: ${err.message}`, 'error');
        setRunning(false);
      },
    });
  }

  // ════════════════════════════════════════════════════════════════
  // Form Handler
  // ════════════════════════════════════════════════════════════════

  if (form) {
    form.addEventListener('submit', async function(e) {
      e.preventDefault();
      if (isRunning) return;

      const mode = document.querySelector('.mode-tab.active');
      const isBatch = mode && mode.dataset.mode === 'batch';
      const url = urlInput ? urlInput.value.trim() : '';

      // URL validation
      if (isBatch) {
        if (!url.startsWith('https://3hentai.net/')) {
          appendToLog('Error: URL de batch inválida. Debe comenzar con https://3hentai.net/', 'error');
          return;
        }
      } else {
        const singleRegex = /^https:\/\/3hentai\.net\/d\/(\d+)\/?$/;
        if (!singleRegex.test(url)) {
          appendToLog('Error: URL inválida. Usa formato: https://3hentai.net/d/123456/', 'error');
          return;
        }
      }

      const formData = new FormData();
      formData.append('url', url);
      formData.append('type', isBatch ? 'batch' : 'single');

      if (isBatch) {
        formData.append('start_page', batchStart ? batchStart.value : '1');
        formData.append('max_comics', batchMax ? batchMax.value : '0');
      }

      logDiv.innerHTML = '';
      progressWrap.classList.remove('hidden');
      updateProgress(0, 0);
      setRunning(true);
      appendToLog(`Modo: ${isBatch ? 'Batch' : 'Individual'}`, 'info');
      appendToLog(`URL: ${url}`, 'info');

      try {
        await startScraping(formData);
      } catch (err) {
        appendToLog(`Error: ${err.message}`, 'error');
        setRunning(false);
      }
    });
  }

  // ════════════════════════════════════════════════════════════════
  // Stop Scraper
  // ════════════════════════════════════════════════════════════════

  if (stopBtn) {
    stopBtn.addEventListener('click', async function() {
      appendToLog('Deteniendo...', 'warning');
      try {
        await ScrapAPI.stopScraping();
        appendToLog('Descarga detenida por el usuario.', 'warning');
        setRunning(false);
      } catch (err) {
        appendToLog(`Error al detener: ${err.message}`, 'error');
      }
    });
  }

  // ════════════════════════════════════════════════════════════════
  // Initial Log Messages
  // ════════════════════════════════════════════════════════════════

  appendToLog('Sistema listo. Ingresa una URL y presiona Descargar.', 'info');
  appendToLog('Usa el modo Batch para descargar múltiples cómics de una serie.', 'info');

  // ════════════════════════════════════════════════════════════════
  // Batch History
  // ════════════════════════════════════════════════════════════════

  async function cargarHistorialBatch(page) {
    bhPage = (page && page > 0) ? page : bhPage;
    const search = bhSearch ? bhSearch.value.trim() : '';

    try {
      const data = await ScrapAPI.fetchBatchHistory(bhPage, search);
      if (!bhBody) return;

      bhBody.innerHTML = '';

      if (data.error) {
        bhBody.innerHTML = `<tr><td colspan="5" class="text-muted text-sm" style="text-align:center;padding:20px;">${data.error}</td></tr>`;
        return;
      }

      const history = data.data || [];
      if (history.length === 0) {
        bhBody.innerHTML = '<tr><td colspan="5" class="text-muted text-sm" style="text-align:center;padding:20px;">Sin historial</td></tr>';
        if (bhPagination) bhPagination.innerHTML = '';
        return;
      }

      history.forEach(item => {
        const tr = document.createElement('tr');
        const created = item.created_at || '';
        const universo = item.universo || item.url || '';
        const total = item.total_descargas || 0;
        const completed = item.completadas || 0;
        const estado = item.estado || 'unknown';
        const estadoLabel = {
          'completado': 'Completado',
          'en_progreso': 'En progreso',
          'detenido': 'Detenido',
          'error': 'Error',
        }[estado] || estado;
        const estadoClass = {
          'completado': 'text-completo',
          'en_progreso': 'text-descargando',
          'detenido': 'text-parcial',
          'error': 'text-error',
        }[estado] || 'text-muted';

        tr.innerHTML = `
          <td class="text-xs text-muted">${created}</td>
          <td class="text-sm" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${universo}</td>
          <td class="text-xs">${completed} / ${total}</td>
          <td class="text-xs ${estadoClass}">${estadoLabel}</td>
          <td>
            <button class="btn-ghost text-xs btn-reanudar" data-url="${item.url || ''}" data-start="${(completed + 1) || 1}" style="padding:4px 10px;">Reanudar</button>
          </td>
        `;
        bhBody.appendChild(tr);
      });

      // Pagination
      if (bhPagination) {
        bhTotalPages = data.total_pages || 1;
        renderPagination(bhPagination, bhPage, bhTotalPages, (p) => cargarHistorialBatch(p));
      }

      // Reanudar buttons
      bhBody.querySelectorAll('.btn-reanudar').forEach(btn => {
        btn.addEventListener('click', function() {
          const url = this.dataset.url;
          const start = this.dataset.start;
          if (urlInput) urlInput.value = url;
          if (batchStart) batchStart.value = start;
          // Switch to batch mode
          document.querySelector('.mode-tab[data-mode="batch"]')?.click();
          // Switch to download tab
          document.querySelector('.nav-tab[data-tab="download"]')?.click();
        });
      });

    } catch (err) {
      if (bhBody) {
        bhBody.innerHTML = `<tr><td colspan="5" class="text-error text-sm" style="text-align:center;padding:20px;">Error: ${err.message}</td></tr>`;
      }
    }
  }

  // ════════════════════════════════════════════════════════════════
  // Gallery
  // ════════════════════════════════════════════════════════════════

  async function cargarGaleria(page) {
    if (page && page > 0) galleryPage = page;

    if (!galleryGrid || !galleryEmpty || !galleryLoading) return;

    galleryGrid.innerHTML = '';
    galleryLoading.classList.remove('hidden');
    galleryEmpty.classList.add('hidden');
    if (galleryPagination) galleryPagination.innerHTML = '';

    const filters = { page: galleryPage };

    const searchInput = document.getElementById('gallerySearch');
    if (searchInput && searchInput.value.trim()) filters.search = searchInput.value.trim();

    const universeFilter = document.getElementById('filterUniverse');
    if (universeFilter && universeFilter.value) filters.universe = universeFilter.value;

    const estadoFilter = document.getElementById('filterEstado');
    if (estadoFilter && estadoFilter.value) filters.estado = estadoFilter.value;

    const dateFrom = document.getElementById('filterDateFrom');
    if (dateFrom && dateFrom.value) filters.date_from = dateFrom.value;

    const dateTo = document.getElementById('filterDateTo');
    if (dateTo && dateTo.value) filters.date_to = dateTo.value;

    const sortSelect = document.getElementById('filterSort');
    if (sortSelect && sortSelect.value) filters.sort = sortSelect.value;

    try {
      const data = await ScrapAPI.fetchGallery(filters);
      galleryLoading.classList.add('hidden');

      const comics = data.data || [];

      if (comics.length === 0) {
        galleryEmpty.classList.remove('hidden');
        return;
      }

      comics.forEach(comic => {
        const card = document.createElement('div');
        card.className = 'comic-card';

        const coverUrl = comic.portada || '';
        const title = comic.titulo || 'Sin título';
        const tipo = comic.tipo || '';
        const idioma = comic.idioma || '';
        const estado = comic.estado || 'desconocido';
        const id = comic.id || '';

        let badgesHTML = '';
        if (comic.universo) {
          const universos = Array.isArray(comic.universo) ? comic.universo : [comic.universo];
          universos.forEach(u => {
            if (u) badgesHTML += `<span class="badge badge-universe">${u}</span> `;
          });
        }

        if (comic.autores) {
          const autores = Array.isArray(comic.autores) ? comic.autores : [comic.autores];
          autores.forEach(a => {
            if (a) badgesHTML += `<span class="badge badge-author">${a}</span> `;
          });
        }

        if (comic.tags) {
          const tags = Array.isArray(comic.tags) ? comic.tags : [comic.tags];
          tags.slice(0, 3).forEach(t => {
            if (t) badgesHTML += `<span class="badge badge-tag">${t}</span> `;
          });
        }

        const estadoClass = (estado || '').toLowerCase().replace(/\s+/g, '');
        const estadoDot = `<span class="status-dot ${estadoClass}" title="${estado}"></span>`;

        card.innerHTML = `
          <div class="comic-cover">
            ${coverUrl ? `<img src="${coverUrl}" alt="${title}" loading="lazy" />` : `<span style="color:#475569;font-size:2rem;">📖</span>`}
          </div>
          <div class="comic-info">
            <h3 title="${title}">${title}</h3>
            <div class="text-xs" style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
              ${estadoDot} ${tipo ? '<span class="badge badge-type">' + tipo + '</span>' : ''} ${idioma ? '<span class="badge badge-lang">' + idioma + '</span>' : ''}
            </div>
            <div class="text-xs" style="display:flex;flex-wrap:wrap;gap:3px;">${badgesHTML}</div>
            <div style="display:flex;gap:6px;margin-top:4px;">
              <button class="btn-ghost text-xs btn-view" data-id="${id}" style="flex:1;padding:4px 8px;">Ver</button>
              <button class="btn-ghost text-xs btn-delete-comic" data-id="${id}" style="flex:1;padding:4px 8px;color:#f87171;">Eliminar</button>
            </div>
          </div>
        `;
        galleryGrid.appendChild(card);
      });

      // Pagination
      galleryTotalPages = data.total_pages || 1;
      if (galleryPagination) {
        renderPagination(galleryPagination, galleryPage, galleryTotalPages, (p) => cargarGaleria(p));
      }

      // Attach event listeners via delegation
      galleryGrid.querySelectorAll('.btn-view').forEach(btn => {
        btn.addEventListener('click', function(e) {
          e.stopPropagation();
          abrirVisor(this.dataset.id);
        });
      });

      galleryGrid.querySelectorAll('.btn-delete-comic').forEach(btn => {
        btn.addEventListener('click', function(e) {
          e.stopPropagation();
          eliminarComic(this.dataset.id);
        });
      });

    } catch (err) {
      galleryLoading.classList.add('hidden');
      galleryEmpty.classList.remove('hidden');
      galleryEmpty.querySelector('p').textContent = `Error: ${err.message}`;
    }
  }

  // ════════════════════════════════════════════════════════════════
  // Viewer
  // ════════════════════════════════════════════════════════════════

  async function abrirVisor(comicId) {
    if (!viewerModal) return;

    try {
      const data = await ScrapAPI.fetchViewer(comicId);
      if (data.error) {
        appendToLog(`Visor: ${data.error}`, 'error');
        return;
      }

      viewerPages = data.pages || [];
      viewerCurrent = 0;
      viewerZoom = 1;
      viewerFit = true;

      if (viewerTitle) viewerTitle.textContent = data.titulo || 'Visor';
      viewerModal.classList.add('open');
      document.body.style.overflow = 'hidden';
      mostrarPagina();
    } catch (err) {
      appendToLog(`Visor: ${err.message}`, 'error');
    }
  }

  function mostrarPagina() {
    if (!viewerImage || !viewerPageInfo) return;
    if (viewerPages.length === 0) {
      viewerImage.src = '';
      viewerPageInfo.textContent = '0 / 0';
      return;
    }

    const page = viewerPages[viewerCurrent];
    if (page) {
      viewerImage.src = page.url || page;
    } else {
      viewerImage.src = '';
    }

    viewerPageInfo.textContent = `${viewerCurrent + 1} / ${viewerPages.length}`;
    if (viewerPrev) viewerPrev.disabled = viewerCurrent <= 0;
    if (viewerNext) viewerNext.disabled = viewerCurrent >= viewerPages.length - 1;

    aplicarZoom();
  }

  function aplicarZoom() {
    if (!viewerImage) return;
    if (viewerFit) {
      viewerImage.style.maxWidth = '100%';
      viewerImage.style.maxHeight = '100%';
      viewerImage.style.width = '';
      viewerImage.style.height = '';
      viewerImage.style.transform = '';
      viewerZoom = 1;
    } else {
      viewerImage.style.maxWidth = 'none';
      viewerImage.style.maxHeight = 'none';
      viewerImage.style.transform = `scale(${viewerZoom})`;
    }
    if (zoomLevel) zoomLevel.textContent = viewerFit ? 'Ajustar' : `${Math.round(viewerZoom * 100)}%`;
  }

  // Viewer navigation
  if (viewerPrev) {
    viewerPrev.addEventListener('click', function() {
      if (viewerCurrent > 0) { viewerCurrent--; mostrarPagina(); }
    });
  }
  if (viewerNext) {
    viewerNext.addEventListener('click', function() {
      if (viewerCurrent < viewerPages.length - 1) { viewerCurrent++; mostrarPagina(); }
    });
  }

  // Keyboard navigation
  document.addEventListener('keydown', function(e) {
    if (!viewerModal || !viewerModal.classList.contains('open')) return;
    if (e.key === 'Escape') cerrarVisor();
    if (e.key === 'ArrowLeft' && viewerCurrent > 0) { viewerCurrent--; mostrarPagina(); }
    if (e.key === 'ArrowRight' && viewerCurrent < viewerPages.length - 1) { viewerCurrent++; mostrarPagina(); }
    if (e.key === '+' || e.key === '=') { zoomIn?.click(); }
    if (e.key === '-') { zoomOut?.click(); }
  });

  if (viewerClose) viewerClose.addEventListener('click', cerrarVisor);

  function cerrarVisor() {
    if (!viewerModal) return;
    viewerModal.classList.remove('open');
    document.body.style.overflow = '';
    viewerImage.src = '';
    viewerPages = [];
    viewerCurrent = 0;
  }

  // Viewer zoom controls
  if (zoomIn) {
    zoomIn.addEventListener('click', function() {
      viewerFit = false;
      viewerZoom = Math.min(viewerZoom + 0.25, 5);
      aplicarZoom();
    });
  }
  if (zoomOut) {
    zoomOut.addEventListener('click', function() {
      viewerFit = false;
      viewerZoom = Math.max(viewerZoom - 0.25, 0.25);
      aplicarZoom();
    });
  }
  if (zoomReset) {
    zoomReset.addEventListener('click', function() {
      viewerZoom = 1;
      viewerFit = false;
      aplicarZoom();
    });
  }
  if (zoomFit) {
    zoomFit.addEventListener('click', function() {
      viewerFit = !viewerFit;
      aplicarZoom();
    });
  }

  // Scroll-to-zoom on viewer image
  if (viewerImage) {
    viewerImage.addEventListener('wheel', function(e) {
      if (viewerFit) {
        viewerFit = false;
      }
      e.preventDefault();
      if (e.deltaY < 0) {
        viewerZoom = Math.min(viewerZoom + 0.1, 5);
      } else {
        viewerZoom = Math.max(viewerZoom - 0.1, 0.25);
      }
      aplicarZoom();
    }, { passive: false });
  }

  // ════════════════════════════════════════════════════════════════
  // Taxonomy Modal
  // ════════════════════════════════════════════════════════════════

  function abrirModalTaxonomias(comic) {
    if (!taxModal || !taxContent) return;

    const showItems = (items, cls) => {
      if (!items || (Array.isArray(items) && items.length === 0)) {
        return '<span class="tax-empty">Sin datos</span>';
      }
      const arr = Array.isArray(items) ? items : [items];
      return arr.map(i => `<span class="tax-item ${cls}">${i}</span>`).join(' ');
    };

    taxContent.innerHTML = `
      <div class="tax-section">
        <h4>🌐 Universos</h4>
        <div class="tax-items">${showItems(comic.universo, 'tax-item-universe')}</div>
      </div>
      <div class="tax-section">
        <h4>🏷️ Tags</h4>
        <div class="tax-items">${showItems(comic.tags, 'tax-item-tag')}</div>
      </div>
      <div class="tax-section">
        <h4>📂 Tipos</h4>
        <div class="tax-items">${showItems(comic.tipo, 'tax-item-type')}</div>
      </div>
      <div class="tax-section">
        <h4>✍️ Autores</h4>
        <div class="tax-items">${showItems(comic.autores, 'tax-item-author')}</div>
      </div>
      <div class="tax-section">
        <h4>👥 Personajes</h4>
        <div class="tax-items">${showItems(comic.personajes, 'tax-item-character')}</div>
      </div>
      <div class="tax-section">
        <h4>🌍 Idioma</h4>
        <div class="tax-items">${showItems(comic.idioma, 'tax-item-lang')}</div>
      </div>
    `;

    taxModal.classList.add('open');
    document.body.style.overflow = 'hidden';
  }

  if (taxClose) {
    taxClose.addEventListener('click', function() {
      taxModal.classList.remove('open');
      document.body.style.overflow = '';
    });
  }

  // Click outside to close
  if (taxModal) {
    taxModal.addEventListener('click', function(e) {
      if (e.target === taxModal) {
        taxModal.classList.remove('open');
        document.body.style.overflow = '';
      }
    });
  }

  // ════════════════════════════════════════════════════════════════
  // Delete Manga
  // ════════════════════════════════════════════════════════════════

  async function eliminarComic(comicId) {
    if (!confirm('¿Eliminar este cómic de la base de datos? Las imágenes NO se borrarán del disco.')) return;

    try {
      const result = await ScrapAPI.deleteManga(comicId);
      if (result.success) {
        appendToLog(`Cómic #${comicId} eliminado de la base de datos.`, 'success');
        cargarGaleria();
      } else {
        appendToLog(`Error: ${result.error || 'No se pudo eliminar'}`, 'error');
      }
    } catch (err) {
      appendToLog(`Error: ${err.message}`, 'error');
    }
  }

  // ════════════════════════════════════════════════════════════════
  // Dashboard
  // ════════════════════════════════════════════════════════════════

  async function cargarDashboard() {
    if (!dashboardContent) return;

    dashboardContent.innerHTML = '<div style="text-align:center;padding:40px;"><div class="spinner"></div><p class="text-muted text-sm" style="margin-top:12px;">Cargando dashboard...</p></div>';

    try {
      const data = await ScrapAPI.fetchDashboard();

      if (data.error) {
        dashboardContent.innerHTML = `<div class="empty-state"><p>${data.error}</p></div>`;
        return;
      }

      // ── Stats Grid ──
      let html = '<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">';

      const stats = [
        { label: 'Total Cómics', value: data.total_comics || 0 },
        { label: 'Descargados', value: data.total_descargados || 0 },
        { label: 'Universos', value: data.total_universos || 0 },
        { label: 'Tags', value: data.total_tags || 0 },
      ];

      stats.forEach(s => {
        html += `
          <div class="stat-card">
            <div class="stat-value">${s.value}</div>
            <div class="stat-label">${s.label}</div>
          </div>
        `;
      });

      html += '</div>';

      // ── Estado Bars ──
      if (data.estados && Object.keys(data.estados).length > 0) {
        html += '<div class="glass p-4 mb-6"><h4 class="text-sm font-semibold text-slate-300 mb-3 uppercase tracking-wide">Estado de Descargas</h4>';
        for (const [estado, count] of Object.entries(data.estados)) {
          const cls = (estado || '').toLowerCase().replace(/\s+/g, '');
          const maxVal = Math.max(...Object.values(data.estados), 1);
          const pct = (count / maxVal) * 100;
          html += `
            <div class="flex items-center gap-3 mb-2">
              <span class="text-xs text-slate-400 w-24">${estado}</span>
              <div class="flex-1 h-2 bg-slate-700/50 rounded-full overflow-hidden">
                <div class="h-full rounded-full bg-${cls}" style="width:${pct}%"></div>
              </div>
              <span class="text-xs text-slate-400 w-10 text-right">${count}</span>
            </div>
          `;
        }
        html += '</div>';
      }

      // ── Top Universos ──
      if (data.top_universos && data.top_universos.length > 0) {
        html += '<div class="glass p-4 mb-6"><h4 class="text-sm font-semibold text-slate-300 mb-3 uppercase tracking-wide">Top Universos</h4>';
        data.top_universos.forEach(u => {
          const maxU = Math.max(...data.top_universos.map(x => x.count), 1);
          const pct = (u.count / maxU) * 100;
          html += `
            <div class="flex items-center gap-3 mb-2">
              <span class="text-xs text-slate-300 w-40 truncate" title="${u.universe}">${u.universe}</span>
              <div class="flex-1 h-2 bg-slate-700/50 rounded-full overflow-hidden">
                <div class="h-full rounded-full" style="width:${pct}%;background:linear-gradient(90deg,#a78bfa,#60a5fa);"></div>
              </div>
              <span class="text-xs text-slate-400 w-10 text-right">${u.count}</span>
            </div>
          `;
        });
        html += '</div>';
      }

      // ── Taxonomies (raw json columns) ──
      if (data.taxonomias) {
        html += '<div class="glass p-4 mb-6"><h4 class="text-sm font-semibold text-slate-300 mb-3 uppercase tracking-wide">Taxonomías</h4>';
        html += '<div class="grid grid-cols-2 md:grid-cols-3 gap-4">';

        const taxSections = [
          { label: 'Idiomas', items: data.taxonomias.idiomas, cls: 'badge-lang' },
          { label: 'Universos', items: data.taxonomias.universos, cls: 'badge-universe' },
          { label: 'Tipos', items: data.taxonomias.tipos, cls: 'badge-type' },
          { label: 'Autores', items: data.taxonomias.autores, cls: 'badge-author' },
          { label: 'Personajes', items: data.taxonomias.personajes, cls: 'badge-character' },
          { label: 'Tags', items: data.taxonomias.tags, cls: 'badge-tag' },
        ];

        taxSections.forEach(section => {
          html += '<div><h5 class="text-xs text-slate-500 mb-2 font-semibold uppercase tracking-wide">' + section.label + '</h5><div style="display:flex;flex-wrap:wrap;gap:4px;">';
          if (section.items && section.items.length > 0) {
            section.items.slice(0, 15).forEach(item => {
              html += `<span class="badge ${section.cls}">${item}</span>`;
            });
            if (section.items.length > 15) {
              html += `<span class="text-xs text-slate-500">+${section.items.length - 15} más</span>`;
            }
          } else {
            html += '<span class="text-xs text-slate-600 italic">Sin datos</span>';
          }
          html += '</div></div>';
        });

        html += '</div></div>';
      }

      // ── Top Idiomas ──
      if (data.top_idiomas && data.top_idiomas.length > 0) {
        html += '<div class="glass p-4 mb-6"><h4 class="text-sm font-semibold text-slate-300 mb-3 uppercase tracking-wide">Idiomas</h4><div class="flex flex-wrap gap-2">';
        data.top_idiomas.forEach(l => {
          html += `<span class="badge badge-lang" style="font-size:.8rem;padding:4px 12px;">${l.idioma || l.language || l}: ${l.count || ''}</span>`;
        });
        html += '</div></div>';
      }

      // ── Export button ──
      html += '<div class="text-right mb-4">';
      html += '<a href="export_wp.php" target="_blank" class="btn-ghost text-sm" style="display:inline-flex;align-items:center;gap:6px;">⬇️ Exportar para WordPress</a>';
      html += '</div>';

      // ── Recent Downloads ──
      if (data.recientes && data.recientes.length > 0) {
        html += '<div class="glass p-4 mb-6"><h4 class="text-sm font-semibold text-slate-300 mb-3 uppercase tracking-wide">Descargas Recientes</h4>';
        html += '<div class="table-wrap"><table><thead><tr><th>Título</th><th>Universo</th><th>Estado</th><th>Fecha</th></tr></thead><tbody>';
        data.recientes.forEach(r => {
          const estadoClass = (r.estado || '').toLowerCase().replace(/\s+/g, '');
          html += `<tr>
            <td class="text-sm" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${r.titulo || '-'}</td>
            <td class="text-xs text-muted">${r.universo || '-'}</td>
            <td class="text-xs"><span class="status-dot ${estadoClass}"></span> ${r.estado || '-'}</td>
            <td class="text-xs text-muted">${r.fecha || r.created_at || '-'}</td>
          </tr>`;
        });
        html += '</tbody></table></div></div>';
      }

      // ── System Info ──
      if (data.system) {
        html += '<div class="glass p-4"><h4 class="text-sm font-semibold text-slate-300 mb-3 uppercase tracking-wide">Información del Sistema</h4>';
        html += '<div class="grid grid-cols-2 md:grid-cols-3 gap-3 text-xs">';
        const sysItems = {
          'PHP Version': data.system.php_version,
          'Memoria Límite': data.system.memory_limit,
          'Max Execution': data.system.max_execution_time,
          'Disk Free': data.system.disk_free,
          'Disk Total': data.system.disk_total,
          'Rows in DB': data.system.total_rows,
        };
        for (const [key, val] of Object.entries(sysItems)) {
          if (val !== undefined && val !== null) {
            html += `<div><span class="text-muted">${key}:</span> <span class="text-slate-300">${val}</span></div>`;
          }
        }
        html += '</div></div>';
      }

      dashboardContent.innerHTML = html;

    } catch (err) {
      dashboardContent.innerHTML = `<div class="empty-state"><p>Error: ${err.message}</p></div>`;
    }
  }

  // ════════════════════════════════════════════════════════════════
  // Settings Modal
  // ════════════════════════════════════════════════════════════════

  if (settingsOpen) {
    settingsOpen.addEventListener('click', async function() {
      if (!settingsModal) return;
      settingsModal.classList.add('open');
      document.body.style.overflow = 'hidden';
      await cargarConfigActual();
    });
  }

  if (settingsClose) {
    settingsClose.addEventListener('click', function() {
      settingsModal.classList.remove('open');
      document.body.style.overflow = '';
    });
  }

  if (settingsModal) {
    settingsModal.addEventListener('click', function(e) {
      if (e.target === settingsModal) {
        settingsModal.classList.remove('open');
        document.body.style.overflow = '';
      }
    });
  }

  async function cargarConfigActual() {
    try {
      const config = await ScrapAPI.fetchConfig();

      const setVal = (id, val) => {
        const el = document.getElementById(id);
        if (el && val !== undefined && val !== null) el.value = val;
      };

      setVal('cfg_db_host', config.db_host);
      setVal('cfg_db_name', config.db_name);
      setVal('cfg_db_user', config.db_user);
      setVal('cfg_db_pass', config.db_pass || '');
      setVal('cfg_site_url', config.site_url);
      setVal('cfg_download_path', config.download_path);
      setVal('cfg_min_delay', config.min_delay);
      setVal('cfg_max_delay', config.max_delay);
      setVal('cfg_max_retries', config.max_retries);
      setVal('cfg_timeout', config.timeout);
      setVal('cfg_webp_quality', config.webp_quality);

      if (settingsStatus) settingsStatus.textContent = 'Configuración cargada.';
    } catch (err) {
      if (settingsStatus) settingsStatus.textContent = `Error: ${err.message}`;
    }
  }

  if (settingsForm) {
    settingsForm.addEventListener('submit', async function(e) {
      e.preventDefault();
      if (settingsStatus) settingsStatus.textContent = 'Guardando...';

      const getVal = (id) => {
        const el = document.getElementById(id);
        return el ? el.value : '';
      };

      const config = {
        db_host: getVal('cfg_db_host'),
        db_name: getVal('cfg_db_name'),
        db_user: getVal('cfg_db_user'),
        db_pass: getVal('cfg_db_pass'),
        site_url: getVal('cfg_site_url'),
        download_path: getVal('cfg_download_path'),
        min_delay: getVal('cfg_min_delay'),
        max_delay: getVal('cfg_max_delay'),
        max_retries: getVal('cfg_max_retries'),
        timeout: getVal('cfg_timeout'),
        webp_quality: getVal('cfg_webp_quality'),
      };

      try {
        const result = await ScrapAPI.saveConfig(config);
        if (result.success) {
          if (settingsStatus) settingsStatus.textContent = '✅ Configuración guardada exitosamente.';
        } else {
          if (settingsStatus) settingsStatus.textContent = `⚠️ ${result.error || 'Error al guardar'}`;
        }
      } catch (err) {
        if (settingsStatus) settingsStatus.textContent = `❌ ${err.message}`;
      }
    });
  }

  // ════════════════════════════════════════════════════════════════
  // Pagination Helper
  // ════════════════════════════════════════════════════════════════

  function renderPagination(container, current, total, callback) {
    container.innerHTML = '';
    if (total <= 1) return;

    const wrap = document.createElement('div');
    wrap.style.cssText = 'display:flex;align-items:center;gap:6px;justify-content:center;margin-top:16px;';

    const makeBtn = (label, page, disabled = false) => {
      const btn = document.createElement('button');
      btn.className = 'btn-ghost text-xs';
      btn.textContent = label;
      btn.style.cssText = 'padding:4px 10px;';
      if (disabled) btn.disabled = true;
      btn.addEventListener('click', () => callback(page));
      return btn;
    };

    // Prev
    wrap.appendChild(makeBtn('‹', current - 1, current <= 1));

    // Pages
    const maxVisible = 7;
    let start = Math.max(1, current - Math.floor(maxVisible / 2));
    let end = Math.min(total, start + maxVisible - 1);
    if (end - start < maxVisible - 1) {
      start = Math.max(1, end - maxVisible + 1);
    }

    if (start > 1) {
      wrap.appendChild(makeBtn('1', 1));
      if (start > 2) {
        const dots = document.createElement('span');
        dots.className = 'text-xs text-muted';
        dots.textContent = '...';
        wrap.appendChild(dots);
      }
    }

    for (let i = start; i <= end; i++) {
      const btn = document.createElement('button');
      if (i === current) {
        btn.className = 'text-xs';
        btn.style.cssText = 'padding:4px 10px;background:linear-gradient(135deg,#7c3aed,#3b82f6);border:none;border-radius:8px;color:#fff;font-weight:600;cursor:default;';
        btn.textContent = i;
      } else {
        btn.className = 'btn-ghost text-xs';
        btn.style.cssText = 'padding:4px 10px;';
        btn.addEventListener('click', () => callback(i));
        btn.textContent = i;
      }
      wrap.appendChild(btn);
    }

    if (end < total) {
      if (end < total - 1) {
        const dots = document.createElement('span');
        dots.className = 'text-xs text-muted';
        dots.textContent = '...';
        wrap.appendChild(dots);
      }
      wrap.appendChild(makeBtn(String(total), total));
    }

    // Next
    wrap.appendChild(makeBtn('›', current + 1, current >= total));

    container.appendChild(wrap);
  }

  // ════════════════════════════════════════════════════════════════
  // Gallery Filter Events
  // ════════════════════════════════════════════════════════════════

  const gallerySearch = document.getElementById('gallerySearch');
  if (gallerySearch) {
    gallerySearch.addEventListener('keydown', function(e) {
      if (e.key === 'Enter') { galleryPage = 1; cargarGaleria(); }
    });
  }

  ['filterUniverse', 'filterEstado', 'filterSort'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('change', function() { galleryPage = 1; cargarGaleria(); });
  });

  ['filterDateFrom', 'filterDateTo'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('change', function() { galleryPage = 1; cargarGaleria(); });
  });

  // ════════════════════════════════════════════════════════════════
  // Batch History Search
  // ════════════════════════════════════════════════════════════════

  if (bhSearch) {
    bhSearch.addEventListener('keydown', function(e) {
      if (e.key === 'Enter') { bhPage = 1; cargarHistorialBatch(); }
    });
  }

  // ════════════════════════════════════════════════════════════════
  // Auto-load gallery on page load
  // ════════════════════════════════════════════════════════════════

  setTimeout(() => cargarGaleria(), 500);

  // ════════════════════════════════════════════════════════════════
  // Public API (for external access if needed)
  // ════════════════════════════════════════════════════════════════

  window.ScrapApp = {
    cargarGaleria,
    cargarDashboard,
    cargarHistorialBatch,
    abrirVisor,
    abrirModalTaxonomias,
    appendToLog,
    updateProgress,
    resetProgress,
    setRunning,
    eliminarComic,
    cargarConfigActual,
  };

})();
