/**
 * Comic Scraper Pro — API Client Module
 * 
 * Centralizes all HTTP communication with the PHP backend.
 * Each endpoint returns a Promise.
 */
const ScrapAPI = (function() {
  'use strict';

  // ── Configuration injected by PHP ──────────────────────────
  const SITE_VIEW_PATH  = window._SITE_VIEW_PATH  || '/view';
  const SITE_BATCH_PATH = window._SITE_BATCH_PATH || '/parody';

  // ── Base fetch wrapper ─────────────────────────────────────
  async function request(url, options = {}) {
    const defaults = {
      headers: { 'Accept': 'application/json' },
    };
    // Don't set Content-Type for FormData (let browser set it)
    if (options.body && !(options.body instanceof FormData)) {
      defaults.headers['Content-Type'] = 'application/json';
    }
    const merged = { ...defaults, ...options };
    const response = await fetch(url, merged);
    return response;
  }

  // ── Scraper (streaming via ReadableStream) ──────────────────
  async function startScraping(formData, callbacks = {}) {
    const { onLine, onError, onComplete } = callbacks;

    const response = await fetch('scraper.php', {
      method: 'POST',
      body: formData,
    });

    if (!response.ok) {
      throw new Error(`HTTP ${response.status}: ${response.statusText}`);
    }

    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';

    function processLines(chunk) {
      buffer += chunk;
      const parts = buffer.split('\n');
      // Keep last (possibly incomplete) line in buffer
      buffer = parts.pop() || '';

      for (const line of parts) {
        const trimmed = line.trim();
        if (!trimmed) continue;

        // Try to parse JSON lines
        if (trimmed.startsWith('{')) {
          try {
            const data = JSON.parse(trimmed);
            if (typeof onLine === 'function') onLine(data);
            continue;
          } catch (_) {
            // fall through to plain text
          }
        }
        // Plain text line
        if (typeof onLine === 'function') onLine({ message: trimmed, type: 'info' });
      }
    }

    function readLoop() {
      return reader.read().then(({ done, value }) => {
        if (done) {
          // Flush remaining buffer
          if (buffer.trim()) {
            processLines(buffer + '\n');
          }
          if (typeof onComplete === 'function') onComplete();
          return;
        }
        processLines(decoder.decode(value, { stream: true }));
        return readLoop();
      }).catch(err => {
        if (typeof onError === 'function') onError(err);
      });
    }

    return readLoop();
  }

  // ── Stop scraper ───────────────────────────────────────────
  async function stopScraping() {
    await request('stop_scraper.php', { method: 'POST' });
  }

  // ── Batch History ──────────────────────────────────────────
  async function fetchBatchHistory(page = 1, search = '') {
    const params = new URLSearchParams({ page, search });
    const resp = await request(`batch_history.php?${params}`);
    return resp.json();
  }

  // ── Gallery ────────────────────────────────────────────────
  async function fetchGallery(filters = {}) {
    const params = new URLSearchParams(filters);
    const resp = await request(`gallery.php?${params}`);
    return resp.json();
  }

  // ── Viewer ─────────────────────────────────────────────────
  async function fetchViewer(comicId, page = 1) {
    const params = new URLSearchParams({ comic_id: comicId, page });
    const resp = await request(`viewer.php?${params}`);
    return resp.json();
  }

  // ── Delete Manga ───────────────────────────────────────────
  async function deleteManga(comicId) {
    const resp = await request('delete_manga.php', {
      method: 'POST',
      body: JSON.stringify({ comic_id: comicId }),
    });
    return resp.json();
  }

  // ── Dashboard ──────────────────────────────────────────────
  async function fetchDashboard() {
    const resp = await request('dashboard.php');
    return resp.json();
  }

  // ── Settings ───────────────────────────────────────────────
  async function fetchConfig() {
    const resp = await request('save_config.php');
    return resp.json();
  }

  async function saveConfig(data) {
    const resp = await request('save_config.php', {
      method: 'POST',
      body: JSON.stringify(data),
    });
    return resp.json();
  }

  // ── Public API ────────────────────────────────────────────
  return {
    SITE_VIEW_PATH,
    SITE_BATCH_PATH,
    request,
    startScraping,
    stopScraping,
    fetchBatchHistory,
    fetchGallery,
    fetchViewer,
    deleteManga,
    fetchDashboard,
    fetchConfig,
    saveConfig,
  };
})();
