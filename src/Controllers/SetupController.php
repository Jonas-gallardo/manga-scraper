<?php
/**
 * src/Controllers/SetupController.php
 *
 * Controller for the initial setup wizard page.
 * Renders the HTML configuration form.
 *
 * @package ScrapApp\Controllers
 */

namespace ScrapApp\Controllers;

class SetupController extends BaseController
{
    /**
     * Show the setup/configuration page.
     */
    public function index(): void
    {
        ?><!DOCTYPE html>
        <html lang="es">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Configuración — Comic Scraper Pro</title>
            <script src="https://cdn.tailwindcss.com"></script>
            <style>
                body { background: #0d1117; color: #e0e0e0; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
                .setup-card { background: #161b22; border: 1px solid #30363d; border-radius: 1rem; padding: 2rem; width: 100%; max-width: 560px; }
                .form-input { width: 100%; padding: 0.625rem 0.875rem; background: #0d1117; border: 1px solid #30363d; border-radius: 0.5rem; color: #e0e0e0; transition: all 0.2s; font-size: 0.9rem; }
                .form-input:focus { outline: none; border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15); }
                .form-input::placeholder { color: #484f58; }
                .btn-primary { padding: 0.75rem 2rem; border-radius: 0.5rem; font-weight: 600; color: white; background: linear-gradient(135deg, #6366f1, #8b5cf6); transition: all 0.2s; border: none; cursor: pointer; width: 100%; font-size: 1rem; }
                .btn-primary:hover { transform: translateY(-1px); box-shadow: 0 6px 20px rgba(99, 102, 241, 0.4); }
                .btn-primary:disabled { opacity: 0.5; cursor: not-allowed; transform: none; box-shadow: none; }
                .spinner { display: inline-block; width: 1rem; height: 1rem; border: 2px solid rgba(255,255,255,0.3); border-top-color: #fff; border-radius: 50%; animation: spin 0.6s linear infinite; vertical-align: middle; }
                @keyframes spin { to { transform: rotate(360deg); } }
                .success-msg { color: #3fb950; }
                .error-msg { color: #f85149; }
                label { display: block; font-size: 0.8rem; color: #8b949e; margin-bottom: 0.35rem; }
                .help-text { font-size: 0.7rem; color: #484f58; margin-top: 0.25rem; }
            </style>
        </head>
        <body>
            <div class="setup-card">
                <div class="text-center mb-6">
                    <h1 class="text-2xl font-bold bg-gradient-to-r from-indigo-400 via-purple-400 to-pink-400 bg-clip-text text-transparent">
                        ⚙️ Comic Scraper Pro
                    </h1>
                    <p class="text-gray-500 text-sm mt-1">Configuración inicial del sistema</p>
                </div>

                <div id="statusMsg" class="hidden text-sm p-3 rounded-lg mb-4"></div>

                <form id="setupForm" class="space-y-4">
                    <div class="border-b border-gray-800 pb-2 mb-2">
                        <h2 class="text-sm font-semibold text-indigo-400">🗄️ Base de Datos MySQL</h2>
                        <p class="text-xs text-gray-500">Credenciales para conectar a MySQL (XAMPP/LAMP)</p>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label for="db_host">Host</label>
                            <input type="text" id="db_host" name="db_host" value="localhost" class="form-input" placeholder="localhost">
                        </div>
                        <div>
                            <label for="db_name">Base de datos</label>
                            <input type="text" id="db_name" name="db_name" value="comics_db" class="form-input" placeholder="comics_db">
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label for="db_user">Usuario</label>
                            <input type="text" id="db_user" name="db_user" value="root" class="form-input" placeholder="root">
                            <p class="help-text">Usuario de MySQL (default: root)</p>
                        </div>
                        <div>
                            <label for="db_pass">Contraseña</label>
                            <input type="password" id="db_pass" name="db_pass" class="form-input" placeholder="(vacía en XAMPP)">
                            <p class="help-text">Contraseña de MySQL (default: vacía)</p>
                        </div>
                    </div>

                    <div class="border-b border-gray-800 pb-2 mb-2">
                        <h2 class="text-sm font-semibold text-indigo-400">🌐 Sitio Web de Origen</h2>
                        <p class="text-xs text-gray-500">URL base del sitio de donde se extraerán los cómics</p>
                    </div>

                    <div>
                        <label for="site_base_url">URL Base del Sitio</label>
                        <input type="url" id="site_base_url" name="site_base_url" value="https://sitio.com" class="form-input" placeholder="https://sitio.com">
                        <p class="help-text">Ej: <code class="text-indigo-400">https://es.3hentai.net</code></p>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label for="site_view_path">Ruta vista individual</label>
                            <input type="text" id="site_view_path" name="site_view_path" value="/d" class="form-input" placeholder="/view">
                            <p class="help-text">Ej: <code class="text-indigo-400">/d</code> para 3hentai</p>
                        </div>
                        <div>
                            <label for="site_batch_path">Ruta búsqueda/batch</label>
                            <input type="text" id="site_batch_path" name="site_batch_path" value="/search" class="form-input" placeholder="/parody">
                            <p class="help-text">Ej: <code class="text-indigo-400">/search</code> para 3hentai</p>
                        </div>
                    </div>

                    <div class="border-b border-gray-800 pb-2 mb-2">
                        <h2 class="text-sm font-semibold text-indigo-400">📁 Directorio de Descargas</h2>
                        <p class="text-xs text-gray-500">Ruta donde se almacenarán los cómics descargados</p>
                    </div>
                    <div>
                        <label for="download_path">Ruta de descarga</label>
                        <input type="text" id="download_path" name="download_path" value="" class="form-input" placeholder="<?php echo __DIR__; ?>/../../descargas">
                        <p class="help-text">Ruta absoluta o relativa. Vacío = valor por defecto.</p>
                    </div>

                    <div class="border-b border-gray-800 pb-2 mb-2">
                        <h2 class="text-sm font-semibold text-indigo-400">🛡️ Anti-Ban (opcional)</h2>
                        <p class="text-xs text-gray-500">Ajustes de retardos y reintentos</p>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label for="delay_page_min">Delay entre páginas (s)</label>
                            <input type="number" id="delay_page_min" name="delay_page_min" value="1.5" step="0.1" min="0.5" class="form-input">
                        </div>
                        <div>
                            <label for="delay_page_max">Delay máximo páginas (s)</label>
                            <input type="number" id="delay_page_max" name="delay_page_max" value="3.5" step="0.1" min="0.5" class="form-input">
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label for="delay_comic_min">Delay entre cómics (s)</label>
                            <input type="number" id="delay_comic_min" name="delay_comic_min" value="5" min="1" class="form-input">
                        </div>
                        <div>
                            <label for="delay_comic_max">Delay máximo cómics (s)</label>
                            <input type="number" id="delay_comic_max" name="delay_comic_max" value="10" min="1" class="form-input">
                        </div>
                    </div>
                    <div>
                        <label for="max_retries">Reintentos máximos por error</label>
                        <input type="number" id="max_retries" name="max_retries" value="3" min="0" max="10" class="form-input">
                    </div>

                    <button type="submit" id="btnSave" class="btn-primary">
                        💾 Guardar Configuración y Probar Conexión
                    </button>
                </form>

                <div id="successActions" class="hidden mt-4 text-center space-y-2">
                    <p class="success-msg text-sm">✅ Configuración guardada exitosamente</p>
                    <a href="index.php" class="inline-block px-6 py-2.5 rounded-lg font-semibold text-white bg-gradient-to-r from-indigo-500 to-purple-500 hover:shadow-lg transition">
                        🚀 Ir a la Aplicación
                    </a>
                </div>

                <p class="text-center text-xs text-gray-600 mt-6">
                    Las credenciales se guardan en <code>config.json</code> (local, no se comparten)
                </p>
            </div>

            <script>
            (function() {
                const form = document.getElementById('setupForm');
                const btnSave = document.getElementById('btnSave');
                const statusMsg = document.getElementById('statusMsg');
                const successActions = document.getElementById('successActions');

                fetch('save_config.php')
                    .then(r => r.json())
                    .then(data => {
                        if (data.success && data.config) {
                            const c = data.config;
                            document.getElementById('db_host').value = c.db_host || 'localhost';
                            document.getElementById('db_name').value = c.db_name || 'comics_db';
                            document.getElementById('db_user').value = c.db_user || 'root';
                            document.getElementById('site_base_url').value = c.site_base_url || 'https://sitio.com';
                            document.getElementById('site_view_path').value = c.site_view_path || '/view';
                            document.getElementById('site_batch_path').value = c.site_batch_path || '/parody';
                            document.getElementById('download_path').value = c.download_path || '';
                            document.getElementById('delay_page_min').value = c.delay_page_min || 1.5;
                            document.getElementById('delay_page_max').value = c.delay_page_max || 3.5;
                            document.getElementById('delay_comic_min').value = c.delay_comic_min || 5;
                            document.getElementById('delay_comic_max').value = c.delay_comic_max || 10;
                            document.getElementById('max_retries').value = c.max_retries || 3;
                            if (c.db_pass_hint) {
                                document.getElementById('db_pass').placeholder = c.db_pass_hint;
                            }
                        }
                    })
                    .catch(() => {});

                form.addEventListener('submit', async (e) => {
                    e.preventDefault();
                    btnSave.disabled = true;
                    btnSave.innerHTML = '<span class="spinner"></span> Guardando y probando...';
                    statusMsg.classList.add('hidden');
                    successActions.classList.add('hidden');

                    const formData = new FormData(form);

                    try {
                        const res = await fetch('save_config.php', {
                            method: 'POST',
                            body: formData
                        });
                        const data = await res.json();

                        if (data.success) {
                            statusMsg.className = 'success-msg text-sm p-3 rounded-lg bg-[#0f2d1a] border border-[#2ea043] mb-4';
                            statusMsg.textContent = data.message;
                            statusMsg.classList.remove('hidden');
                            successActions.classList.remove('hidden');
                            btnSave.textContent = '✅ Configuración Guardada';
                        } else {
                            statusMsg.className = 'error-msg text-sm p-3 rounded-lg bg-[#2d0f0f] border border-[#f85149] mb-4';
                            statusMsg.textContent = '❌ ' + (data.message || 'Error al guardar');
                            statusMsg.classList.remove('hidden');
                            btnSave.disabled = false;
                            btnSave.innerHTML = '💾 Guardar Configuración y Probar Conexión';
                        }
                    } catch (err) {
                        statusMsg.className = 'error-msg text-sm p-3 rounded-lg bg-[#2d0f0f] border border-[#f85149] mb-4';
                        statusMsg.textContent = '❌ Error de conexión: ' + err.message;
                        statusMsg.classList.remove('hidden');
                        btnSave.disabled = false;
                        btnSave.innerHTML = '💾 Guardar Configuración y Probar Conexión';
                    }
                });
            })();
            </script>
        </body>
        </html>
        <?php
        exit;
    }
}
