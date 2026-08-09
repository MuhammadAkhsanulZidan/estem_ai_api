<?php
namespace App\Helpers;

use PDO;

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

        if (trim($rawInput) === '') {
            return [];
        }

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
     * Resolves a database document path to a full HTTPS URL based on user role,
     * converting raw public paths to short asset URLs.
     */
    public static function getDocumentUrl(string $path, string $roleName = 'affiliator'): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        // Replace public/bck prefix with assets
        $cleanPath = ltrim($path, '/');
        if (str_starts_with($cleanPath, 'public/bck/')) {
            $cleanPath = 'assets/' . substr($cleanPath, 11);
        }

        $roleName = strtolower($roleName);
        $domain = 'jej-estemai.zendekia.com';
        if ($roleName === 'admin') {
            $domain = 'adm-estemai.zendekia.com';
        } elseif ($roleName === 'reviewer') {
            $domain = 'rev-estemai.zendekia.com';
        }

        return "https://{$domain}/" . ltrim($cleanPath, '/');
    }

    /**
     * Get affiliator ID based on user ID and role.
     * Admins/reviewers can specify affiliator_id in $_GET or request body,
     * while affiliators are locked to their own linked affiliator_id.
     */
    public static function getAffiliatorId(PDO $pdo, int $userId, string $roleName): int
    {
        $roleName = strtolower($roleName);
        if ($roleName === 'admin' || $roleName === 'reviewer') {
            $body = self::getBody();
            $affiliatorId = $_GET['affiliator_id'] ?? $body['affiliator_id'] ?? null;
            if ($affiliatorId !== null) {
                return (int)$affiliatorId;
            }
        }

        $stmt = $pdo->prepare("SELECT affiliator_id FROM users WHERE id = :id");
        $stmt->execute(['id' => $userId]);
        $affiliatorId = $stmt->fetchColumn();

        if (!$affiliatorId) {
            (new ApiResponse(false, 'User is not linked to any affiliator.'))->send(400);
            exit;
        }

        return (int)$affiliatorId;
    }

    /**
    * Executes a paginated SQL query along with total count calculation.
    *
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
        string $query,
        string $tableName,
        string $queryWhere = '',
        array $filterFields = [],
        array $params = [],
        ?callable $mutateItems = null
    ): array {
        $filterField = $_GET['filter_field'] ?? "";
        $filterValue = trim($_GET['filter_value'] ?? "");

        // Build dynamic filtering clause
        $filterConditions = [];

        if ($filterField !== "" && $filterValue !== "" && in_array($filterField, $filterFields, true)) {
            $filterConditions[] = "{$filterField} ILIKE :filter_val";
            $params['filter_val'] = '%' . $filterValue . '%';
        } elseif ($filterValue !== "" && !empty($filterFields)) {
            $orConditions = [];
            foreach ($filterFields as $f) {
                $orConditions[] = "{$f} ILIKE :filter_val";
            }
            $filterConditions[] = "(" . implode(" OR ", $orConditions) . ")";
            $params['filter_val'] = '%' . $filterValue . '%';
        }

        $whereClause = "";
        if (!empty($filterConditions)) {
            $whereClause = " AND " . implode(" AND ", $filterConditions);
        }

        $pageNo = isset($_GET['page_no']) ? (int)$_GET['page_no'] : 1;
        $pageRow = isset($_GET['page_row']) ? (int)$_GET['page_row'] : 10;

        // 1. Get total items count
        $countQuery = "SELECT COUNT(*) FROM {$tableName} WHERE 1=1 {$whereClause} {$queryWhere}";
        $stmt = $pdo->prepare($countQuery);
        foreach ($params as $key => $val) {
            $stmt->bindValue(':' . $key, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();

        $totalItems = (int)$stmt->fetchColumn();
        $useLimit = $pageNo > 0 && $pageRow > 0;

        // Apply filtering clause to main query (detecting if the outer query has a WHERE clause)
        $lastParenthesis = strrpos($query, ')');
        $hasOuterWhere = false;
        if ($lastParenthesis !== false) {
            $outerPart = substr($query, $lastParenthesis);
            $hasOuterWhere = stripos($outerPart, 'WHERE') !== false;
        } else {
            $hasOuterWhere = stripos($query, 'WHERE') !== false;
        }

        if ($hasOuterWhere) {
            $query .= " {$whereClause}";
        } else {
            $query .= " WHERE 1=1 {$whereClause}";
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

        // 2. Apply mutation if provided
        if ($mutateItems !== null) {
            $items = $mutateItems($items);
        }

        // 3. Return Clean Pagination Payload
        return [
            'items'       => $items,
            'total_items' => $totalItems,
            'page_no'     => $pageNo,
            'page_row'    => $pageRow
        ];
    }
}
