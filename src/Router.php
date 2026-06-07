<?php
/**
 * src/Router.php
 *
 * Simple router for the Front Controller Pattern.
 * Maps HTTP method + URI patterns to controller actions.
 *
 * @package ScrapApp
 */

namespace ScrapApp;

class Router
{
    /** @var array<string, array<array{pattern: string, handler: callable}>> */
    private array $routes = [];

    /**
     * Register a GET route.
     */
    public function get(string $pattern, callable $handler): void
    {
        $this->routes['GET'][] = ['pattern' => $pattern, 'handler' => $handler];
    }

    /**
     * Register a POST route.
     */
    public function post(string $pattern, callable $handler): void
    {
        $this->routes['POST'][] = ['pattern' => $pattern, 'handler' => $handler];
    }

    /**
     * Register a route that accepts any HTTP method.
     */
    public function any(string $pattern, callable $handler): void
    {
        $this->routes['ANY'][] = ['pattern' => $pattern, 'handler' => $handler];
    }

    /**
     * Dispatch the current request to the matching route.
     *
     * @param string $method HTTP method (GET, POST)
     * @param string $uri    Request URI (path only)
     */
    public function dispatch(string $method, string $uri): void
    {
        // Normalize URI: remove query string and trailing slash
        $uri = parse_url($uri, PHP_URL_PATH);
        $uri = '/' . trim($uri, '/');

        // Try exact method match first
        foreach (['ANY', $method] as $m) {
            if (!isset($this->routes[$m])) {
                continue;
            }
            foreach ($this->routes[$m] as $route) {
                $params = $this->match($route['pattern'], $uri);
                if ($params !== null) {
                    call_user_func_array($route['handler'], $params);
                    return;
                }
            }
        }

        // 404 — no route matched
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'Ruta no encontrada: ' . $method . ' ' . $uri,
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Match a URI against a route pattern.
     * Supports {param} placeholders and optional trailing slash.
     *
     * Examples:
     *   /api/dashboard        → exact match
     *   /api/comic/{id}       → /api/comic/123  => ['id' => '123']
     *   /api/delete/{id}/     → /api/delete/456 => ['id' => '456']
     *
     * @param string $pattern Route pattern
     * @param string $uri     Request URI
     * @return array|null     Associative array of params, or null if no match
     */
    private function match(string $pattern, string $uri): ?array
    {
        // Normalize pattern
        $pattern = '/' . trim($pattern, '/');

        // Convert {param} placeholders to regex capture groups
        $regex = preg_replace('/\{(\w+)\}/', '(?P<$1>[^/]+)', $pattern);
        $regex = '#^' . $regex . '$#';

        if (preg_match($regex, $uri, $matches)) {
            // Filter out numeric keys
            $params = array_filter($matches, fn($key) => !is_int($key), ARRAY_FILTER_USE_KEY);
            return $params ?: [];
        }

        return null;
    }

    /**
     * Get all registered routes (for debugging).
     *
     * @return array<string, array<string>>
     */
    public function getRoutes(): array
    {
        $list = [];
        foreach ($this->routes as $method => $routes) {
            foreach ($routes as $route) {
                $list[] = $method . ' ' . $route['pattern'];
            }
        }
        return $list;
    }
}
