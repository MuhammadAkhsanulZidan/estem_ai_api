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

// Get the raw incoming JSON payload (expects an encrypted package)
$rawInput = file_get_contents('php://input');
$requestData = json_decode($rawInput, true);

// Validate that the required encryption components exist
if (!isset($requestData['iv'], $requestData['c'], $requestData['t'])) {
    (new ApiResponse(false, 'Invalid payload structure. Encryption parameters missing.'))->send(400);
}

// Retrieve the shared secret encryption key (must be 32 bytes for AES-256)
// Retrieve and decode the Base64 encryption key (results in 32 raw bytes)
$rawEncryptionKey = $_ENV['ENCRYPTION_KEY'] ?? '';
$encryptionKey = base64_decode($rawEncryptionKey, true);

if ($encryptionKey === false || mb_strlen($encryptionKey, '8bit') !== 32) {
    (new ApiResponse(false, 'Server encryption configuration error.'))->send(500);
}
try {
    // Decode base64-encoded encryption components sent by the client
    $iv = base64_decode($requestData['iv']);
    $ciphertext = base64_decode($requestData['c']);
    $tag = base64_decode($requestData['t']);

    // Decrypt the payload using AES-256-GCM
    $decryptedJson = openssl_decrypt(
        $ciphertext,
        'aes-256-gcm',
        $encryptionKey,
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    if ($decryptedJson === false) {
        (new ApiResponse(false, 'Decryption failed. Invalid key or corrupted data.'))->send(400);
    }

    // Parse the decrypted JSON string into an array
    $input = json_decode($decryptedJson, true);
    if (!is_array($input)) {
        (new ApiResponse(false, 'Invalid decrypted payload format.'))->send(400);
    }

    $username = trim($input['username'] ?? '');
    $roleId = $input['role_id'] ?? '';
    $password = $input['password'] ?? '';

    // Basic payload validation
    if (empty($username) || empty($password)) {
        (new ApiResponse(false, 'Username and password are required'))->send(400);
    }

    // Fetch user from PostgreSQL using prepared statements to prevent SQL injection
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

} catch (\Exception $e) {
    (new ApiResponse(false, 'Debug Error: ' . $e->getMessage()))->send(500);
}
