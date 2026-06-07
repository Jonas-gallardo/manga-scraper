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

            $this->json([
                'success' => true,
                'total_comics' => $total_comics,
                'por_estado' => $por_estado,
                'total_paginas_ok' => $total_paginas_ok,
                'total_paginas_fail' => $total_paginas_fail,
                'total_paginas' => $total_paginas,
                'tamano_total_bytes' => $tamano_total_bytes,
                'tamano_formateado' => $this->formatBytes($tamano_total_bytes),
                'total_universos' => $total_universos,
                'ultimas_descargas' => $ultimas_descargas,
                'top_universos' => $top_universos,
                'dir_size' => $dir_size,
                'dir_size_formateado' => $this->formatBytes($dir_size),
                'dir_file_count' => $dir_count,
                'top_idiomas' => $top_idiomas,
                'etiquetas_unicas' => $etiquetas_unicas,
                'actividad_reciente' => $actividad_reciente,
                'tasa_exito' => $tasa_exito,
            ]);
        } catch (\Exception $e) {
            $this->json([
                'success' => false,
                'message' => 'Error al obtener estadísticas: ' . $e->getMessage(),
            ], 500);
        }
    }
}
