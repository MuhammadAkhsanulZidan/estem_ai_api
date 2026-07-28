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
    public static function getBody(): array {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (strpos($contentType, 'multipart/form-data') !== false) {
            return $_POST;
        }

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

    /**
    * Executes a paginated SQL query along with total count calculation.
    *
    * @param PDO $pdo
    * @param string $baseQuery SQL query without WHERE or ORDER BY clauses.
    * @param string $whereClause Standard filter conditions (e.g., "WHERE 1=1 AND status = 'active'").
    * @param array $params Prepared statement bound values.
    * @param string $orderBy SQL ORDER BY expression.
    * @param int $pageNo Current page number.
    * @param int $pageRow Number of items per page.
    * @return array Standardized pagination response payload.
    */
    public static function paginate(
        PDO $pdo,
        string $baseQuery,
        string $whereClause = 'WHERE 1=1',
        array $params = [],
        string $orderBy = '',
        int $pageNo = 1,
        int $pageRow = 10
    ): array {
        $useLimit = $pageNo > 0 && $pageRow > 0;

        // 1. Calculate Total Matching Count
        $countQuery = "SELECT COUNT(*) FROM ({$baseQuery} {$whereClause}) AS count_subquery";
        $countStmt = $pdo->prepare($countQuery);
        foreach ($params as $key => $val) {
            $countStmt->bindValue(':' . $key, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $countStmt->execute();
        $totalItems = (int) $countStmt->fetchColumn();

        // 2. Fetch Paginated Records
        $query = "{$baseQuery} {$whereClause}";
        if ($orderBy !== '') {
            $query .= " {$orderBy}";
        }

        if ($useLimit) {
            $offset = ($pageNo - 1) * $pageRow;
            $query .= " LIMIT :limit OFFSET :offset";
        }

        $stmt = $pdo->prepare($query);
        foreach ($params as $key => $val) {
            $stmt->bindValue(':' . $key, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }

        if ($useLimit) {
            $stmt->bindValue(':limit', $pageRow, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        }

        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 3. Return Clean Pagination Payload
        return [
            'items'       => $items,
            'total_items' => $totalItems,
            'page_no'     => $pageNo,
            'page_row'    => $pageRow
        ];
    }
}
