<?php
/**
 * src/Controllers/DictionaryController.php
 *
 * Controller for the tag/Universe dictionary manager.
 * Handles both HTML rendering (GET) and AJAX operations (POST).
 *
 * @package ScrapApp\Controllers
 */

namespace ScrapApp\Controllers;

class DictionaryController extends BaseController
{
    private string $customMappingsFile;
    private string $tagsHarvestFile;
    private string $seriesHarvestFile;

    public function __construct()
    {
        parent::__construct();
        $base = __DIR__ . '/../..';
        $this->customMappingsFile = $base . '/data/custom_mappings.json';
        $this->tagsHarvestFile    = $base . '/tags_3hentai.json';
        $this->seriesHarvestFile  = $base . '/series_3hentai.json';
    }

    /**
     * Handle all dictionary requests.
     * POST → AJAX handler
     * GET  → HTML page render
     */
    public function handle(): void
    {
        if ($this->isPost() && isset($_POST['action'])) {
            $this->handleAjax();
        } else {
            $this->renderPage();
        }
    }

    /**
     * Handle AJAX POST requests for dictionary operations.
     */
    private function handleAjax(): void
    {
        header('Content-Type: application/json');

        try {
            $custom = $this->loadCustomMappings();
            $action = $_POST['action'];

            switch ($action) {
                case 'add_tag':
                    $this->ajaxAddTag($custom);
                    break;
                case 'delete_tag':
                    $this->ajaxDeleteTag($custom);
                    break;
                case 'add_universe':
                    $this->ajaxAddUniverse($custom);
                    break;
                case 'delete_universe':
                    $this->ajaxDeleteUniverse($custom);
                    break;
                case 'add_tag_from_proposal':
                    $this->ajaxAddTagFromProposal($custom);
                    break;
                case 'add_universe_from_proposal':
                    $this->ajaxAddUniverseFromProposal($custom);
                    break;
                default:
                    throw new \Exception('Acción desconocida: ' . $action);
            }
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
    }

    private function ajaxAddTag(array &$custom): void
    {
        $original = trim($_POST['original'] ?? '');
        $destino  = trim($_POST['destino'] ?? '');
        if ($original === '' || $destino === '') {
            throw new \Exception('Ambos campos son requeridos');
        }
        $custom['tags'][$original] = $destino;
        $this->saveCustomMappings($custom);
        echo json_encode(['success' => true, 'message' => "Mapeo agregado: {$original} → {$destino}"]);
        exit;
    }

    private function ajaxDeleteTag(array &$custom): void
    {
        $original = trim($_POST['original'] ?? '');
        if ($original === '') {
            throw new \Exception('Tag original requerido');
        }
        $found = false;
        foreach ($custom['tags'] as $key => $value) {
            if ($key === $original) {
                unset($custom['tags'][$key]);
                $found = true;
                break;
            }
        }
        if (!$found) {
            throw new \Exception('El tag no está en los mappings personalizados (los hardcoded no se pueden eliminar aquí)');
        }
        $this->saveCustomMappings($custom);
        echo json_encode(['success' => true, 'message' => "Mapeo eliminado: {$original}"]);
        exit;
    }

    private function ajaxAddUniverse(array &$custom): void
    {
        $univName = trim($_POST['name'] ?? '');
        if ($univName === '') {
            throw new \Exception('Nombre del universo requerido');
        }
        $normalized = \TaxonomyData::normalizeForSearch($univName);

        // Check duplicates in hardcoded
        foreach (\TaxonomyData::getUniverses() as $existing) {
            if (\TaxonomyData::normalizeForSearch($existing) === $normalized) {
                throw new \Exception("El universo '{$univName}' ya existe en el diccionario base");
            }
        }
        // Check duplicates in custom
        foreach ($custom['universes'] as $existing) {
            if (\TaxonomyData::normalizeForSearch($existing) === $normalized) {
                throw new \Exception("El universo '{$univName}' ya está en los mappings personalizados");
            }
        }
        $custom['universes'][] = $univName;
        $this->saveCustomMappings($custom);
        echo json_encode(['success' => true, 'message' => "Universo agregado: {$univName}"]);
        exit;
    }

    private function ajaxDeleteUniverse(array &$custom): void
    {
        $univName = trim($_POST['name'] ?? '');
        if ($univName === '') {
            throw new \Exception('Nombre del universo requerido');
        }
        $normalized = \TaxonomyData::normalizeForSearch($univName);
        $found = false;
        foreach ($custom['universes'] as $i => $existing) {
            if (\TaxonomyData::normalizeForSearch($existing) === $normalized) {
                array_splice($custom['universes'], $i, 1);
                $found = true;
                break;
            }
        }
        if (!$found) {
            throw new \Exception('El universo no está en los mappings personalizados (los hardcoded no se pueden eliminar aquí)');
        }
        $this->saveCustomMappings($custom);
        echo json_encode(['success' => true, 'message' => "Universo eliminado: {$univName}"]);
        exit;
    }

    private function ajaxAddTagFromProposal(array &$custom): void
    {
        $original = trim($_POST['original'] ?? '');
        $destino  = trim($_POST['destino'] ?? $original);
        if ($original === '') {
            throw new \Exception('Tag original requerido');
        }
        $custom['tags'][$original] = $destino;
        $this->saveCustomMappings($custom);
        echo json_encode(['success' => true, 'message' => "Tag agregado al diccionario: {$original} → {$destino}"]);
        exit;
    }

    private function ajaxAddUniverseFromProposal(array &$custom): void
    {
        $univName = trim($_POST['name'] ?? '');
        if ($univName === '') {
            throw new \Exception('Nombre del universo requerido');
        }
        $normalized = \TaxonomyData::normalizeForSearch($univName);
        foreach ($this->getMergedUniverses() as $existing) {
            if (\TaxonomyData::normalizeForSearch($existing) === $normalized) {
                throw new \Exception("El universo '{$univName}' ya existe");
            }
        }
        $custom['universes'][] = $univName;
        $this->saveCustomMappings($custom);
        echo json_encode(['success' => true, 'message' => "Universo agregado al diccionario: {$univName}"]);
        exit;
    }

    /**
     * Render the dictionary management HTML page.
     */
    private function renderPage(): void
    {
        require_once __DIR__ . '/../../includes/TaxonomyData.php';

        $mergedTagMappings  = $this->getMergedTagMappings();
        $mergedUniverses    = $this->getMergedUniverses();
        $hardcodedTags      = \TaxonomyData::getTagMappings();
        $hardcodedUniverses = \TaxonomyData::getUniverses();
        $custom             = $this->loadCustomMappings();

        $tagsHarvest   = $this->loadHarvestData($this->tagsHarvestFile);
        $seriesHarvest = $this->loadHarvestData($this->seriesHarvestFile);

        $existingTagNormals = [];
        foreach ($mergedTagMappings as $key => $value) {
            $existingTagNormals[$key] = true;
        }
        foreach ($this->getMergedTags() as $norm => $tag) {
            $existingTagNormals[$norm] = true;
        }

        $existingUniverseNormals = [];
        foreach ($mergedUniverses as $univ) {
            $existingUniverseNormals[\TaxonomyData::normalizeForSearch($univ)] = $univ;
        }

        // Render the HTML page
        include __DIR__ . '/../../includes/dictionary_page.php';
        exit;
    }

    // ── Data helper methods ──

    private function loadCustomMappings(): array
    {
        if (!file_exists($this->customMappingsFile)) {
            return ['tags' => [], 'universes' => []];
        }
        $data = json_decode(file_get_contents($this->customMappingsFile), true);
        if (!is_array($data)) {
            return ['tags' => [], 'universes' => []];
        }
        $data['tags'] = $data['tags'] ?? [];
        $data['universes'] = $data['universes'] ?? [];
        return $data;
    }

    private function saveCustomMappings(array $data): bool
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        return file_put_contents($this->customMappingsFile, $json) !== false;
    }

    private function loadHarvestData(string $file): array
    {
        if (!file_exists($file)) {
            return [];
        }
        $data = json_decode(file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    private function getMergedTags(): array
    {
        $hardcoded = \TaxonomyData::getTags();
        $tags = [];
        foreach ($hardcoded as $tag) {
            $tags[\TaxonomyData::normalizeForSearch($tag)] = $tag;
        }
        return $tags;
    }

    private function getMergedTagMappings(): array
    {
        $hardcoded = \TaxonomyData::getTagMappings();
        $custom = $this->loadCustomMappings();
        $all = $hardcoded;
        foreach ($custom['tags'] as $key => $value) {
            $normalized = \TaxonomyData::normalizeForSearch($key);
            $all[$normalized] = $value;
        }
        return $all;
    }

    private function getMergedUniverses(): array
    {
        $hardcoded = \TaxonomyData::getUniverses();
        $custom = $this->loadCustomMappings();
        $all = $hardcoded;
        foreach ($custom['universes'] as $univ) {
            $normalized = \TaxonomyData::normalizeForSearch($univ);
            $exists = false;
            foreach ($all as $existing) {
                if (\TaxonomyData::normalizeForSearch($existing) === $normalized) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $all[] = $univ;
            }
        }
        return $all;
    }
}
