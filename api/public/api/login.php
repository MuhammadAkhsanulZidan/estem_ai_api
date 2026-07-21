<?php
// public/api/login.php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/database.php';

use App\Models\ApiResponse;
use Firebase\JWT\JWT;

use function App\Functions\post_rq;

// Get incoming JSON request payload
$input = post_rq();

$username = trim($input['username'] ?? '');
$password = $input['password'] ?? '';

// Basic payload validation
if (empty($username) || empty($password)) {
    (new ApiResponse(false, 'Username and password are required'))->send(400);
}

try {
    // Fetch user from PostgreSQL using prepared statements to prevent SQL injection
    $stmt = $pdo->prepare("SELECT id, username, password_hash FROM users WHERE username = :username");
    $stmt->execute(['username' => $username]);
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

} catch (\Exception $e) {
    // Temporarily print the real error message for debugging
    (new ApiResponse(false, 'Debug Error: ' . $e->getMessage()))->send(500);
}
