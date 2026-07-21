<?php
// public/api/login.php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/database.php';

use App\Models\ApiResponse;
use Firebase\JWT\JWT;

// Enforce strict POST method requirement
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    (new ApiResponse(false, 'Method Not Allowed. Please use POST.'))->send(405);
}

// Get incoming JSON request payload
$input = json_decode(file_get_contents('php://input'), true);

$username = trim($input['username'] ?? '');
$password = $input['password'] ?? '';

// Basic payload validation
if (empty($username) || empty($password)) {
    (new ApiResponse(false, 'Username and password are required'))->send(400);
}

try {
    // Fetch user from PostgreSQL using prepared statements to prevent SQL injection
    $stmt = $pdo->prepare("SELECT id, username, password FROM users WHERE username = :username");
    $stmt->execute(['username' => $username]);
    $user = $stmt->fetch();

    // Verify user exists and check password hash securely
    if ($user && password_verify($password, $user['password'])) {

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

        // Bundle payload data inside the standardized 'data' block
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

} catch (\PDOException $e) {
    error_log("Login Error: " . $e->getMessage());
    (new ApiResponse(false, 'Internal server error'))->send(500);
}
