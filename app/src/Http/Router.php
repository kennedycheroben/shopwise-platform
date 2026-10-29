<?php

namespace App\Http;

/**
 * Lightweight, High-Performance Storefront Router
 */
class Router
{
    private array $routes = [];
    private $notFoundHandler = null;

    /**
     * Register a GET route
     */
    public function get(string $path, callable $handler): self
    {
        return $this->addRoute('GET', $path, $handler);
    }

    /**
     * Register a POST route
     */
    public function post(string $path, callable $handler): self
    {
        return $this->addRoute('POST', $path, $handler);
    }

    /**
     * Register an HTTP route
     */
    public function addRoute(string $method, string $path, callable $handler): self
    {
        $normalizedPath = '/' . ltrim($path, '/');

        $this->routes[] = [
            'method'  => strtoupper($method),
            'path'    => $normalizedPath,
            'handler' => $handler,
        ];
        return $this;
    }

    /**
     * Set custom 404 handler
     */
    public function setNotFound(callable $handler): self
    {
        $this->notFoundHandler = $handler;
        return $this;
    }

    /**
     * Resolve current request path relative to base directory
     */
    public static function resolveRequestPath(): string
    {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($requestUri, PHP_URL_PATH) ?? '/';

        $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        $baseDir = rtrim(dirname($scriptName), '/');

        if ($baseDir !== '' && str_starts_with($path, $baseDir)) {
            $path = substr($path, strlen($baseDir));
        }

        if ($path === '' || $path === '/') {
            return '/';
        }

        return $path;
    }

    /**
     * Dispatch the current request
     */
    public function dispatch(): void
    {
        $requestMethod = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $effectiveMethod = ($requestMethod === 'HEAD') ? 'GET' : $requestMethod;
        $path = self::resolveRequestPath();

        foreach ($this->routes as $route) {
            if ($route['method'] !== $effectiveMethod) {
                continue;
            }

            // Convert parameter placeholders like {slug} to regex capture groups
            $pattern = preg_replace('#\{([a-zA-Z0-9_]+)\}#', '(?P<$1>[^/]+)', $route['path']);
            $regex = '#^' . $pattern . '$#';

            if (preg_match($regex, $path, $matches)) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                call_user_func($route['handler'], $params);
                return;
            }
        }

        if ($this->notFoundHandler !== null) {
            call_user_func($this->notFoundHandler);
        } else {
            http_response_code(404);
            echo "404 Not Found";
        }
    }
}
