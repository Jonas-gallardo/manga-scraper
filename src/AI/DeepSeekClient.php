<?php
/**
 * DeepSeekClient.php
 *
 * Cliente para la API directa de DeepSeek (Chat Completions).
 * Genera la síntesis técnica de 2 oraciones que se almacena en
 * WordPress como post_excerpt.
 *
 * Base verificada por el usuario (replicada al pie de la letra):
 *  - Endpoint: https://api.deepseek.com/chat/completions (sin /v1)
 *  - Modelo: deepseek-chat
 *  - temperature: 0.1
 *  - max_tokens: 150
 *  - Reintentos: 3, sleep(2), break en 4xx (excepto 429)
 *  - CURLOPT_SSL_VERIFYPEER: false
 *  - Limpieza de título: trim(preg_replace('/\[.*?\]|\(.*?\)/', '', $titulo))
 *
 * Refuerzos para cumplimiento SEO estricto:
 *  - Prompt ampliado: prohíbe markdown, obliga a usar los nombres exactos
 *    de universo/personajes y pide densidad de keywords natural.
 *  - Limpieza post-generación: elimina asteriscos, guiones bajos, backticks
 *    y normaliza espacios.
 *  - Validación: exige exactamente 2 oraciones y sin caracteres prohibidos;
 *    si falla, regenera una vez antes de devolver el texto.
 *
 * @package ComicScraperPro
 */

/**
 * Cliente de DeepSeek para generar fichas técnicas descriptivas.
 */
class DeepSeekClient
{
    /** @var string API key de DeepSeek */
    private string $apiKey;

    /** @var string Endpoint de chat completions */
    private string $url;

    /** @var int Número máximo de reintentos por llamada */
    private int $maxRetries;

    /** @var int Segundos de espera entre reintentos */
    private int $retrySleepSeconds;

    /** @var string System prompt (REGLAS DE ORO ampliadas para SEO) */
    private string $systemPrompt;

    /** @var string Último error de diagnóstico (para logging) */
    private string $lastError = '';

    /**
     * @param string|null $apiKey API key (default: la verificada)
     * @param string|null $url Endpoint (default: el verificado sin /v1)
     */
    public function __construct(?string $apiKey = null, ?string $url = null)
    {
        $this->apiKey = $apiKey ?? 'sk-b4fbd8ac76074f0e9c2058cf4de61037';
        $this->url = $url ?? 'https://api.deepseek.com/chat/completions';
        $this->maxRetries = 3;
        $this->retrySleepSeconds = 2;

        $this->systemPrompt = <<<PROMPT
Eres un redactor técnico para catálogos web de cómics y manga para adultos.
Tu tarea es escribir una síntesis informativa de 2 oraciones para la ficha de un producto basándote ÚNICAMENTE en sus datos.

REGLAS DE ORO:
1. PROHIBIDO inventar eventos, tramas, romances, escándalos o acciones (NO digas 'se ve envuelta en...', 'te mantendrá despierto...', 'es una fantasía visual...').
2. Limítate a mencionar el formato, el universo, los personajes presentes y la naturaleza del contenido basado en las etiquetas.
3. Jamás incluyas códigos entre corchetes (ej: '[wjs07]').
4. Redacta en español fluido, profesional y descriptivo.
5. SIEMPRE 2 oraciones separadas por un punto.
6. PROHIBIDO usar formato markdown: no uses asteriscos (*), guiones bajos (_) ni acentos graves (`). El texto debe ser plano.
7. Usa EXACTAMENTE los nombres de universo y personajes tal como aparecen en los datos. No los sustituyas por descripciones genéricas (como 'una mujer', 'una androide', 'un héroe') ni los alteres.
8. Integra de forma natural el universo, los personajes principales y las etiquetas más relevantes como palabras clave, sin caer en repetición robótica ni amontonarlas.
PROMPT;
    }

    /**
     * Limpia el título eliminando corchetes y paréntesis (y su contenido).
     * Réplica exacta del script de referencia.
     *
     * @param string $titulo Título original
     * @return string Título limpio
     */
    public static function cleanTitle(string $titulo): string
    {
        return trim(preg_replace('/\[.*?\]|\(.*?\)/', '', $titulo));
    }

    /**
     * Limpia el texto generado por la IA.
     *
     * Elimina residuos markdown (asteriscos, guiones bajos, backticks),
     * corchetes sueltos y normaliza los espacios (incluye saltos de línea
     * y espacios múltiples).
     *
     * @param string $text Texto crudo generado por la IA
     * @return string Texto limpio
     */
    public static function sanitizeOutput(string $text): string
    {
        $text = str_replace(['**', '__', '`'], '', $text);
        $text = str_replace(['*', '_'], '', $text);
        $text = str_replace(['[', ']'], '', $text);
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim((string) $text);
    }

    /**
     * Cuenta las oraciones detectando signos de cierre seguidos de espacio o fin.
     *
     * Un punto seguido de un carácter no espacial (ej: "N.º") NO se cuenta
     * como cierre de oración, evitando falsos positivos con abreviaturas.
     *
     * @param string $text
     * @return int
     */
    private function countSentences(string $text): int
    {
        $count = preg_match_all('/[.!?](?=\s|$)/u', $text);
        return $count === false ? 0 : (int) $count;
    }

    /**
     * Valida que el excerpt cumpla los requisitos estrictos:
     * exactamente 2 oraciones y sin caracteres markdown ni corchetes.
     *
     * @param string $text Texto ya saneado
     * @return bool
     */
    private function isValidExcerpt(string $text): bool
    {
        if ($this->countSentences($text) !== 2) {
            return false;
        }
        if (preg_match('/[*_`\[\]]/u', $text)) {
            return false;
        }
        return true;
    }

    /**
     * Realiza una única llamada a la API y devuelve un array normalizado.
     *
     * @param array<string, mixed> $data Payload de la petición
     * @return array<string, mixed> ['ok' => bool, 'content' => string, 'httpCode' => int, 'curlError' => string, 'raw' => string]
     */
    private function requestCompletion(array $data): array
    {
        $ch = curl_init($this->url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $result    = json_decode($response, true);
        curl_close($ch);

        if (isset($result['choices'][0]['message']['content'])) {
            return [
                'ok'       => true,
                'content'  => (string) $result['choices'][0]['message']['content'],
                'httpCode' => (int) $httpCode,
            ];
        }

        return [
            'ok'        => false,
            'httpCode'  => (int) $httpCode,
            'curlError' => $curlError,
            'raw'       => substr((string) $response, 0, 300),
        ];
    }

    /**
     * Genera la ficha técnica descriptiva (síntesis de 2 oraciones).
     *
     * @param string $titulo Título del post
     * @param string $universo Universo (puede ir vacío)
     * @param string $personajes Personajes separados por comas (puede ir vacío)
     * @param string $tipo Tipo (manga, doujinshi, etc.)
     * @param string $etiquetas Etiquetas separadas por comas (puede ir vacío)
     * @return string|null Texto generado o null si falló
     */
    public function generateExcerpt(
        string $titulo,
        string $universo = '',
        string $personajes = '',
        string $tipo = '',
        string $etiquetas = ''
    ): ?string {
        $this->lastError = '';

        // Limpieza idéntica al script de referencia
        $tituloLimpio = self::cleanTitle($titulo);

        $userPrompt = "Genera la ficha técnica descriptiva para esta entrada:
    - Título: {$tituloLimpio}
    - Universo: " . (empty($universo) ? 'N/A' : $universo) . "
    - Personajes: " . (empty($personajes) ? 'N/A' : $personajes) . "
    - Tipo: " . (empty($tipo) ? 'N/A' : $tipo) . "
    - Etiquetas: " . (empty($etiquetas) ? 'N/A' : $etiquetas) . "
Responde SOLO con el texto plano de 2 oraciones, sin markdown ni caracteres especiales.";

        $data = [
            'model'       => 'deepseek-chat',
            'messages'    => [
                ['role' => 'system', 'content' => $this->systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'temperature' => 0.1, // Temperatura muy baja para ser técnico y preciso
            'max_tokens'  => 150,
        ];

        // ── 1. Reintentos por red/HTTP ──
        $content = null;

        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            $res = $this->requestCompletion($data);

            if ($res['ok']) {
                $content = $res['content'];
                break;
            }

            // Guardar el error para diagnóstico
            $this->lastError = "HTTP: " . ($res['httpCode'] ?? '?') .
                               " | cURL: " . ($res['curlError'] ?? '') .
                               " | " . ($res['raw'] ?? '');

            // Si es un error definitivo (4xx de autenticación/validación), no reintentar
            if (($res['httpCode'] ?? 0) >= 400 && ($res['httpCode'] ?? 0) < 500 && ($res['httpCode'] ?? 0) != 429) {
                break;
            }

            // Espera antes de reintentar
            if ($attempt < $this->maxRetries) {
                sleep($this->retrySleepSeconds);
            }
        }

        if (!is_string($content) || trim($content) === '') {
            return null;
        }

        // ── 2. Limpieza post-generación ──
        $cleaned = self::sanitizeOutput($content);

        // ── 3. Validación + regeneración única si no cumple ──
        if (!$this->isValidExcerpt($cleaned)) {
            $res2 = $this->requestCompletion($data);
            if ($res2['ok']) {
                $cleaned2 = self::sanitizeOutput($res2['content']);
                if ($this->isValidExcerpt($cleaned2)) {
                    return $cleaned2;
                }
            }
        }

        return $cleaned !== '' ? $cleaned : null;
    }

    /**
     * Genera un texto alternativo (alt text) corto y descriptivo para un cómic.
     *
     * Se usa para el atributo alt de las imágenes. Devuelve UNA sola frase
     * descriptiva en español (máx. ~125 caracteres), sin markdown ni corchetes,
     * basada ÚNICAMENTE en el título y las taxonomías del cómic.
     *
     * @param string $titulo Título del cómic
     * @param string $universo Universo (puede ir vacío)
     * @param string $personajes Personajes separados por comas (puede ir vacío)
     * @param string $tipo Tipo (manga, doujinshi, etc.)
     * @param string $etiquetas Etiquetas separadas por comas (puede ir vacío)
     * @return string|null Texto alternativo generado o null si falló
     */
    public function generateAltText(
        string $titulo,
        string $universo = '',
        string $personajes = '',
        string $tipo = '',
        string $etiquetas = ''
    ): ?string {
        $this->lastError = '';

        $tituloLimpio = self::cleanTitle($titulo);

        $userPrompt = "Genera el texto alternativo (atributo alt) para la imagen de portada de este cómic:
    - Título: {$tituloLimpio}
    - Universo: " . (empty($universo) ? 'N/A' : $universo) . "
    - Personajes: " . (empty($personajes) ? 'N/A' : $personajes) . "
    - Tipo: " . (empty($tipo) ? 'N/A' : $tipo) . "
    - Etiquetas: " . (empty($etiquetas) ? 'N/A' : $etiquetas) . "
Responde SOLO con UNA frase descriptiva en español, sin markdown, sin corchetes, sin comillas, de máximo 125 caracteres, describiendo de forma objetiva qué es la imagen (una portada o página del cómic indicado).";

        $data = [
            'model'       => 'deepseek-chat',
            'messages'    => [
                ['role' => 'system', 'content' => $this->systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'temperature' => 0.1,
            'max_tokens'  => 80,
        ];

        $content = null;
        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            $res = $this->requestCompletion($data);

            if ($res['ok']) {
                $content = $res['content'];
                break;
            }

            $this->lastError = "HTTP: " . ($res['httpCode'] ?? '?') .
                               " | cURL: " . ($res['curlError'] ?? '') .
                               " | " . ($res['raw'] ?? '');

            if (($res['httpCode'] ?? 0) >= 400 && ($res['httpCode'] ?? 0) < 500 && ($res['httpCode'] ?? 0) != 429) {
                break;
            }

            if ($attempt < $this->maxRetries) {
                sleep($this->retrySleepSeconds);
            }
        }

        if (!is_string($content) || trim($content) === '') {
            return null;
        }

        $cleaned = self::sanitizeOutput($content);
        $cleaned = trim(preg_replace('/^["\']|["\']$/u', '', $cleaned));

        // Forzar longitud máxima razonable para un alt text (125 chars)
        if (mb_strlen($cleaned) > 125) {
            $cleaned = mb_substr($cleaned, 0, 125);
            $cleaned = rtrim($cleaned, ' ,;:');
        }

        return $cleaned !== '' ? $cleaned : null;
    }

    /**
     * Devuelve el último error de diagnóstico.
     *
     * @return string
     */
    public function getLastError(): string
    {
        return $this->lastError;
    }
}
