<?php

namespace App\Controllers;

use App\Config\Database;
use App\Models\ApiResponse;
use App\Helpers\RequestHelper;
use Firebase\JWT\JWT;

class AuthController {
    public function login(){
        try {
            $pdo = Database::getConnection();
            // Get payload (plain JSON or decrypted package) using the helper
            $data = RequestHelper::getBody();

            $username = trim($data['username'] ?? '');
            $roleId = $data['role_id'] ?? '';
            $password = $data['password'] ?? '';

            // Basic payload validation
            if (empty($username) || empty($password) || empty($roleId)) {
                (new ApiResponse(false, 'Username, password, and role are required'))->send(400);
            }

            // Fetch user from PostgreSQL using prepared statements
            $stmt = $pdo->prepare("
                SELECT id, username, role_id, password_hash
                FROM users
                WHERE username = :username
                AND role_id = :role_id
            ");

            $stmt->execute(['username' => $username, 'role_id' => $roleId]);
            $user = $stmt->fetch();

            // Verify user exists and check password hash securely
            if ($user && password_verify($password, $user['password_hash'])) {
                $issuedAt = time();
                $expirationTime = $issuedAt + 3600; // Token valid for 1 hour
                $secretKey = $_ENV['JWT_SECRET'] ?? '';

                $payload = [
                    'iat' => $issuedAt,
                    'exp' => $expirationTime,
                    'data' => [
                        'id' => $user['id'],
                        'username' => $user['username']
                    ]
                ];

                // Generate the JWT
                $jwt = JWT::encode($payload, $secretKey, 'HS256');

                $responseData = [
                    'token' => $jwt,
                    'expires_in' => 3600,
                    'user' => [
                        'id' => $user['id'],
                        'username' => $user['username']
                    ]
                ];

                (new ApiResponse(true, 'Login successful', $responseData))->send(200);

            } else {
                // Generic error response to prevent user enumeration attacks
                (new ApiResponse(false, 'Invalid username or password'))->send(401);
            }

        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Debug Error: ' . $e->getMessage()))->send(500);
        }
    }
}
