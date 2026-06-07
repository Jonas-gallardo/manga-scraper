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
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
    };
    // Don't set Content-Type for FormData (let browser set it)
    if (options.body && !(options.body instanceof FormData)) {
      defaults.headers['Content-Type'] = 'application/json';
    }
    const merged = { ...defaults, ...options };
    const response = await fetch(url, merged);
    return response;
  }

  /**
   * Safely parse a JSON response, handling errors gracefully.
   * Falls back to text if the response is not valid JSON.
   */
  async function parseJSON(response) {
    if (!response.ok) {
      const text = await response.text().catch(() => '');
      throw new Error(`HTTP ${response.status}: ${response.statusText}${text ? ' — ' + text.slice(0, 200) : ''}`);
    }
    const contentType = response.headers.get('content-type') || '';
    if (!contentType.includes('application/json') && !contentType.includes('application/problem+json')) {
      const text = await response.text().catch(() => '');
      throw new Error(`Respuesta inesperada (${contentType || 'vacía'})${text ? ': ' + text.slice(0, 200) : ''}`);
    }
    return response.json();
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
    return parseJSON(resp);
  }

  // ── Gallery ────────────────────────────────────────────────
  async function fetchGallery(filters = {}) {
    const params = new URLSearchParams(filters);
    const resp = await request(`gallery.php?${params}`);
    return parseJSON(resp);
  }

  // ── Viewer ─────────────────────────────────────────────────
  async function fetchViewer(comicId, page) {
    const params = new URLSearchParams({ comic_id: comicId });
    // Only add page when explicitly requested (for image serving, not JSON listing)
    if (page !== undefined && page !== null) {
      params.set('page', page);
    }
    const resp = await request(`viewer.php?${params}`);
    return parseJSON(resp);
  }

  // ── Delete Manga ───────────────────────────────────────────
  async function deleteManga(comicId) {
    const resp = await request('delete_manga.php', {
      method: 'POST',
      body: JSON.stringify({ comic_id: comicId }),
    });
    return parseJSON(resp);
  }

  // ── Dashboard ──────────────────────────────────────────────
  async function fetchDashboard() {
    const resp = await request('dashboard.php');
    return parseJSON(resp);
  }

  // ── Settings ───────────────────────────────────────────────
  async function fetchConfig() {
    const resp = await request('save_config.php');
    return parseJSON(resp);
  }

  async function saveConfig(data) {
    const resp = await request('save_config.php', {
      method: 'POST',
      body: JSON.stringify(data),
    });
    return parseJSON(resp);
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
