<?php
/**
 * src/Controllers/GalleryController.php
 *
 * Controller for the comic gallery AJAX API.
 * Returns paginated, filtered JSON listing of downloaded comics.
 *
 * @package ScrapApp\Controllers
 */

namespace ScrapApp\Controllers;

class GalleryController extends BaseController
{
    /**
     * Get paginated gallery listing.
     * Matches the logic from gallery.php.
     */
    public function index(): void
    {
        try {
            $pdo = $this->getPDO();

            // ── Parameters ──
            $page        = max(1, (int) $this->getParam('page', 1));
            $per_page    = max(1, min(50, (int) $this->getParam('per_page', 20)));
            $search      = trim($this->getParam('search', ''));
            $universo    = trim($this->getParam('universo', ''));
            $estado      = trim($this->getParam('estado', ''));
            $fecha_desde = trim($this->getParam('fecha_desde', ''));
            $fecha_hasta = trim($this->getParam('fecha_hasta', ''));
            $sort        = in_array($this->getParam('sort', 'fecha_descarga'), ['fecha_descarga', 'titulo', 'id_fuente', 'universo', 'total_paginas'])
                           ? $this->getParam('sort') : 'fecha_descarga';
            $order       = strtoupper($this->getParam('order', 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

            $offset = ($page - 1) * $per_page;

            // ── Build dynamic WHERE ──
            $where = [];
            $params = [];

            if ($search) {
                $where[] = '(c.titulo LIKE :search OR c.tags LIKE :search2 OR c.id_fuente LIKE :search3)';
                $params[':search'] = "%{$search}%";
                $params[':search2'] = "%{$search}%";
                $params[':search3'] = "%{$search}%";
            }

            if ($universo) {
                $where[] = 'c.universo = :universo';
                $params[':universo'] = $universo;
            }

            if ($estado) {
                $where[] = 'c.estado = :estado';
                $params[':estado'] = $estado;
            }

            if ($fecha_desde) {
                $where[] = 'c.fecha_descarga >= :fecha_desde';
                $params[':fecha_desde'] = $fecha_desde . ' 00:00:00';
            }

            if ($fecha_hasta) {
                $where[] = 'c.fecha_descarga <= :fecha_hasta';
                $params[':fecha_hasta'] = $fecha_hasta . ' 23:59:59';
            }

            // Exclude blacklisted manga
            $where[] = 'c.id_fuente NOT IN (SELECT id_fuente FROM mangas_eliminados)';

            $where_clause = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

            // ── Total count ──
            $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM comics_descargados c $where_clause");
            $stmt->execute($params);
            $total = (int) $stmt->fetch()['total'];

            // ── Query current page ──
            $allowed_sort = ['fecha_descarga', 'titulo', 'id_fuente', 'universo', 'total_paginas'];
            if (!in_array($sort, $allowed_sort)) $sort = 'fecha_descarga';
            $order_dir = ($order === 'ASC') ? 'ASC' : 'DESC';

            $sql = "SELECT c.*,
                           COALESCE(c.tamano_bytes, 0) as tamano_bytes
                    FROM comics_descargados c
                    $where_clause
                    ORDER BY c.$sort $order_dir
                    LIMIT :limit OFFSET :offset";

            $stmt = $pdo->prepare($sql);
            foreach ($params as $k => $v) {
                $stmt->bindValue($k, $v);
            }
            $stmt->bindValue(':limit', $per_page, \PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
            $stmt->execute();
            $comics = $stmt->fetchAll();

            // ── Get cover and real file count for each comic ──
            foreach ($comics as &$comic) {
                $comic['portada'] = null;
                $comic['archivos_reales'] = 0;

                // Parse JSON taxonomies
                if (!empty($comic['taxonomias'])) {
                    $decoded = json_decode($comic['taxonomias'], true);
                    $comic['taxonomias'] = is_array($decoded) ? $decoded : null;
                } else {
                    $comic['taxonomias'] = null;
                }

                if ($comic['ruta_carpeta'] && is_dir($comic['ruta_carpeta'])) {
                    $files = \escanear_imagenes($comic['ruta_carpeta']);
                    $comic['archivos_reales'] = count($files);

                    if (!empty($files)) {
                        $comic['portada'] = 'viewer.php?comic_id=' . $comic['id_fuente'] . '&cover=1';
                    }

                    // ── AUTO-REVALIDATION ──
                    if ($comic['estado'] === 'error' && $comic['archivos_reales'] > 0) {
                        $total_esperado = (int) $comic['total_paginas'];
                        $nuevo_estado = ($comic['archivos_reales'] >= $total_esperado && $total_esperado > 0)
                            ? 'completo'
                            : 'parcial';

                        $stmt_repair = $pdo->prepare(
                            'UPDATE comics_descargados SET estado = :nuevo, paginas_ok = :ok, paginas_fail = :fail WHERE id_fuente = :id'
                        );
                        $stmt_repair->execute([
                            ':nuevo' => $nuevo_estado,
                            ':ok'    => $comic['archivos_reales'],
                            ':fail'  => max(0, $total_esperado - $comic['archivos_reales']),
                            ':id'    => $comic['id_fuente'],
                        ]);

                        $comic['estado'] = $nuevo_estado;
                        $comic['paginas_ok'] = $comic['archivos_reales'];
                        $comic['paginas_fail'] = max(0, $total_esperado - $comic['archivos_reales']);
                    }
                }

                $comic['tamano_formateado'] = $this->formatBytes((int) $comic['tamano_bytes']);
            }
            unset($comic);

            // ── Get universe list for filters ──
            $stmt_uni = $pdo->query(
                'SELECT DISTINCT c.universo FROM comics_descargados c
                 WHERE c.universo IS NOT NULL
                 AND c.id_fuente NOT IN (SELECT id_fuente FROM mangas_eliminados)
                 ORDER BY c.universo'
            );
            $universos = $stmt_uni->fetchAll(\PDO::FETCH_COLUMN);

            // ── Count deleted manga ──
            $total_eliminados = (int) $pdo->query(
                'SELECT COUNT(*) FROM mangas_eliminados'
            )->fetchColumn();

            $this->json([
                'success'         => true,
                'comics'          => $comics,
                'total'           => $total,
                'page'            => $page,
                'per_page'        => $per_page,
                'total_pages'     => ceil($total / $per_page),
                'universos'       => $universos,
                'total_eliminados' => $total_eliminados,
            ]);
        } catch (\Exception $e) {
            $this->json([
                'success' => false,
                'message' => 'Error al consultar: ' . $e->getMessage(),
            ], 500);
        }
    }
}
