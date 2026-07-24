<?php

namespace App\Controllers;

use App\Config\Database;
use App\Middleware\AuthMiddleware;
use App\Models\ApiResponse;
use App\Helpers\RequestHelper;

use PDO;

class AdminProtocolController
{
    /**
     * Retrieve admin protocols (all or single by ID).
     */
    public function get()
    {
        try {
            $pdo = Database::getConnection();
            $id = $_GET['id'] ?? null;

            if ($id !== null) {
                $stmt = $pdo->prepare("SELECT * FROM admin_protocols WHERE id = :id");
                $stmt->execute(['id' => $id]);
                $protocol = $stmt->fetch();

                if (!$protocol) {
                    (new ApiResponse(false, 'Protocol not found'))->send(404);
                }

                (new ApiResponse(true, 'Protocol retrieved successfully', $protocol))->send(200);
            } else {
                $filterField = $_GET['filter_field'] ?? null;
                $filterValue = $_GET['filter_value'] ?? null;
                $pageNo = isset($_GET['page_no']) ? (int)$_GET['page_no'] : null;
                $pageRow = isset($_GET['page_row']) ? (int)$_GET['page_row'] : null;
                $allowedFields = ['protocol_name', 'indication', 'protocol_version', 'is_active'];

                $where = '';
                $params = [];

                if ($filterField !== null && $filterValue !== null && in_array($filterField, $allowedFields)) {
                    if ($filterField === 'is_active') {
                        $val = filter_var($filterValue, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
                        $where = "WHERE is_active = :val";
                        $params['val'] = $val;
                    } else {
                        $where = "WHERE {$filterField} ILIKE :val";
                        $params['val'] = '%' . $filterValue . '%';
                    }
                }

                // 1. Get total items count
                $countQuery = "SELECT COUNT(*) FROM admin_protocols $where";
                $stmt = $pdo->prepare($countQuery);
                foreach ($params as $key => $val) {
                    $stmt->bindValue(':' . $key, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
                }
                $stmt->execute();
                $totalItems = (int)$stmt->fetchColumn();

                // 2. Get paginated results
                $query = "SELECT * FROM admin_protocols $where ORDER BY id DESC";
                $useLimit = $pageNo !== null && $pageRow !== null && $pageNo > 0 && $pageRow > 0;
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
                $protocols = $stmt->fetchAll();

                $responseData = [
                    'items' => $protocols,
                    'total_items' => $totalItems,
                    'page_no' => $pageNo ?? 1,
                    'page_row' => $pageRow ?? $totalItems
                ];

                (new ApiResponse(true, 'Protocols retrieved successfully', $responseData))->send(200);
            }
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }

    /**
     * Create a new admin protocol.
     */
    public function post()
    {
        $user = AuthMiddleware::authorize(['admin']);

        try {
            $pdo = Database::getConnection();
            $data = RequestHelper::getBody();

            $protocolName = trim($data['protocol_name'] ?? '');
            if (empty($protocolName)) {
                (new ApiResponse(false, 'Protocol name is required'))->send(400);
            }

            $indication = $data['indication'] ?? null;
            $protocolVersion = $data['protocol_version'] ?? null;
            $isActive = isset($data['is_active']) ? filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN) : false;
            $createdBy = $user['data']['id'];

            $stmt = $pdo->prepare("
                INSERT INTO admin_protocols (protocol_name, indication, protocol_version, is_active, create_by, created_at, updated_at)
                VALUES (:protocol_name, :indication, :protocol_version, :is_active, :create_by, NOW(), NOW())
                RETURNING *
            ");

            $stmt->bindValue(':protocol_name', $protocolName, PDO::PARAM_STR);
            $stmt->bindValue(':indication', $indication, $indication === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':protocol_version', $protocolVersion, $protocolVersion === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':is_active', $isActive, PDO::PARAM_BOOL);
            $stmt->bindValue(':create_by', $createdBy, $createdBy === null ? PDO::PARAM_NULL : PDO::PARAM_INT);

            $stmt->execute();
            $newProtocol = $stmt->fetch();

            // Save uploaded documents
            $files = $_FILES['documents'] ?? $_FILES['documents[]'] ?? null;
            if ($files && !empty($files['name'][0])) {
                $uploadDir = __DIR__ . '/../../public/bck/administrator/protocols/';
                if (!is_dir($uploadDir)) {
                    if (!mkdir($uploadDir, 0777, true)) {
                        throw new \Exception("Failed to create upload directory: " . $uploadDir);
                    }
                }

                if (is_array($files['name'])) {
                    for ($i = 0; $i < count($files['name']); $i++) {
                        $errorCode = $files['error'][$i];
                        if ($errorCode !== UPLOAD_ERR_OK) {
                            $uploadErrors = [
                                UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the upload_max_filesize directive in php.ini.',
                                UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.',
                                UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded.',
                                UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
                                UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder.',
                                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
                                UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.'
                            ];
                            $errMsg = $uploadErrors[$errorCode] ?? 'Unknown upload error.';
                            throw new \Exception("File upload error for file '{$files['name'][$i]}': " . $errMsg);
                        }

                        $tmpName = $files['tmp_name'][$i];
                        $originalName = basename($files['name'][$i]);
                        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
                        $filename = pathinfo($originalName, PATHINFO_FILENAME);
                        $randomId = bin2hex(random_bytes(2));
                        $sanitizedName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $filename) . '_' . time() . '_' . $randomId . '.' . $extension;
                        $targetPath = $uploadDir . $sanitizedName;

                        if (!move_uploaded_file($tmpName, $targetPath)) {
                            throw new \Exception("Failed to move uploaded file '{$originalName}' to '{$targetPath}'");
                        }

                        $dbPath = 'public/bck/administrator/protocols/' . $sanitizedName;
                        $docStmt = $pdo->prepare("
                            INSERT INTO admin_protocol_documents (protocol_id, document_path)
                            VALUES (:protocol_id, :document_path)
                        ");
                        $docStmt->execute([
                            'protocol_id' => $newProtocol['id'],
                            'document_path' => $dbPath
                        ]);
                    }
                }
            }

            (new ApiResponse(true, 'Protocol created successfully', $newProtocol))->send(201);
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }

    /**
     * Update an existing admin protocol.
     */
    public function put()
    {
        try {
            $pdo = Database::getConnection();
            $data = RequestHelper::getBody();
            $user = AuthMiddleware::authorize(['admin']);

            $id = $_GET['id'] ?? $data['id'] ?? null;
            if ($id === null) {
                (new ApiResponse(false, 'Protocol ID is required'))->send(400);
            }

            // Check if protocol exists
            $stmt = $pdo->prepare("SELECT id FROM admin_protocols WHERE id = :id");
            $stmt->execute(['id' => $id]);
            if (!$stmt->fetch()) {
                (new ApiResponse(false, 'Protocol not found'))->send(404);
            }

            $protocolName = trim($data['protocol_name'] ?? '');
            if (empty($protocolName)) {
                (new ApiResponse(false, 'Protocol name is required'))->send(400);
            }

            $indication = $data['indication'] ?? null;
            $protocolVersion = $data['protocol_version'] ?? null;
            $isActive = isset($data['is_active']) ? filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN) : true;
            $updatedBy = $user['data']['id'];
            $stmt = $pdo->prepare("
                UPDATE admin_protocols
                SET protocol_name = :protocol_name,
                    indication = :indication,
                    protocol_version = :protocol_version,
                    is_active = :is_active,
                    updated_by = :updated_by,
                    updated_at = NOW()
                WHERE id = :id
                RETURNING *
            ");

            $stmt->bindValue(':protocol_name', $protocolName, PDO::PARAM_STR);
            $stmt->bindValue(':indication', $indication, $indication === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':protocol_version', $protocolVersion, $protocolVersion === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':is_active', $isActive, PDO::PARAM_BOOL);
            $stmt->bindValue(':updated_by', $updatedBy, $updatedBy === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);

            $stmt->execute();
            $updatedProtocol = $stmt->fetch();

            // Save uploaded documents
            $files = $_FILES['documents'] ?? $_FILES['documents[]'] ?? null;
            if ($files && !empty($files['name'][0])) {
                $uploadDir = __DIR__ . '/../../public/bck/administrator/protocols/';
                if (!is_dir($uploadDir)) {
                    if (!mkdir($uploadDir, 0777, true)) {
                        throw new \Exception("Failed to create upload directory: " . $uploadDir);
                    }
                }

                if (is_array($files['name'])) {
                    for ($i = 0; $i < count($files['name']); $i++) {
                        $errorCode = $files['error'][$i];
                        if ($errorCode !== UPLOAD_ERR_OK) {
                            $uploadErrors = [
                                UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the upload_max_filesize directive in php.ini.',
                                UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.',
                                UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded.',
                                UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
                                UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder.',
                                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
                                UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.'
                            ];
                            $errMsg = $uploadErrors[$errorCode] ?? 'Unknown upload error.';
                            throw new \Exception("File upload error for file '{$files['name'][$i]}': " . $errMsg);
                        }

                        $tmpName = $files['tmp_name'][$i];
                        $originalName = basename($files['name'][$i]);
                        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
                        $filename = pathinfo($originalName, PATHINFO_FILENAME);
                        $randomId = bin2hex(random_bytes(2));
                        $sanitizedName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $filename) . '_' . time() . '_' . $randomId . '.' . $extension;
                        $targetPath = $uploadDir . $sanitizedName;

                        if (!move_uploaded_file($tmpName, $targetPath)) {
                            throw new \Exception("Failed to move uploaded file '{$originalName}' to '{$targetPath}'");
                        }

                        $dbPath = 'public/bck/administrator/protocols/' . $sanitizedName;
                        $docStmt = $pdo->prepare("
                            INSERT INTO admin_protocol_documents (protocol_id, document_path)
                            VALUES (:protocol_id, :document_path)
                        ");
                        $docStmt->execute([
                            'protocol_id' => $id,
                            'document_path' => $dbPath
                        ]);
                    }
                }
            }

            (new ApiResponse(true, 'Protocol updated successfully', $updatedProtocol))->send(200);
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }

    /**
     * Delete an admin protocol.
     */
    public function delete()
    {
        try {
            $user = AuthMiddleware::authorize(['admin']);
            $pdo = Database::getConnection();
            $id = $_GET['id'] ?? null;

            if ($id === null) {
                $data = RequestHelper::getBody();
                $id = $data['id'] ?? null;
            }

            if ($id === null) {
                (new ApiResponse(false, 'Protocol ID is required'))->send(400);
            }

            // Check if protocol exists
            $stmt = $pdo->prepare("SELECT id FROM admin_protocols WHERE id = :id");
            $stmt->execute(['id' => $id]);
            if (!$stmt->fetch()) {
                (new ApiResponse(false, 'Protocol not found'))->send(404);
            }

            $stmt = $pdo->prepare("DELETE FROM admin_protocols WHERE id = :id");
            $stmt->execute(['id' => $id]);

            (new ApiResponse(true, 'Protocol deleted successfully'))->send(200);
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }

    /**
     * Retrieve E-CRF templates for a protocol.
     */
    public function getEcrf()
    {
        try {
            $pdo = Database::getConnection();
            $protocolId = $_GET['protocol_id'] ?? null;

            if ($protocolId === null) {
                (new ApiResponse(false, 'Protocol ID is required'))->send(400);
            }

            // Optional: get a specific section
            $sectionId = $_GET['section_id'] ?? null;

            if ($sectionId !== null) {
                $stmt = $pdo->prepare("SELECT * FROM admin_protocol_ecrfs WHERE protocol_id = :protocol_id AND section_id = :section_id");
                $stmt->execute([
                    'protocol_id' => $protocolId,
                    'section_id' => (int)$sectionId
                ]);
                $ecrf = $stmt->fetch();
                $data = $ecrf ? json_decode($ecrf['questions_schema'], true) : [];
                (new ApiResponse(true, 'E-CRF retrieved successfully', $data))->send(200);
            } else {
                // Return all sections grouped using LEFT JOIN with ecrf_sections lookup table
                $stmt = $pdo->prepare("
                    SELECT 
                        es.id AS section_id,
                        es.section_name,
                        ape.questions_schema
                    FROM ecrf_sections es
                    LEFT JOIN admin_protocol_ecrfs ape 
                        ON es.id = ape.section_id AND ape.protocol_id = :protocol_id
                    ORDER BY es.id ASC
                ");
                $stmt->execute(['protocol_id' => $protocolId]);
                $rows = $stmt->fetchAll();

                $rawSections = [
                    'persiapan' => [],
                    'pelaksanaan' => [],
                    'monitoring' => [],
                    'evaluasi' => [],
                ];

                $sectionMap = [
                    1 => 'persiapan',
                    2 => 'pelaksanaan',
                    3 => 'monitoring',
                    4 => 'evaluasi'
                ];

                foreach ($rows as $row) {
                    $secId = (int)$row['section_id'];
                    $secKey = $sectionMap[$secId] ?? ('section_' . $secId);
                    $rawSections[$secKey] = json_decode($row['questions_schema'] ?? '[]', true) ?: [];
                }

                $persiapanCount = count($rawSections['persiapan']);
                $pelaksanaanCount = count($rawSections['pelaksanaan']);
                $monitoringCount = count($rawSections['monitoring']);

                $isPelaksanaanLocked = ($persiapanCount === 0);
                $isMonitoringLocked = $isPelaksanaanLocked || ($pelaksanaanCount === 0);
                $isEvaluasiLocked = $isMonitoringLocked || ($monitoringCount === 0);

                $sections = [
                    'persiapan' => [
                        'questions' => $rawSections['persiapan'],
                        'is_locked' => false
                    ],
                    'pelaksanaan' => [
                        'questions' => $rawSections['pelaksanaan'],
                        'is_locked' => $isPelaksanaanLocked
                    ],
                    'monitoring' => [
                        'questions' => $rawSections['monitoring'],
                        'is_locked' => $isMonitoringLocked
                    ],
                    'evaluasi' => [
                        'questions' => $rawSections['evaluasi'],
                        'is_locked' => $isEvaluasiLocked
                    ]
                ];

                (new ApiResponse(true, 'E-CRF templates retrieved successfully', $sections))->send(200);
            }
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }

    /**
     * Save/Update E-CRF template for a protocol and section.
     */
    public function postEcrf()
    {
        $user = AuthMiddleware::authorize(['admin']);

        try {
            $pdo = Database::getConnection();
            $data = RequestHelper::getBody();

            $protocolId = $data['protocol_id'] ?? null;
            $sectionId = $data['section_id'] ?? null;
            $questionsSchema = $data['questions_schema'] ?? [];

            if ($protocolId === null || $sectionId === null) {
                (new ApiResponse(false, 'Protocol ID and Section ID are required'))->send(400);
            }

            if (!in_array((int)$sectionId, [1, 2, 3, 4])) {
                (new ApiResponse(false, 'Invalid Section ID. Must be 1, 2, 3, or 4'))->send(400);
            }

            $jsonSchema = json_encode($questionsSchema);
            $userId = $user['data']['id'];

            // PostgreSQL UPSERT (INSERT ... ON CONFLICT DO UPDATE)
            $stmt = $pdo->prepare("
                INSERT INTO admin_protocol_ecrfs (protocol_id, section_id, questions_schema, created_by, updated_by, created_at, updated_at)
                VALUES (:protocol_id, :section_id, :questions_schema::jsonb, :user_id, :user_id, NOW(), NOW())
                ON CONFLICT (protocol_id, section_id) 
                DO UPDATE SET 
                    questions_schema = EXCLUDED.questions_schema,
                    updated_by = EXCLUDED.updated_by,
                    updated_at = NOW()
                RETURNING *
            ");

            $stmt->bindValue(':protocol_id', $protocolId, PDO::PARAM_INT);
            $stmt->bindValue(':section_id', $sectionId, PDO::PARAM_INT);
            $stmt->bindValue(':questions_schema', $jsonSchema, PDO::PARAM_STR);
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);

            $stmt->execute();
            $result = $stmt->fetch();

            (new ApiResponse(true, 'E-CRF saved successfully', $result))->send(200);
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }
}
