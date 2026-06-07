<?php

declare(strict_types=1);

namespace ScrapApp\Infrastructure;

/**
 * HttpClient.php
 *
 * Cliente HTTP unificado para toda la aplicación.
 * Elimina la duplicación de fetchPage() en harvest_tags.php y harvest_series.php,
 * y centraliza la configuración de cURL.
 *
 * @package ScrapApp
 * @subpackage Infrastructure
 */
class HttpClient
{
    private array $defaultOptions;
    private string $userAgent;
    private int $timeout;
    private int $connectTimeout;
    private int $maxRedirects;
    private bool $sslVerify;

    /**
     * @param array<string, mixed> $options Opciones por defecto
     */
    public function __construct(array $options = [])
    {
        $this->userAgent = $options['user_agent']
            ?? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

        $this->timeout = $options['timeout'] ?? 30;
        $this->connectTimeout = $options['connect_timeout'] ?? 10;
        $this->maxRedirects = $options['max_redirects'] ?? 5;
        $this->sslVerify = $options['ssl_verify'] ?? false;

        $this->defaultOptions = $options['default_headers'] ?? [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
            'Accept-Language: es-ES,es;q=0.9,en;q=0.8',
            'DNT: 1',
            'Connection: keep-alive',
            'Upgrade-Insecure-Requests: 1',
        ];
    }

    /**
     * Obtiene el HTML de una URL.
     *
     * @param string $url URL a fetchear
     * @param array<string, mixed> $extraOptions Opciones adicionales que sobreescriben las default
     * @return string|null El HTML obtenido, o null si hubo error
     */
    public function fetchPage(string $url, array $extraOptions = []): ?string
    {
        $ch = curl_init();

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $extraOptions['timeout'] ?? $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $extraOptions['connect_timeout'] ?? $this->connectTimeout,
            CURLOPT_MAXREDIRS => $extraOptions['max_redirects'] ?? $this->maxRedirects,
            CURLOPT_USERAGENT => $extraOptions['user_agent'] ?? $this->userAgent,
            CURLOPT_SSL_VERIFYPEER => $extraOptions['ssl_verify'] ?? $this->sslVerify,
            CURLOPT_HTTPHEADER => $extraOptions['headers'] ?? $this->defaultOptions,
        ];

        curl_setopt_array($ch, $options);

        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($html === false || $html === '') {
            return null;
        }

        return $html;
    }

    /**
     * Descarga un archivo binario (imagen) a una ruta local.
     *
     * @param string $url URL del archivo a descargar
     * @param string $destination Ruta absoluta de destino
     * @param array<string, mixed> $extraOptions Opciones adicionales
     * @return bool True si la descarga fue exitosa
     */
    public function downloadFile(string $url, string $destination, array $extraOptions = []): bool
    {
        $dir = dirname($destination);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $fp = fopen($destination, 'wb');
        if ($fp === false) {
            return false;
        }

        $ch = curl_init();

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $extraOptions['timeout'] ?? 60,
            CURLOPT_CONNECTTIMEOUT => $extraOptions['connect_timeout'] ?? $this->connectTimeout,
            CURLOPT_MAXREDIRS => $extraOptions['max_redirects'] ?? $this->maxRedirects,
            CURLOPT_USERAGENT => $extraOptions['user_agent'] ?? $this->userAgent,
            CURLOPT_SSL_VERIFYPEER => $extraOptions['ssl_verify'] ?? $this->sslVerify,
            CURLOPT_HTTPHEADER => $extraOptions['headers'] ?? $this->defaultOptions,
        ];

        curl_setopt_array($ch, $options);
        $success = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if ($success === false || $httpCode >= 400) {
            @unlink($destination);
            return false;
        }

        return true;
    }

    /**
     * Obtiene el contenido JSON de una URL y lo decodifica.
     *
     * @param string $url URL del JSON
     * @param array<string, mixed> $extraOptions Opciones adicionales
     * @return array|null Array decodificado o null si hubo error
     */
    public function fetchJson(string $url, array $extraOptions = []): ?array
    {
        $html = $this->fetchPage($url, $extraOptions);
        if ($html === null) {
            return null;
        }

        $data = json_decode($html, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Parsea el HTML con DOMDocument y devuelve un DOMXPath.
     *
     * @param string $html HTML a parsear
     * @return \DOMXPath|null Objeto XPath listo para consultas, o null si falla el parseo
     */
    public function parseHtml(string $html): ?\DOMXPath
    {
        $doc = new \DOMDocument();
        $loaded = @$doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        if (!$loaded) {
            return null;
        }
        return new \DOMXPath($doc);
    }
}
