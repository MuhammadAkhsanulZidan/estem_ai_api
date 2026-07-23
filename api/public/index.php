<?php

// 1. Dynamic Origin handling
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';

header("Access-Control-Allow-Origin: {$origin}");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Max-Age: 86400"); // Cache preflight for 24h

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit();
}

header('Content-Type: application/json');

// 2. Fatal Error Handler (catches missing files, syntax errors, etc.)
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        echo json_encode([
            'status' => 'fatal_error',
            'message' => $error['message'],
            'file' => $error['file'],
            'line' => $error['line']
        ]);
    }
});

// 3. Autoload & Routing
$autoloadPath = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    http_response_code(500);
    echo json_encode(['error' => "Autoload file not found at: {$autoloadPath}"]);
    exit();
}

require_once $autoloadPath;

use App\Core\Router;
use App\Controllers\AuthController;
use App\Controllers\AdminProtocolController;
use App\Controllers\UserController;

try {
    $router = new Router();
    $router->post('/v1/login', [AuthController::class, 'login']);
    $router->get('/v1/admin-protocols', [AdminProtocolController::class, 'get']);
    $router->post('/v1/admin-protocols', [AdminProtocolController::class, 'post']);
    $router->put('/v1/admin-protocols', [AdminProtocolController::class, 'put']);
    $router->delete('/v1/admin-protocols', [AdminProtocolController::class, 'delete']);

    $router->get('/v1/users', [UserController::class, 'get']);
    $router->post('/v1/users', [UserController::class, 'post']);
    $router->put('/v1/users', [UserController::class, 'put']);
    $router->delete('/v1/users', [UserController::class, 'delete']);

    // Parse path to remove query parameters
    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $router->dispatch($uri, $_SERVER['REQUEST_METHOD']);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'exception',
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}
