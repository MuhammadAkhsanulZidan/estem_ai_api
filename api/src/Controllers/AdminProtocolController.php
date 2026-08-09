<?php

namespace App\Controllers;

use App\Config\Database;
use App\Middleware\AuthMiddleware;
use App\Models\ApiResponse;
use App\Helpers\RequestHelper;
use App\Constants\ROLE_NAME;

use PDO;

class AdminProtocolController
{
    /**
     * Retrieve admin protocols (paginated listing, no documents list fetched).
     */
    public function get()
    {
        AuthMiddleware::authorize([ROLE_NAME::ADMIN, ROLE_NAME::REVIEWER]);

        try {
            $pdo = Database::getConnection();

            $filterField = $_GET['filter_field'] ?? null;
            $filterValue = $_GET['filter_value'] ?? null;
            $pageNo = isset($_GET['page_no']) ? (int)$_GET['page_no'] : 1;
            $pageRow = isset($_GET['page_row']) ? (int)$_GET['page_row'] : 10;
            $allowedFields = ['protocol_name', 'indication', 'protocol_version', 'is_active'];

            $where = 'WHERE 1=1';
            $params = [];

            if ($filterField !== null && $filterValue !== null && in_array($filterField, $allowedFields)) {
                if ($filterField === 'is_active') {
                    $val = filter_var($filterValue, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
                    $where .= " AND is_active = :val";
                    $params['val'] = $val;
                } else {
                    $where .= " AND {$filterField} ILIKE :val";
                    $params['val'] = '%' . $filterValue . '%';
                }
            }

            $query = "SELECT * FROM admin_protocols {$where} ORDER BY id DESC";
            $tableName = "(SELECT id, protocol_name, indication FROM admin_protocols {$where}) A";

            $responseData = RequestHelper::paginate(
                pdo: $pdo,
                query: $query,
                tableName: $tableName,
                queryWhere: "",
                filterFields: ['protocol_name', 'indication'],
                params: $params
            );

            (new ApiResponse(true, 'Protocols retrieved successfully', $responseData))->send(200);
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }

    /**
     * Retrieve a single admin protocol by ID, including aggregated documents.
     */
    public function detail()
    {
        AuthMiddleware::authorize([ROLE_NAME::ADMIN, ROLE_NAME::REVIEWER]);

        try {
            $pdo = Database::getConnection();
            $id = $_GET['id'] ?? null;

            if ($id === null) {
                (new ApiResponse(false, 'Protocol ID is required'))->send(400);
                return;
            }

            $stmt = $pdo->prepare("
                SELECT ap.*,
                       COALESCE(
                           (
                               SELECT json_agg(json_build_object('id', doc.id, 'document_path', doc.document_path))
                               FROM admin_protocol_documents doc
                               WHERE doc.protocol_id = ap.id
                           ), '[]'::json
                       ) AS documents
                FROM admin_protocols ap
                WHERE ap.id = :id
            ");
            $stmt->execute(['id' => $id]);
            $protocol = $stmt->fetch();

            if (!$protocol) {
                (new ApiResponse(false, 'Protocol not found'))->send(404);
                return;
            }

            $protocol['documents'] = json_decode($protocol['documents'] ?? '[]', true);

            (new ApiResponse(true, 'Protocol details retrieved successfully', $protocol))->send(200);
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }

    /**
     * Create a new admin protocol.
     */
    public function post()
    {
        $user = AuthMiddleware::authorize([ROLE_NAME::ADMIN]);

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

                        // Synchronous chatbot ingestion
                        $this->ingestChatbotDocument($pdo, $targetPath, $sanitizedName);
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
            $user = AuthMiddleware::authorize([ROLE_NAME::ADMIN]);

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

                        // Synchronous chatbot ingestion
                        $this->ingestChatbotDocument($pdo, $targetPath, $sanitizedName);
                    }
                }
            }

            // Option B: Granular Deletion of documents
            $keepDocIdsInput = $data['keep_document_ids'] ?? null;
            if ($keepDocIdsInput !== null) {
                // Handle JSON-encoded array or direct list
                $keepDocIds = [];
                if (is_array($keepDocIdsInput)) {
                    $keepDocIds = array_map('intval', $keepDocIdsInput);
                } elseif (is_string($keepDocIdsInput)) {
                    $decoded = json_decode($keepDocIdsInput, true);
                    if (is_array($decoded)) {
                        $keepDocIds = array_map('intval', $decoded);
                    }
                }

                $docStmt = $pdo->prepare("SELECT id, document_path FROM admin_protocol_documents WHERE protocol_id = :protocol_id");
                $docStmt->execute(['protocol_id' => $id]);
                $currentDocs = $docStmt->fetchAll();

                $publicDir = __DIR__ . '/../../public/';
                foreach ($currentDocs as $doc) {
                    $docId = (int)$doc['id'];
                    if (!in_array($docId, $keepDocIds)) {
                        $absPath = realpath($publicDir . $doc['document_path']);
                        if ($absPath && file_exists($absPath) && is_file($absPath)) {
                            unlink($absPath);

                            // Clean up chatbot document and chunks
                            $delChatbotDoc = $pdo->prepare("DELETE FROM chatbot_documents WHERE file_path = :file_path");
                            $delChatbotDoc->execute(['file_path' => $absPath]);
                        }
                        
                        $delStmt = $pdo->prepare("DELETE FROM admin_protocol_documents WHERE id = :id");
                        $delStmt->execute(['id' => $docId]);
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
            $user = AuthMiddleware::authorize([ROLE_NAME::ADMIN]);
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

            // Retrieve documents first to delete physical files
            $docStmt = $pdo->prepare("SELECT document_path FROM admin_protocol_documents WHERE protocol_id = :id");
            $docStmt->execute(['id' => $id]);
            $docs = $docStmt->fetchAll();

            $publicDir = __DIR__ . '/../../public/';
            foreach ($docs as $doc) {
                $absPath = realpath($publicDir . $doc['document_path']);
                if ($absPath && file_exists($absPath) && is_file($absPath)) {
                    unlink($absPath);

                    // Clean up chatbot document and chunks
                    $delChatbotDoc = $pdo->prepare("DELETE FROM chatbot_documents WHERE file_path = :file_path");
                    $delChatbotDoc->execute(['file_path' => $absPath]);
                }
            }

            $stmt = $pdo->prepare("DELETE FROM admin_protocols WHERE id = :id");
            $stmt->execute(['id' => $id]);

            (new ApiResponse(true, 'Protocol deleted successfully'))->send(200);
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }



    /**
     * Parse document and insert chunks/embeddings/intents into chatbot tables in the background.
     */
    private function ingestChatbotDocument(\PDO $pdo, string $targetPath, string $sanitizedName)
    {
        $tempChatbotDir = __DIR__ . '/../../public/bck/chatbot_documents/';
        if (!is_dir($tempChatbotDir)) {
            mkdir($tempChatbotDir, 0777, true);
        }
        $tempPath = $tempChatbotDir . $sanitizedName;
        copy($targetPath, $tempPath);
        
        $scriptPath = __DIR__ . '/../../scripts/ingest_single_document.php';
        $cmd = "php " . escapeshellarg($scriptPath) . " " . escapeshellarg($tempPath) . " " . escapeshellarg($targetPath) . " " . escapeshellarg($sanitizedName) . " > /dev/null 2>&1 &";
        shell_exec($cmd);
    }
}
