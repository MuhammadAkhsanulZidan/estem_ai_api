<?php

namespace App\Core;

class Router
{
    private array $routes = [];

    public function get(string $uri, array $action): void
    {
        $this->addRoute('GET', $uri, $action);
    }

    public function post(string $uri, array $action): void
    {
        $this->addRoute('POST', $uri, $action);
    }

    private function addRoute(string $method, string $uri, array $action): void
    {
        $this->routes[] = [
            'method' => $method,
            'uri'    => $uri,
            'action' => $action,
        ];
    }

    public function dispatch(string $requestUri, string $requestMethod): void
    {
        $path = parse_url($requestUri, PHP_URL_PATH);

        foreach ($this->routes as $route) {
            if ($route['uri'] === $path && $route['method'] === $requestMethod) {
                [$controllerClass, $methodName] = $route['action'];

                $controller = new $controllerClass();
                $controller->$methodName();
                return;
            }
        }

        http_response_code(404);
        echo json_encode(['error' => 'Endpoint not found']);
    }
}
