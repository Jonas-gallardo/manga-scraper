<?php
/**
 * LanguageProcessor.php
 *
 * Procesa y normaliza el idioma extraído de la fuente de scraping.
 * Asigna un identificador estandarizado para la taxonomía personalizada
 * "Idiomas" de Gluglux (WordPress CPT UI).
 *
 * @package ScrapApp
 * @subpackage Taxonomy
 */

class LanguageProcessor
{
    /**
     * Mapa de normalización de idiomas.
     * Clave: patrón de búsqueda (código ISO, nombre en varios idiomas)
     * Valor: nombre estandarizado en español para WordPress
     *
     * @var array<string, string>
     */
    private array $languageMap;

    public function __construct()
    {
        $this->languageMap = $this->buildLanguageMap();
    }

    /**
     * Construye el mapa de normalización de idiomas.
     *
     * @return array<string, string> Clave normalizada → nombre en español
     */
    private function buildLanguageMap(): array
    {
        $map = [];

        // Español
        $entries = ['español', 'spanish', 'es', 'castellano', 'spa', 'es_es', 'es-mx', 'sp'];
        foreach ($entries as $e) {
            $map[$this->normalizeKey($e)] = 'español';
        }

        // Inglés
        $entries = ['english', 'inglés', 'en', 'eng', 'en_us', 'en-gb', 'ingles'];
        foreach ($entries as $e) {
            $map[$this->normalizeKey($e)] = 'inglés';
        }

        // Japonés
        $entries = ['japanese', 'japonés', 'ja', 'jpn', 'jap', 'japonés', 'nihongo', '日本語'];
        foreach ($entries as $e) {
            $map[$this->normalizeKey($e)] = 'japonés';
        }

        // Chino
        $entries = ['chinese', 'chino', 'zh', 'zho', 'chi', 'mandarín', 'mandarin', 'cantones', 'cantonés', '中文'];
        foreach ($entries as $e) {
            $map[$this->normalizeKey($e)] = 'chino';
        }

        // Coreano
        $entries = ['korean', 'coreano', 'ko', 'kor', '한국어'];
        foreach ($entries as $e) {
            $map[$this->normalizeKey($e)] = 'coreano';
        }

        // Francés
        $entries = ['french', 'francés', 'fr', 'fra', 'fre', 'français', 'frances'];
        foreach ($entries as $e) {
            $map[$this->normalizeKey($e)] = 'francés';
        }

        // Alemán
        $entries = ['german', 'alemán', 'de', 'deu', 'ger', 'deutsch', 'aleman'];
        foreach ($entries as $e) {
            $map[$this->normalizeKey($e)] = 'alemán';
        }

        // Italiano
        $entries = ['italian', 'italiano', 'it', 'ita', 'italiano'];
        foreach ($entries as $e) {
            $map[$this->normalizeKey($e)] = 'italiano';
        }

        // Portugués
        $entries = ['portuguese', 'portugués', 'pt', 'por', 'portugues'];
        foreach ($entries as $e) {
            $map[$this->normalizeKey($e)] = 'portugués';
        }

        // Ruso
        $entries = ['russian', 'ruso', 'ru', 'rus', 'русский'];
        foreach ($entries as $e) {
            $map[$this->normalizeKey($e)] = 'ruso';
        }

        // Polaco
        $entries = ['polish', 'polaco', 'pl', 'pol', 'polski'];
        foreach ($entries as $e) {
            $map[$this->normalizeKey($e)] = 'polaco';
        }

        // Vietnamita
        $entries = ['vietnamese', 'vietnamita', 'vi', 'vie', 'tiếng việt'];
        foreach ($entries as $e) {
            $map[$this->normalizeKey($e)] = 'vietnamita';
        }

        // Tailandés
        $entries = ['thai', 'tailandés', 'th', 'tha', 'tailandes', 'ภาษาไทย'];
        foreach ($entries as $e) {
            $map[$this->normalizeKey($e)] = 'tailandés';
        }

        // Holandés
        $entries = ['dutch', 'holandés', 'nl', 'nld', 'dut', 'nederlands', 'holandes'];
        foreach ($entries as $e) {
            $map[$this->normalizeKey($e)] = 'holandés';
        }

        // Latín (presente en algunos contenidos)
        $entries = ['latin', 'latín', 'la', 'lat', 'latin'];
        foreach ($entries as $e) {
            $map[$this->normalizeKey($e)] = 'latín';
        }

        // Otros / Múltiples (cuando la fuente indica varios idiomas)
        $entries = ['multi', 'multiple', 'multilingual', 'varios', 'varios idiomas', 'otros'];
        foreach ($entries as $e) {
            $map[$this->normalizeKey($e)] = 'otros';
        }

        return $map;
    }

    /**
     * Procesa y normaliza un idioma extraído de la fuente.
     *
     * @param string|null $rawLanguage Código o nombre de idioma extraído
     * @return string|null Nombre normalizado en español, o null si no se pudo determinar
     */
    public function process(?string $rawLanguage): ?string
    {
        if ($rawLanguage === null || trim($rawLanguage) === '') {
            return null;
        }

        $clean = $this->cleanLanguageString($rawLanguage);

        if ($clean === null) {
            return null;
        }

        // Buscar en el mapa de normalización
        $key = $this->normalizeKey($clean);

        if (isset($this->languageMap[$key])) {
            return $this->languageMap[$key];
        }

        // Búsqueda parcial: si el string contiene alguna palabra clave conocida
        foreach ($this->languageMap as $mapKey => $mapValue) {
            if (strpos($key, $mapKey) !== false || strpos($mapKey, $key) !== false) {
                return $mapValue;
            }
        }

        // No se pudo determinar → devolver el original limpio como fallback
        return $clean;
    }

    /**
     * Limpia el string de idioma: elimina caracteres extraños, espacios.
     *
     * @param string $raw
     * @return string|null
     */
    private function cleanLanguageString(string $raw): ?string
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        // Eliminar caractéres no relevantes (números, símbolos raros)
        $clean = preg_replace('/[^\p{L}\s\-_]/u', '', $raw);
        $clean = preg_replace('/\s+/u', ' ', $clean);
        $clean = trim($clean);

        if ($clean === '') {
            return null;
        }

        return $clean;
    }

    /**
     * Normaliza una clave para búsqueda en el mapa.
     *
     * @param string $text
     * @return string
     */
    private function normalizeKey(string $text): string
    {
        return mb_strtolower(trim($text), 'UTF-8');
    }

    /**
     * Retorna el mapa completo de idiomas (para depuración).
     *
     * @return array<string, string>
     */
    public function getLanguageMap(): array
    {
        return $this->languageMap;
    }
}
