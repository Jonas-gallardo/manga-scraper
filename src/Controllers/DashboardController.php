<?php
/**
 * src/Controllers/DashboardController.php
 *
 * Controller for the dashboard statistics API endpoint.
 * Returns system statistics as JSON.
 *
 * @package ScrapApp\Controllers
 */

namespace ScrapApp\Controllers;

class DashboardController extends BaseController
{
    /**
     * Get all dashboard statistics.
     * Matches the logic from dashboard.php.
     */
    public function index(): void
    {
        try {
            $pdo = $this->getPDO();

            // ── Total de cómics ──
            $total_comics = (int) $pdo->query(
                'SELECT COUNT(*) FROM comics_descargados'
            )->fetchColumn();

            // ── Por estado ──
            $stmt_estado = $pdo->query(
                'SELECT estado, COUNT(*) as count FROM comics_descargados GROUP BY estado'
            );
            $por_estado = [];
            while ($row = $stmt_estado->fetch()) {
                $por_estado[$row['estado']] = (int) $row['count'];
            }

            // ── Páginas ──
            $stmt_paginas = $pdo->query(
                'SELECT COALESCE(SUM(paginas_ok), 0) as ok, COALESCE(SUM(paginas_fail), 0) as fail FROM comics_descargados'
            );
            $paginas_row = $stmt_paginas->fetch();
            $total_paginas_ok   = (int) $paginas_row['ok'];
            $total_paginas_fail = (int) $paginas_row['fail'];
            $total_paginas      = $total_paginas_ok + $total_paginas_fail;

            // ── Tamaño en disco ──
            $stmt_tamano = $pdo->query(
                'SELECT COALESCE(SUM(tamano_bytes), 0) as total FROM comics_descargados'
            );
            $tamano_total_bytes = (int) $stmt_tamano->fetch()['total'];

            // ── Universos únicos ──
            $total_universos = (int) $pdo->query(
                'SELECT COUNT(DISTINCT universo) FROM comics_descargados WHERE universo IS NOT NULL'
            )->fetchColumn();

            // ── Últimas descargas ──
            $stmt_ultimas = $pdo->query(
                'SELECT id_fuente, titulo, universo, estado, paginas_ok, total_paginas, fecha_descarga
                 FROM comics_descargados
                 ORDER BY fecha_descarga DESC LIMIT 10'
            );
            $ultimas_descargas = $stmt_ultimas->fetchAll();

            // ── Top universos ──
            $stmt_top = $pdo->query(
                'SELECT universo, COUNT(*) as total
                 FROM comics_descargados
                 WHERE universo IS NOT NULL
                 GROUP BY universo
                 ORDER BY total DESC LIMIT 10'
            );
            $top_universos = $stmt_top->fetchAll();

            // ── Estadísticas del directorio de descargas ──
            $downloads_dir = defined('DOWNLOADS_DIR') ? DOWNLOADS_DIR : (__DIR__ . '/../../descargas');
            $dir_size = 0;
            $dir_count = 0;
            if (is_dir($downloads_dir)) {
                $it = new \RecursiveDirectoryIterator($downloads_dir, \RecursiveDirectoryIterator::SKIP_DOTS);
                $files = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($files as $file) {
                    if ($file->isFile()) {
                        $dir_size += $file->getSize();
                        $dir_count++;
                    }
                }
            }

            // ── Taxonomías ──
            $top_idiomas = [];
            $etiquetas_unicas = 0;
            $stmt_tax = $pdo->query(
                'SELECT taxonomias FROM comics_descargados WHERE taxonomias IS NOT NULL'
            );
            $idioma_count = [];
            $tag_set = [];
            while ($row = $stmt_tax->fetch()) {
                $tax = json_decode($row['taxonomias'], true);
                if (!is_array($tax)) continue;
                if (!empty($tax['idioma'])) {
                    $lang = trim($tax['idioma']);
                    $idioma_count[$lang] = ($idioma_count[$lang] ?? 0) + 1;
                }
                if (!empty($tax['etiquetas'])) {
                    foreach ($tax['etiquetas'] as $tag) {
                        $tag_set[mb_strtolower(trim($tag))] = true;
                    }
                }
            }
            arsort($idioma_count);
            $top_idiomas = array_slice($idioma_count, 0, 10);
            $etiquetas_unicas = count($tag_set);

            // ── Actividad reciente (últimos 7 días) ──
            $stmt_actividad = $pdo->query(
                'SELECT DATE(fecha_descarga) as dia, COUNT(*) as total
                 FROM comics_descargados
                 WHERE fecha_descarga >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                 GROUP BY DATE(fecha_descarga)
                 ORDER BY dia ASC'
            );
            $actividad_reciente = $stmt_actividad->fetchAll();

            // ── Tasa de éxito ──
            $total_con_estado = $total_paginas_ok + $total_paginas_fail;
            $tasa_exito = $total_con_estado > 0
                ? round(($total_paginas_ok / $total_con_estado) * 100, 1)
                : 0;

            // ── Total descargados (paginas_ok > 0) ──
            $total_descargados = (int) $pdo->query(
                'SELECT COUNT(*) FROM comics_descargados WHERE paginas_ok > 0'
            )->fetchColumn();

            // ── Total tags ──
            $total_tags = (int) $pdo->query(
                'SELECT COUNT(DISTINCT TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(c.tags, ",", n.n), ",", -1))) as total
                 FROM comics_descargados c
                 CROSS JOIN (SELECT 1 n UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5) n
                 WHERE CHAR_LENGTH(c.tags) - CHAR_LENGTH(REPLACE(c.tags, ",", "")) >= n.n - 1'
            )->fetchColumn();
            if ($total_tags === 0) {
                $total_tags = $etiquetas_unicas;
            }

            // ── Taxonomies from DB ──
            $taxonomias = [
                'idiomas'    => array_keys($top_idiomas),
                'universos'  => array_map(fn($u) => $u['universo'], $top_universos),
                'tipos'      => [],
                'autores'    => [],
                'personajes' => [],
                'tags'       => [],
            ];

            // Get tipos and autores from comics
            // Types are stored in the taxonomias JSON column
            $stmt_tax_tipos = $pdo->query(
                'SELECT taxonomias FROM comics_descargados WHERE taxonomias IS NOT NULL LIMIT 200'
            );
            $tipo_set = [];
            while ($row = $stmt_tax_tipos->fetch()) {
                $tax = json_decode($row['taxonomias'], true);
                if (!is_array($tax) || empty($tax['tipos'])) continue;
                foreach ((array)$tax['tipos'] as $t) {
                    $t = trim($t);
                    if ($t !== '') $tipo_set[$t] = true;
                }
            }
            $taxonomias['tipos'] = array_keys(array_slice($tipo_set, 0, 20));

            $stmt_autores = $pdo->query(
                'SELECT DISTINCT autor FROM comics_descargados WHERE autor IS NOT NULL AND autor != "" ORDER BY autor LIMIT 20'
            );
            $taxonomias['autores'] = $stmt_autores->fetchAll(\PDO::FETCH_COLUMN);

            // Get unique tags from comics
            if ($etiquetas_unicas > 0) {
                $stmt_tags = $pdo->query(
                    'SELECT tags FROM comics_descargados WHERE tags IS NOT NULL AND tags != "" LIMIT 100'
                );
                $all_tags = [];
                while ($row = $stmt_tags->fetch()) {
                    $parts = explode(',', $row['tags']);
                    foreach ($parts as $t) {
                        $t = trim($t);
                        if ($t !== '') $all_tags[mb_strtolower($t)] = $t;
                    }
                }
                $taxonomias['tags'] = array_values(array_slice($all_tags, 0, 30));
            }

            // ── System info ──
            $system = [
                'php_version'       => phpversion(),
                'memory_limit'      => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time') . 's',
                'disk_free'         => $this->formatBytes(@disk_free_space(DOWNLOADS_DIR) ?: 0),
                'disk_total'        => $this->formatBytes(@disk_total_space(DOWNLOADS_DIR) ?: 0),
                'total_rows'        => $total_comics,
            ];

            // ── Format recientes ──
            $recientes = [];
            foreach ($ultimas_descargas as $r) {
                $recientes[] = [
                    'titulo'    => $r['titulo'] ?? '-',
                    'universo'  => $r['universo'] ?? '-',
                    'estado'    => $r['estado'] ?? '-',
                    'fecha'     => $r['fecha_descarga'] ?? '-',
                    'created_at' => $r['fecha_descarga'] ?? '-',
                ];
            }

            // ── Format top_universos ──
            $top_universos_formatted = [];
            foreach ($top_universos as $u) {
                $top_universos_formatted[] = [
                    'universe' => $u['universo'],
                    'count'    => (int) $u['total'],
                ];
            }

            // ── Format top_idiomas ──
            $top_idiomas_formatted = [];
            foreach ($top_idiomas as $lang => $count) {
                $top_idiomas_formatted[] = [
                    'idioma' => $lang,
                    'count'  => $count,
                ];
            }

            $this->json([
                'success'           => true,
                'total_comics'      => $total_comics,
                'total_descargados' => $total_descargados,
                'total_universos'   => $total_universos,
                'total_tags'        => $total_tags,
                'estados'           => $por_estado,
                'top_universos'     => $top_universos_formatted,
                'taxonomias'        => $taxonomias,
                'top_idiomas'       => $top_idiomas_formatted,
                'recientes'         => $recientes,
                'system'            => $system,
                'total_paginas_ok'  => $total_paginas_ok,
                'total_paginas_fail' => $total_paginas_fail,
                'total_paginas'     => $total_paginas,
                'tamano_total_bytes' => $tamano_total_bytes,
                'tamano_formateado' => $this->formatBytes($tamano_total_bytes),
                'dir_size'          => $dir_size,
                'dir_size_formateado' => $this->formatBytes($dir_size),
                'dir_file_count'    => $dir_count,
                'etiquetas_unicas'  => $etiquetas_unicas,
                'actividad_reciente' => $actividad_reciente,
                'tasa_exito'        => $tasa_exito,
            ]);
        } catch (\Exception $e) {
            $this->json([
                'success' => false,
                'message' => 'Error al obtener estadísticas: ' . $e->getMessage(),
            ], 500);
        }
    }
}
