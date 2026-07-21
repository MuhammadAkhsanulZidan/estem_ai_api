<?php
namespace App\Helpers;

use App\Models\ApiResponse;

class RequestHelper {

    /**
     * Parses and optionally decrypts the incoming request payload.
     *
     * @param bool $isEncrypted Whether to use AES-256-GCM decryption
     * @return array The decoded input data array
     */
    public static function post_rq(): array {
        // Enforce strict POST method requirement
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            (new ApiResponse(false, 'Method Not Allowed. Please use POST.'))->send(405);
        }

        // Check if encryption is required via query string parameter (e.g., ?is_enc=true)
        $isEncrypted = filter_var($_GET['is_enc'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $rawInput = file_get_contents('php://input');

        if ($isEncrypted) {
            $requestData = json_decode($rawInput, true);
            if (!isset($requestData['iv'], $requestData['c'], $requestData['t'])) {
                (new ApiResponse(false, 'Invalid payload structure. Encryption parameters missing.'))->send(400);
            }

            $rawEncryptionKey = $_ENV['ENCRYPTION_KEY'] ?? '';
            $encryptionKey = base64_decode($rawEncryptionKey, true);

            if ($encryptionKey === false || mb_strlen($encryptionKey, '8bit') !== 32) {
                (new ApiResponse(false, 'Server encryption configuration error.'))->send(500);
            }

            $iv = base64_decode($requestData['iv']);
            $ciphertext = base64_decode($requestData['c']);
            $tag = base64_decode($requestData['t']);

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

            $input = json_decode($decryptedJson, true);
        } else {
            $input = json_decode($rawInput, true);
        }

        if (!is_array($input)) {
            (new ApiResponse(false, 'Invalid payload format.'))->send(400);
        }

        return $input;
    }
}
