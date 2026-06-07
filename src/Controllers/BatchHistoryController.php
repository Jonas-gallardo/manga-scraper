<?php
/**
 * src/Controllers/BatchHistoryController.php
 *
 * Controller for the batch execution history API.
 * Returns paginated JSON with batch execution records.
 *
 * @package ScrapApp\Controllers
 */

namespace ScrapApp\Controllers;

class BatchHistoryController extends BaseController
{
    /**
     * Get batch execution history.
     * Matches the logic from batch_history.php.
     */
    public function index(): void
    {
        try {
            $pdo = $this->getPDO();

            $page     = max(1, (int) $this->getParam('page', 1));
            $per_page = max(1, min(100, (int) $this->getParam('per_page', 20)));
            $search   = trim($this->getParam('search', ''));

            $offset = ($page - 1) * $per_page;

            // ── Build WHERE ──
            $where = '';
            $params = [];
            if ($search) {
                $where = 'WHERE bh.universo LIKE :search OR bh.url_base LIKE :search2';
                $params[':search'] = "%{$search}%";
                $params[':search2'] = "%{$search}%";
            }

            // ── Total count ──
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM batch_historial bh $where");
            $stmt->execute($params);
            $total = (int) $stmt->fetchColumn();

            // ── Query page ──
            $sql = "SELECT bh.*,
                           CASE WHEN bh.ultima_pagina > 0
                               THEN bh.ultima_pagina + 1
                               ELSE 1
                           END as resume_page
                    FROM batch_historial bh
                    $where
                    ORDER BY bh.fecha_ejecucion DESC
                    LIMIT :limit OFFSET :offset";

            $stmt = $pdo->prepare($sql);
            foreach ($params as $k => $v) {
                $stmt->bindValue($k, $v);
            }
            $stmt->bindValue(':limit', $per_page, \PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
            $stmt->execute();
            $records = $stmt->fetchAll();

            // ── Format records ──
            foreach ($records as &$record) {
                $record['total_enlaces']     = (int) ($record['total_enlaces'] ?? 0);
                $record['comics_descargados'] = (int) ($record['comics_descargados'] ?? 0);
                $record['comics_omitidos']    = (int) ($record['comics_omitidos'] ?? 0);
                $record['comics_errores']     = (int) ($record['comics_errores'] ?? 0);
                $record['ultima_pagina']      = (int) ($record['ultima_pagina'] ?? 0);
                $record['pagina_inicial']     = (int) ($record['pagina_inicial'] ?? 1);
                $record['max_comics']         = (int) ($record['max_comics'] ?? 0);
            }
            unset($record);

            $this->json([
                'success'     => true,
                'records'     => $records,
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $per_page,
                'total_pages' => ceil($total / $per_page),
            ]);
        } catch (\Exception $e) {
            $this->json([
                'success' => false,
                'message' => 'Error al obtener historial: ' . $e->getMessage(),
            ], 500);
        }
    }
}
