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

        try {
            // 2. Decode JWT
            $decoded = JWT::decode($jwt, new Key($_ENV['JWT_SECRET'], 'HS256'));
            return (array) $decoded;

            // 3. Verify Role Authorization
            if (!empty($allowedRoles) && !in_array($decoded->data->role_name, $allowedRoles, true)) {
                http_response_code(403); // Forbidden
                echo json_encode(['error' => 'Forbidden: Insufficient privileges']);
                exit();
            }

            // Return user data so controllers can use $user->sub or $user->role
            return (array) $decoded;

        } catch (\Throwable $e) {
            http_response_code(401);
            echo json_encode([
                'error' => 'Unauthorized: Invalid or expired token',
                'data'=>$decoded
            ]);
            exit();
        }
    }
}
