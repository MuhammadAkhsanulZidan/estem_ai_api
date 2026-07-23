<?php

namespace App\Middleware;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class AuthMiddleware {

    /**
     * Authenticates JWT and checks if user has one of the allowed roles.
     *
     * @param array $allowedRoles List of accepted roles for the route
     * @return array Decoded JWT payload data
     */
    public static function authorize(array $allowedRoles = []): array {
        // 1. Fetch Authorization Header
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if (!$authHeader || !preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized: Token missing']);
            exit();
        }

        $jwt = $matches[1];

        // Ensure environment variables are loaded
        if (empty($_ENV['JWT_SECRET'])) {
            if (file_exists(__DIR__ . '/../../.env')) {
                $dotenv = \Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
                $dotenv->safeLoad();
            }
        }

        $secret = $_ENV['JWT_SECRET'] ?? '';

        try {
            // 2. Decode JWT
            $decoded = JWT::decode($jwt, new Key($secret, 'HS256'));
            $decodedArray = json_decode(json_encode($decoded), true);

            // 3. Verify Role Authorization
            if (!empty($allowedRoles) && !in_array($decodedArray['data']['role_name'] ?? '', $allowedRoles, true)) {
                http_response_code(403); // Forbidden
                echo json_encode(['error' => 'Forbidden: Insufficient privileges']);
                exit();
            }

            // Return user data so controllers can use it
            return $decodedArray;

        } catch (\Throwable $e) {
            http_response_code(401);
            echo json_encode([
                'error' => 'Unauthorized: Invalid or expired token',
                'message' => $e->getMessage()
            ]);
            exit();
        }
    }
}
