<?php

namespace App\Controllers;

use App\Config\Database;
use App\Middleware\AuthMiddleware;
use App\Models\ApiResponse;
use App\Helpers\RequestHelper;
use App\Helpers\StatusHelper;
use PDO;

class AffiliatorProtocolController
{
    /**
    * Retrieve affiliator protocols (all or single by ID).
    */
    public function get()
    {
        try {
            $pdo = Database::getConnection();
            $id = $_GET['id'] ?? null;

            $user = AuthMiddleware::authorize(['affiliator', 'admin', 'reviewer']);
            $roleName = $user['data']['role_name'] ?? '';
            $userId = $user['data']['id'] ?? null;

            $affiliatorId = null;
            if ($roleName === 'affiliator') {
                $stmtUser = $pdo->prepare("SELECT affiliator_id FROM users WHERE id = :id");
                $stmtUser->execute(['id' => $userId]);
                $affiliatorId = $stmtUser->fetchColumn();
                if (!$affiliatorId) {
                    (new ApiResponse(false, 'User has no associated affiliator'))->send(403);
                    return;
                }
            }

            if ($id !== null) {
                $protocol = Database::fetch("
                    SELECT
                        ap.*,
                        COALESCE(
                            (
                                SELECT json_agg(json_build_object('id', doc.id, 'document_path', doc.document_path))
                                FROM affiliator_protocol_documents doc
                                WHERE doc.protocol_id = ap.id
                            ), '[]'::json
                        ) AS documents
                    FROM affiliator_protocols ap
                    WHERE ap.id = :id
                ", ['id' => $id]);

                if (!$protocol) {
                    (new ApiResponse(false, 'Protocol not found'))->send(404);
                    return;
                }

                if ($affiliatorId !== null && (int)$protocol['affiliator_id'] !== (int)$affiliatorId) {
                    (new ApiResponse(false, 'Forbidden: You do not own this protocol'))->send(403);
                    return;
                }

                $protocol['documents'] = json_decode($protocol['documents'] ?? '[]', true);
                $protocol['status_id'] = StatusHelper::resolveStatus($protocol);

                (new ApiResponse(true, 'Protocol retrieved successfully', $protocol))->send(200);
                return;
            }

            $params = [];
            $statusConditions = [];

            if ($affiliatorId !== null) {
                $statusConditions[] = "ap.affiliator_id = :affiliator_id";
                $params['affiliator_id'] = $affiliatorId;
            }

            $isPosted   = $_GET['is_posted'] ?? "";
            $isRevised  = $_GET['is_revised'] ?? "";
            $isReviewed = $_GET['is_reviewed'] ?? "";
            $isApproved = $_GET['is_approved'] ?? "";


            // Status Filters
            if ($isPosted !== ""){
                $statusConditions[] = "ap.is_posted = :is_posted";
                $params['is_posted'] = ($isPosted === "1" || $isPosted === "true") ? 'true' : 'false';
            }
            if ($isReviewed !== ""){
                $statusConditions[] = "ap.is_reviewed = :is_reviewed";
                $params['is_reviewed'] = ($isReviewed === "1" || $isReviewed === "true") ? 'true' : 'false';
            }
            if ($isRevised !== ""){
                $statusConditions[] = "ap.is_revised = :is_revised";
                $params['is_revised'] = ($isRevised === "1" || $isRevised === "true") ? 'true' : 'false';
            }
            if ($isApproved !== ""){
                $statusConditions[] = "ap.is_approved = :is_approved";
                $params['is_approved'] = ($isApproved === "1" || $isApproved === "true") ? 'true' : 'false';
            }

            $statusWhere = "";
            if (!empty($statusConditions)) {
                $statusWhere = "WHERE " . implode(" AND ", $statusConditions);
            }

            // Base query with status filtering inside the inner query
            $query = "
                SELECT * FROM (
                    SELECT
                        ap.*, aff.affiliator_name,
                        COALESCE(
                            (
                                SELECT json_agg(json_build_object('id', doc.id, 'document_path', doc.document_path))
                                FROM affiliator_protocol_documents doc
                                WHERE doc.protocol_id = ap.id
                            ), '[]'::json
                        ) AS documents
                    FROM affiliator_protocols ap
                    LEFT JOIN affiliators aff ON ap.affiliator_id = aff.id
                    {$statusWhere}
                    ORDER BY ap.id DESC
                ) A
            ";

            // Dynamic table expression for pagination counting
            $tableName = "(SELECT ap.*, aff.affiliator_name FROM affiliator_protocols ap LEFT JOIN affiliators aff ON ap.affiliator_id = aff.id {$statusWhere}) A";

            $responseData = RequestHelper::paginate(
                pdo: $pdo,
                query: $query,
                tableName: $tableName,
                params: $params,
                filterFields: ['protocol_name', 'affiliator_name'],
                mutateItems: function ($items) {
                    foreach ($items as &$p) {
                        $p['documents'] = json_decode($p['documents'] ?? '[]', true);
                        $p['status_id'] = StatusHelper::resolveStatus($p);
                    }
                    return $items;
                }
            );

            (new ApiResponse(true, 'Protocols retrieved successfully', $responseData))->send(200);

        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }

    /**
     * Create a new affiliator protocol.
     */
    public function post()
    {
        $user = AuthMiddleware::authorize(['affiliator', 'admin']);

        try {
            $pdo = Database::getConnection();

            // Support both JSON body and multipart/form-data POST fields
            $data = $_POST;
            if (empty($data)) {
                $data = RequestHelper::getBody();
            }

            $roleName = strtolower($user['data']['role_name'] ?? '');
            $userId = $user['data']['id'] ?? null;

            $affiliatorId = $data['affiliator_id'] ?? null;
            if ($roleName === 'affiliator' && $userId !== null) {
                $stmtUser = $pdo->prepare("SELECT affiliator_id FROM users WHERE id = :id");
                $stmtUser->execute(['id' => $userId]);
                $dbAffiliatorId = $stmtUser->fetchColumn();
                if ($dbAffiliatorId) {
                    $affiliatorId = $dbAffiliatorId;
                }
            }

            $protocolName = trim($data['protocol_name'] ?? '');
            $statusId = trim($data['status_id'] ?? '');

            if ($affiliatorId === null || empty($protocolName) || empty($statusId)) {
                (new ApiResponse(false, 'affiliator_id, protocol_name, and status_id are required'))->send(400);
            }

            $indication = $data['indication'] ?? null;
            $protocolVersion = $data['protocol_version'] ?? null;
            $protocolReferenceId = isset($data['protocol_reference_id']) && $data['protocol_reference_id'] !== "" ? (int)$data['protocol_reference_id'] : null;
            $creatorNote = $data['creator_note'] ?? null;
            $reviewerNote = $data['reviewer_note'] ?? null;
            $createdBy = $user['data']['id'] ?? null;

            $flags = StatusHelper::mapStatusToFlags($statusId);

            $stmt = $pdo->prepare("
                INSERT INTO affiliator_protocols (affiliator_id, protocol_reference_id, protocol_name, indication, protocol_version, is_posted, is_reviewed, is_revised, is_approved, creator_note, reviewer_note, create_by, created_at, updated_at)
                VALUES (:affiliator_id, :protocol_reference_id, :protocol_name, :indication, :protocol_version, :is_posted, :is_reviewed, :is_revised, :is_approved, :creator_note, :reviewer_note, :create_by, NOW(), NOW())
                RETURNING *
            ");

            $stmt->bindValue(':affiliator_id', $affiliatorId, PDO::PARAM_INT);
            $stmt->bindValue(':protocol_reference_id', $protocolReferenceId, $protocolReferenceId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindValue(':protocol_name', $protocolName, PDO::PARAM_STR);
            $stmt->bindValue(':indication', $indication, $indication === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':protocol_version', $protocolVersion, $protocolVersion === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':is_posted', $flags['is_posted'], PDO::PARAM_BOOL);
            $stmt->bindValue(':is_reviewed', $flags['is_reviewed'], PDO::PARAM_BOOL);
            $stmt->bindValue(':is_revised', $flags['is_revised'], PDO::PARAM_BOOL);
            $stmt->bindValue(':is_approved', $flags['is_approved'], PDO::PARAM_BOOL);
            $stmt->bindValue(':creator_note', $creatorNote, $creatorNote === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':reviewer_note', $reviewerNote, $reviewerNote === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':create_by', $createdBy, $createdBy === null ? PDO::PARAM_NULL : PDO::PARAM_INT);

            $stmt->execute();
            $newProtocol = $stmt->fetch();

            // Fetch affiliator code to determine subfolder pathing
            $codeStmt = $pdo->prepare("SELECT affiliator_code FROM affiliators WHERE id = :id");
            $codeStmt->execute(['id' => $affiliatorId]);
            $affiliatorCode = $codeStmt->fetchColumn() ?: 'default';

            // Process File Uploads dynamically
            if (!empty($_FILES)) {
                $protocolsBaseDir = __DIR__ . '/../../public/bck/affiliator/protocols/';
                if (!is_dir($protocolsBaseDir)) {
                    if (!mkdir($protocolsBaseDir, 0777, true)) {
                        throw new \Exception("Failed to create protocols base directory: " . $protocolsBaseDir);
                    }
                    @chmod($protocolsBaseDir, 0777);
                }

                $uploadDir = $protocolsBaseDir . $affiliatorCode . '/' . $newProtocol['id'] . '/';
                if (!is_dir($uploadDir)) {
                    if (!mkdir($uploadDir, 0777, true)) {
                        throw new \Exception("Failed to create target protocol directory: " . $uploadDir);
                    }
                    @chmod($uploadDir, 0777);
                }

                foreach ($_FILES as $key => $file) {
                    if ($file['error'] === UPLOAD_ERR_OK) {
                        $originalName = basename($file['name']);
                        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
                        $filename = pathinfo($originalName, PATHINFO_FILENAME);
                        $randomId = bin2hex(random_bytes(2));
                        $sanitizedName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $filename) . '_' . time() . '_' . $randomId . '.' . $extension;
                        $targetPath = $uploadDir . $sanitizedName;

                        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
                            throw new \Exception("Failed to move uploaded file to target path: " . $targetPath);
                        }

                        $dbPath = 'public/bck/affiliator/protocols/' . $affiliatorCode . '/' . $newProtocol['id'] . '/' . $sanitizedName;

                        $insStmt = $pdo->prepare("
                            INSERT INTO affiliator_protocol_documents (protocol_id, document_path)
                            VALUES (:protocol_id, :document_path)
                        ");
                        $insStmt->execute([
                            'protocol_id' => $newProtocol['id'],
                            'document_path' => $dbPath
                        ]);
                    }
                }
            }

            // Retrieve newly created protocol with its documents
            $docStmt = $pdo->prepare("SELECT id, document_path FROM affiliator_protocol_documents WHERE protocol_id = :protocol_id");
            $docStmt->execute(['protocol_id' => $newProtocol['id']]);
            $newProtocol['documents'] = $docStmt->fetchAll() ?: [];
            $newProtocol['status_id'] = StatusHelper::resolveStatus($newProtocol);

            (new ApiResponse(true, 'Protocol created successfully', $newProtocol))->send(201);
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }

    /**
     * Update an existing affiliator protocol.
     */
    public function put()
    {
        $user = AuthMiddleware::authorize(['affiliator', 'admin']);

        try {
            $pdo = Database::getConnection();
            $data = RequestHelper::getBody();

            $id = $_GET['id'] ?? $data['id'] ?? null;
            if ($id === null) {
                (new ApiResponse(false, 'Protocol ID is required'))->send(400);
            }

            // Check if protocol exists and get existing row
            $stmt = $pdo->prepare("SELECT * FROM affiliator_protocols WHERE id = :id");
            $stmt->execute(['id' => $id]);
            $existing = $stmt->fetch();
            if (!$existing) {
                (new ApiResponse(false, 'Protocol not found'))->send(404);
            }

            $roleName = strtolower($user['data']['role_name'] ?? '');
            $userId = $user['data']['id'] ?? null;

            $affiliatorId = $data['affiliator_id'] ?? $existing['affiliator_id'];
            if ($roleName === 'affiliator' && $userId !== null) {
                $stmtUser = $pdo->prepare("SELECT affiliator_id FROM users WHERE id = :id");
                $stmtUser->execute(['id' => $userId]);
                $dbAffiliatorId = $stmtUser->fetchColumn();
                if ($dbAffiliatorId) {
                    $affiliatorId = $dbAffiliatorId;
                }
            }
            $protocolName = isset($data['protocol_name']) ? trim($data['protocol_name']) : $existing['protocol_name'];
            $statusId = isset($data['status_id']) ? trim($data['status_id']) : StatusHelper::resolveStatus($existing);

            if ($affiliatorId === null || empty($protocolName) || empty($statusId)) {
                (new ApiResponse(false, 'affiliator_id, protocol_name, and status_id are required'))->send(400);
            }

            $indication = isset($data['indication']) ? $data['indication'] : $existing['indication'];
            $protocolVersion = isset($data['protocol_version']) ? $data['protocol_version'] : $existing['protocol_version'];
            $protocolReferenceId = isset($data['protocol_reference_id']) && $data['protocol_reference_id'] !== "" ? (int)$data['protocol_reference_id'] : $existing['protocol_reference_id'];
            $creatorNote = isset($data['creator_note']) ? $data['creator_note'] : $existing['creator_note'];
            $reviewerNote = isset($data['reviewer_note']) ? $data['reviewer_note'] : $existing['reviewer_note'];
            $updatedBy = $user['data']['id'] ?? null;

            $flags = StatusHelper::mapStatusToFlags($statusId);

            $stmt = $pdo->prepare("
                UPDATE affiliator_protocols
                SET affiliator_id = :affiliator_id,
                    protocol_reference_id = :protocol_reference_id,
                    protocol_name = :protocol_name,
                    indication = :indication,
                    protocol_version = :protocol_version,
                    is_posted = :is_posted,
                    is_reviewed = :is_reviewed,
                    is_revised = :is_revised,
                    is_approved = :is_approved,
                    creator_note = :creator_note,
                    reviewer_note = :reviewer_note,
                    updated_by = :updated_by,
                    updated_at = NOW()
                WHERE id = :id
                RETURNING *
            ");

            $stmt->bindValue(':affiliator_id', $affiliatorId, PDO::PARAM_INT);
            $stmt->bindValue(':protocol_reference_id', $protocolReferenceId, $protocolReferenceId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindValue(':protocol_name', $protocolName, PDO::PARAM_STR);
            $stmt->bindValue(':indication', $indication, $indication === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':protocol_version', $protocolVersion, $protocolVersion === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':is_posted', $flags['is_posted'], PDO::PARAM_BOOL);
            $stmt->bindValue(':is_reviewed', $flags['is_reviewed'], PDO::PARAM_BOOL);
            $stmt->bindValue(':is_revised', $flags['is_revised'], PDO::PARAM_BOOL);
            $stmt->bindValue(':is_approved', $flags['is_approved'], PDO::PARAM_BOOL);
            $stmt->bindValue(':creator_note', $creatorNote, $creatorNote === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':reviewer_note', $reviewerNote, $reviewerNote === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':updated_by', $updatedBy, $updatedBy === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);

            $stmt->execute();
            $updatedProtocol = $stmt->fetch();

            // Delete removed documents
            $keepIds = isset($data['keep_document_ids']) ? json_decode($data['keep_document_ids'], true) : null;
            if (is_array($keepIds)) {
                $docStmt = $pdo->prepare("SELECT id, document_path FROM affiliator_protocol_documents WHERE protocol_id = :id");
                $docStmt->execute(['id' => $id]);
                $existingDocs = $docStmt->fetchAll();

                $publicDir = __DIR__ . '/../../public/';
                foreach ($existingDocs as $doc) {
                    if (!in_array($doc['id'], $keepIds)) {
                        // Delete physical file
                        $absPath = realpath($publicDir . $doc['document_path']);
                        if ($absPath && file_exists($absPath) && is_file($absPath)) {
                            unlink($absPath);
                        }
                        // Delete DB record
                        $delStmt = $pdo->prepare("DELETE FROM affiliator_protocol_documents WHERE id = :id");
                        $delStmt->execute(['id' => $doc['id']]);
                    }
                }
            }

            // Fetch affiliator code to determine subfolder pathing
            $codeStmt = $pdo->prepare("SELECT affiliator_code FROM affiliators WHERE id = :id");
            $codeStmt->execute(['id' => $affiliatorId]);
            $affiliatorCode = $codeStmt->fetchColumn() ?: 'default';

            // Process newly uploaded documents
            if (!empty($_FILES)) {
                $protocolsBaseDir = __DIR__ . '/../../public/bck/affiliator/protocols/';
                if (!is_dir($protocolsBaseDir)) {
                    if (!mkdir($protocolsBaseDir, 0777, true)) {
                        throw new \Exception("Failed to create protocols base directory: " . $protocolsBaseDir);
                    }
                    @chmod($protocolsBaseDir, 0777);
                }

                $uploadDir = $protocolsBaseDir . $affiliatorCode . '/' . $id . '/';
                if (!is_dir($uploadDir)) {
                    if (!mkdir($uploadDir, 0777, true)) {
                        throw new \Exception("Failed to create target protocol directory: " . $uploadDir);
                    }
                    @chmod($uploadDir, 0777);
                }

                $filesToProcess = [];
                foreach ($_FILES as $fileKey => $fileVal) {
                    if (is_array($fileVal['name'])) {
                        for ($i = 0; $i < count($fileVal['name']); $i++) {
                            if ($fileVal['error'][$i] === UPLOAD_ERR_OK) {
                                $filesToProcess[] = [
                                    'name' => $fileVal['name'][$i],
                                    'tmp_name' => $fileVal['tmp_name'][$i],
                                ];
                            }
                        }
                    } else {
                        if ($fileVal['error'] === UPLOAD_ERR_OK) {
                            $filesToProcess[] = [
                                'name' => $fileVal['name'],
                                'tmp_name' => $fileVal['tmp_name'],
                            ];
                        }
                    }
                }

                foreach ($filesToProcess as $f) {
                    $originalName = basename($f['name']);
                    $extension = pathinfo($originalName, PATHINFO_EXTENSION);
                    $filename = pathinfo($originalName, PATHINFO_FILENAME);
                    $randomId = bin2hex(random_bytes(2));
                    $sanitizedName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $filename) . '_' . time() . '_' . $randomId . '.' . $extension;
                    $targetPath = $uploadDir . $sanitizedName;

                    if (!move_uploaded_file($f['tmp_name'], $targetPath)) {
                        throw new \Exception("Failed to move uploaded file to target path: " . $targetPath);
                    }

                    $dbPath = 'public/bck/affiliator/protocols/' . $affiliatorCode . '/' . $id . '/' . $sanitizedName;

                    $insStmt = $pdo->prepare("
                        INSERT INTO affiliator_protocol_documents (protocol_id, document_path)
                        VALUES (:protocol_id, :document_path)
                    ");
                    $insStmt->execute([
                        'protocol_id' => $id,
                        'document_path' => $dbPath
                    ]);
                }
            }

            // Retrieve updated row with documents
            $finalDocStmt = $pdo->prepare("SELECT id, document_path FROM affiliator_protocol_documents WHERE protocol_id = :id");
            $finalDocStmt->execute(['id' => $id]);
            $updatedProtocol['documents'] = $finalDocStmt->fetchAll() ?: [];
            $updatedProtocol['status_id'] = StatusHelper::resolveStatus($updatedProtocol);

            (new ApiResponse(true, 'Protocol updated successfully', $updatedProtocol))->send(200);
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }

    /**
     * Delete an affiliator protocol.
     */
    public function delete()
    {
        $user = AuthMiddleware::authorize(['affiliator', 'admin']);

        try {
            $pdo = Database::getConnection();
            $id = $_GET['id'] ?? null;

            if ($id === null) {
                (new ApiResponse(false, 'Protocol ID is required'))->send(400);
            }

            // Delete associated documents
            $docStmt = $pdo->prepare("SELECT document_path FROM affiliator_protocol_documents WHERE protocol_id = :protocol_id");
            $docStmt->execute(['protocol_id' => $id]);
            $docs = $docStmt->fetchAll();

            $publicDir = __DIR__ . '/../../public/';
            foreach ($docs as $doc) {
                $absPath = realpath($publicDir . $doc['document_path']);
                if ($absPath && file_exists($absPath) && is_file($absPath)) {
                    unlink($absPath);
                }
            }

            $delDocs = $pdo->prepare("DELETE FROM affiliator_protocol_documents WHERE protocol_id = :protocol_id");
            $delDocs->execute(['protocol_id' => $id]);

            $stmt = $pdo->prepare("DELETE FROM affiliator_protocols WHERE id = :id");
            $stmt->execute(['id' => $id]);

            (new ApiResponse(true, 'Protocol and associated documents deleted successfully'))->send(200);
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }

    public function getReviewList(): void
    {
        AuthMiddleware::authorize(['reviewer', 'admin']);
        try {
            $pdo = Database::getConnection();

            $filterValue = $_GET['filter_value'] ?? '';
            $status = $_GET['status'] ?? '';
            $pageNo = isset($_GET['page_no']) ? (int)$_GET['page_no'] : 1;
            $pageRow = isset($_GET['page_row']) ? (int)$_GET['page_row'] : 8;
            $offset = ($pageNo - 1) * $pageRow;

            $whereParts = ["ap.is_posted = true"];
            $params = [];

            if (!empty($filterValue)) {
                $whereParts[] = "(ap.protocol_name ILIKE :filter OR ap.indication ILIKE :filter OR a.affiliator_name ILIKE :filter)";
                $params['filter'] = "%{$filterValue}%";
            }

            if (!empty($status)) {
                if (in_array($status, ['submitted', 'review'])) {
                    $whereParts[] = "(ap.is_posted = true AND ap.is_reviewed = false)";
                } elseif ($status === 'revision') {
                    $whereParts[] = "(ap.is_posted = true AND ap.is_reviewed = true AND ap.is_revised = true)";
                } elseif ($status === 'approved') {
                    $whereParts[] = "(ap.is_posted = true AND ap.is_reviewed = true AND ap.is_approved = true)";
                } elseif ($status === 'rejected') {
                    $whereParts[] = "(ap.is_posted = true AND ap.is_reviewed = true AND ap.is_approved = false AND ap.is_revised = false)";
                }
            }

            $whereSql = implode(' AND ', $whereParts);

            $countSql = "
                SELECT COUNT(*)
                FROM affiliator_protocols ap
                JOIN affiliators a ON ap.affiliator_id = a.id
                WHERE $whereSql
            ";
            $stmtCount = $pdo->prepare($countSql);
            $stmtCount->execute($params);
            $totalItems = $stmtCount->fetchColumn();

            $sql = "
                SELECT
                    ap.*,
                    a.affiliator_name as hospital_name
                FROM affiliator_protocols ap
                JOIN affiliators a ON ap.affiliator_id = a.id
                WHERE $whereSql
                ORDER BY ap.created_at DESC
                LIMIT :limit OFFSET :offset
            ";

            $stmt = $pdo->prepare($sql);
            foreach ($params as $k => $v) {
                $stmt->bindValue(":$k", $v);
            }
            $stmt->bindValue(':limit', $pageRow, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();

            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Fetch documents
            if (!empty($items)) {
                $ids = array_column($items, 'id');
                $inQuery = implode(',', array_fill(0, count($ids), '?'));
                $docStmt = $pdo->prepare("SELECT id, protocol_id, document_path FROM affiliator_protocol_documents WHERE protocol_id IN ($inQuery)");
                $docStmt->execute($ids);
                $docs = $docStmt->fetchAll(PDO::FETCH_ASSOC);

                $docsByProtocol = [];
                foreach ($docs as $doc) {
                    $docsByProtocol[$doc['protocol_id']][] = [
                        'id' => $doc['id'],
                        'document_path' => $doc['document_path']
                    ];
                }

                foreach ($items as &$item) {
                    $item['documents'] = $docsByProtocol[$item['id']] ?? [];
                    $item['status_id'] = StatusHelper::resolveStatus($item);
                }
            }

            (new ApiResponse(true, 'Data fetched successfully', [
                'items' => $items,
                'total_items' => $totalItems,
                'page_no' => $pageNo,
                'page_row' => $pageRow
            ]))->send(200);

        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }

    public function reviewProtocol(): void
    {
        AuthMiddleware::authorize(['reviewer', 'admin']);
        try {
            $data = RequestHelper::getBody();

            if (empty($data['id']) || empty($data['decision'])) {
                (new ApiResponse(false, 'Missing required fields: id, decision'))->send(400);
                return;
            }

            $id = $data['id'];
            $decision = $data['decision'];
            $reviewerNote = $data['reviewer_note'] ?? null;

            $statusId = '';
            if ($decision === 'approve') {
                $statusId = 'approved';
            } elseif ($decision === 'revision') {
                $statusId = 'revision';
            } elseif ($decision === 'reject') {
                $statusId = 'rejected';
            } else {
                (new ApiResponse(false, 'Invalid decision'))->send(400);
                return;
            }

            $flags = StatusHelper::mapStatusToFlags($statusId);

            $pdo = Database::getConnection();

            // Check if exists
            $stmt = $pdo->prepare("SELECT id FROM affiliator_protocols WHERE id = :id");
            $stmt->execute(['id' => $id]);
            if (!$stmt->fetch()) {
                (new ApiResponse(false, 'Protocol not found'))->send(404);
                return;
            }

            // Update
            $updateSql = "
                UPDATE affiliator_protocols
                SET is_posted = :is_posted,
                    is_reviewed = :is_reviewed,
                    is_revised = :is_revised,
                    is_approved = :is_approved,
                    reviewer_note = :reviewer_note
                WHERE id = :id
                RETURNING *
            ";
            $updateStmt = $pdo->prepare($updateSql);
            $updateStmt->execute([
                'is_posted' => $flags['is_posted'] ? 1 : 0,
                'is_reviewed' => $flags['is_reviewed'] ? 1 : 0,
                'is_revised' => $flags['is_revised'] ? 1 : 0,
                'is_approved' => $flags['is_approved'] ? 1 : 0,
                'reviewer_note' => $reviewerNote,
                'id' => $id
            ]);

            $updatedRow = $updateStmt->fetch(PDO::FETCH_ASSOC);
            $updatedRow['status_id'] = StatusHelper::resolveStatus($updatedRow);

            (new ApiResponse(true, 'Review decision saved', $updatedRow))->send(200);

        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }
}
