<?php

namespace App\Controllers;

use App\Config\Database;
use App\Middleware\AuthMiddleware;
use App\Models\ApiResponse;
use App\Helpers\RequestHelper;
use PDO;

class AffiliatorSupervisionController
{
    /**
     * Get active supervision registration details and documents.
     */
     public function get()
    {
        $user = AuthMiddleware::authorize(['affiliator', 'admin', 'reviewer']);

        try {
            $pdo = Database::getConnection();
            $userId = $user['data']['id'];

            // 1. Resolve affiliator_id
            $affiliatorId = $_GET['affiliator_id'] ?? null;
            if ($affiliatorId === null) {
                $affStmt = $pdo->prepare("SELECT affiliator_id FROM users WHERE id = :user_id");
                $affStmt->execute(['user_id' => $userId]);
                $affRow = $affStmt->fetch();
                $affiliatorId = $affRow ? $affRow['affiliator_id'] : null;
            }

            if ($affiliatorId === null) {
                if ($user['data']['role_name'] == "admin" || $user['data']['role_name'] == "reviewer") {
                    $filterField = $_GET['filter_field'] ?? "";
                    $filterValue = $_GET['filter_value'] ?? "";
                    $status = $_GET['status'] ?? "";
                    $pageNo = isset($_GET['page_no']) ? (int)$_GET['page_no'] : 1;
                    $pageRow = isset($_GET['page_row']) ? (int)$_GET['page_row'] : 10;
                    $useLimit = $pageNo > 0 && $pageRow > 0;

                    $where = 'WHERE 1=1';
                    $params = [];

                    // Allowed search fields map to SQL columns
                    $allowedFields = [
                        'hospital_name' => 'aff.affiliator_name',
                        'pic_name' => 'asup.pic_name',
                        'status' => 'asup.status'
                    ];

                    // Filter field & search value
                    if ($filterField !== "" && $filterValue !== "" && isset($allowedFields[$filterField])) {
                        $column = $allowedFields[$filterField];
                        $where .= " AND {$column} ILIKE :val";
                        $params['val'] = '%' . $filterValue . '%';
                    } else if ($filterValue !== "") {
                        // Default search across hospital_name and pic_name
                        $where .= " AND (aff.affiliator_name ILIKE :val OR asup.pic_name ILIKE :val)";
                        $params['val'] = '%' . $filterValue . '%';
                    }

                    // Explicit status filter
                    if ($status !== "" && $status !== "Semua") {
                        $where .= " AND asup.status ILIKE :status";
                        $params['status'] = $status;
                    }

                    // 1a. Get total items count
                    $countQuery = "
                        SELECT COUNT(*)
                        FROM affiliator_supervisions asup
                        JOIN affiliators aff ON asup.affiliator_id = aff.id
                        {$where}
                    ";
                    $countStmt = $pdo->prepare($countQuery);
                    foreach ($params as $key => $val) {
                        $countStmt->bindValue(':' . $key, $val, PDO::PARAM_STR);
                    }
                    $countStmt->execute();
                    $totalItems = (int)$countStmt->fetchColumn();

                    // 1b. Query with pagination and filtering
                    $query = "
                        SELECT
                            asup.*,
                            aff.affiliator_name AS hospital_name,
                            aff.affiliator_type AS institution_type,
                            aff.address
                        FROM affiliator_supervisions asup
                        JOIN affiliators aff ON asup.affiliator_id = aff.id
                        {$where}
                        ORDER BY asup.id DESC
                    ";

                    if ($useLimit) {
                        $offset = ($pageNo - 1) * $pageRow;
                        $query .= " LIMIT :limit OFFSET :offset";
                    }

                    $stmt = $pdo->prepare($query);
                    foreach ($params as $key => $val) {
                        $stmt->bindValue(':' . $key, $val, PDO::PARAM_STR);
                    }
                    if ($useLimit) {
                        $stmt->bindValue(':limit', $pageRow, PDO::PARAM_INT);
                        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
                    }

                    $stmt->execute();
                    $supervisions = $stmt->fetchAll();

                    // 1c. Attach nested document relations
                    foreach ($supervisions as &$sup) {
                        $docStmt = $pdo->prepare("
                            SELECT id, document_path
                            FROM affiliator_supervision_documents
                            WHERE supervision_id = :id
                            ORDER BY id DESC
                        ");
                        $docStmt->execute(['id' => $sup['id']]);
                        $sup['documents'] = $docStmt->fetchAll();
                    }

                    $responseData = [
                        'items' => $supervisions,
                        'total_items' => $totalItems,
                        'page_no' => $pageNo,
                        'page_row' => $pageRow
                    ];

                    (new ApiResponse(true, 'All supervisions retrieved successfully', $responseData))->send(200);
                } else {
                    (new ApiResponse(true, 'No affiliator associated', null))->send(200);
                }
            }

            // 2. Single Affiliator detail branch (returns single record, no pagination)
            $stmt = $pdo->prepare("
                SELECT
                    asup.*,
                    aff.affiliator_name AS hospital_name,
                    aff.affiliator_type AS institution_type,
                    aff.address
                FROM affiliator_supervisions asup
                JOIN affiliators aff ON asup.affiliator_id = aff.id
                WHERE asup.affiliator_id = :affiliator_id
            ");
            $stmt->execute(['affiliator_id' => $affiliatorId]);
            $supervision = $stmt->fetch();

            if (!$supervision) {
                // Return default fallback structure if no registration created yet
                $affStmt = $pdo->prepare("SELECT * FROM affiliators WHERE id = :id");
                $affStmt->execute(['id' => $affiliatorId]);
                $aff = $affStmt->fetch();

                $defaultData = [
                    'id' => null,
                    'affiliator_id' => $affiliatorId,
                    'pic_name' => '',
                    'status' => 'draft',
                    'review_notes' => null,
                    'hospital_name' => $aff ? $aff['affiliator_name'] : '',
                    'institution_type' => $aff ? $aff['affiliator_type'] : '',
                    'address' => $aff ? $aff['address'] : '',
                    'documents' => []
                ];
                (new ApiResponse(true, 'No supervision progress found, returning profile defaults', $defaultData))->send(200);
            }

            // 3. Fetch documents list
            $docStmt = $pdo->prepare("
                SELECT id, document_path
                FROM affiliator_supervision_documents
                WHERE supervision_id = :supervision_id
                ORDER BY id DESC
            ");
            $docStmt->execute(['supervision_id' => $supervision['id']]);
            $supervision['documents'] = $docStmt->fetchAll();

            (new ApiResponse(true, 'Supervision details retrieved successfully', $supervision))->send(200);
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }
    /**
     * Upsert supervision registration application progress.
     */
    public function post()
    {
        $user = AuthMiddleware::authorize(['affiliator']);

        try {
            $pdo = Database::getConnection();
            $userId = $user['data']['id'];
            $data = $_POST; // Read from $_POST directly as request carries multipart/form-data for uploads

            // 1. Resolve affiliator_id
            $affStmt = $pdo->prepare("SELECT affiliator_id FROM users WHERE id = :user_id");
            $affStmt->execute(['user_id' => $userId]);
            $affRow = $affStmt->fetch();
            $affiliatorId = $affRow ? $affRow['affiliator_id'] : null;

            if ($affiliatorId === null) {
                (new ApiResponse(false, 'Affiliator profile is not initialized yet.'))->send(400);
            }

            $picName = trim($data['pic_name'] ?? '');
            $status = trim($data['status'] ?? 'draft');

            if (!in_array($status, ['draft', 'submitted', 'review', 'revision', 'approved', 'active'])) {
                (new ApiResponse(false, 'Invalid status.'))->send(400);
            }

            // 2. Perform UPSERT on supervision metadata
            $stmt = $pdo->prepare("
                INSERT INTO affiliator_supervisions (affiliator_id, pic_name, status, created_by, updated_by, created_at, updated_at)
                VALUES (:affiliator_id, :pic_name, :status, :user_id, :user_id, NOW(), NOW())
                ON CONFLICT (affiliator_id)
                DO UPDATE SET
                    pic_name = EXCLUDED.pic_name,
                    status = EXCLUDED.status,
                    updated_by = EXCLUDED.updated_by,
                    updated_at = NOW()
                RETURNING *
            ");
            $stmt->execute([
                'affiliator_id' => $affiliatorId,
                'pic_name' => $picName,
                'status' => $status,
                'user_id' => $userId
            ]);
            $supervision = $stmt->fetch();
            $supervisionId = $supervision['id'];

            // 3. Process File Uploads - Accept multiple documents dynamically
            $uploadDir = __DIR__ . '/../../public/bck/affiliator/supervisions/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            if (!empty($_FILES)) {
                foreach ($_FILES as $key => $file) {
                    if ($file['error'] === UPLOAD_ERR_OK) {
                        $originalName = basename($file['name']);
                        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
                        $filename = pathinfo($originalName, PATHINFO_FILENAME);
                        $randomId = bin2hex(random_bytes(2));
                        $sanitizedName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $filename) . '_' . time() . '_' . $randomId . '.' . $extension;
                        $targetPath = $uploadDir . $sanitizedName;

                        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                            $dbPath = 'public/bck/affiliator/supervisions/' . $sanitizedName;

                            // Insert document record
                            $insStmt = $pdo->prepare("
                                INSERT INTO affiliator_supervision_documents (supervision_id, document_path)
                                VALUES (:supervision_id, :document_path)
                            ");
                            $insStmt->execute([
                                'supervision_id' => $supervisionId,
                                'document_path' => $dbPath
                            ]);
                        }
                    }
                }
            }

            // 4. Retrieve all uploaded documents to return complete status
            $docStmt = $pdo->prepare("SELECT id, document_path FROM affiliator_supervision_documents WHERE supervision_id = :supervision_id ORDER BY id DESC");
            $docStmt->execute(['supervision_id' => $supervisionId]);
            $supervision['documents'] = $docStmt->fetchAll();

            (new ApiResponse(true, 'Supervision registration saved successfully', $supervision))->send(200);
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }

    /**
     * Delete a supervision document.
     */
    public function deleteDocument()
    {
        AuthMiddleware::authorize(['affiliator']);

        try {
            $pdo = Database::getConnection();
            $id = $_GET['id'] ?? null;

            if ($id === null) {
                $data = RequestHelper::getBody();
                $id = $data['id'] ?? null;
            }

            if ($id === null) {
                (new ApiResponse(false, 'ID is required'))->send(400);
            }

            // Check if document exists and get path to delete physically
            $stmt = $pdo->prepare("SELECT document_path FROM affiliator_supervision_documents WHERE id = :id");
            $stmt->execute(['id' => $id]);
            $doc = $stmt->fetch();

            if ($doc) {
                $publicDir = __DIR__ . '/../../public/';
                $absPath = realpath($publicDir . $doc['document_path']);
                if ($absPath && file_exists($absPath) && is_file($absPath)) {
                    unlink($absPath);
                }

                $delStmt = $pdo->prepare("DELETE FROM affiliator_supervision_documents WHERE id = :id");
                $delStmt->execute(['id' => $id]);
            }

            (new ApiResponse(true, 'Dokumen berhasil dihapus'))->send(200);
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }

    /**
     * Update supervision review status and notes by admin.
     */
     public function review()
     {
         $user = AuthMiddleware::authorize(['admin', 'reviewer']);

         try {
             $pdo = Database::getConnection();
             $data = RequestHelper::getBody();

             $id = $data['id'] ?? null;
             $status = trim($data['status'] ?? '');
             $reviewNotes = trim($data['review_notes'] ?? '');

             if ($id === null || empty($status)) {
                 (new ApiResponse(false, 'Supervision ID and Status are required'))->send(400);
                 return;
             }

             if (!in_array($status, ['draft', 'submitted', 'review', 'revision', 'approved', 'active'])) {
                 (new ApiResponse(false, 'Invalid status.'))->send(400);
                 return;
             }

             $userId = $user['data']['id'];

             $stmt = $pdo->prepare("
                 UPDATE affiliator_supervisions
                 SET status = :status::varchar,
                     review_notes = :review_notes,
                     approved_by = CASE WHEN :status_check::varchar IN ('approved', 'active') THEN :approved_user_id::integer ELSE approved_by END,
                     approved_at = CASE WHEN :status_check2::varchar IN ('approved', 'active') THEN NOW() ELSE approved_at END,
                     updated_by = :user_id::integer,
                     updated_at = NOW()
                 WHERE id = :id::integer
                 RETURNING *
             ");

             $stmt->bindValue(':status', $status, PDO::PARAM_STR);
             $stmt->bindValue(':status_check', $status, PDO::PARAM_STR);
             $stmt->bindValue(':status_check2', $status, PDO::PARAM_STR);
             $stmt->bindValue(':review_notes', $reviewNotes === '' ? null : $reviewNotes, $reviewNotes === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
             $stmt->bindValue(':approved_user_id', $userId, PDO::PARAM_INT);
             $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
             $stmt->bindValue(':id', $id, PDO::PARAM_INT);

             $stmt->execute();
             $result = $stmt->fetch();

             if (!$result) {
                 (new ApiResponse(false, 'Supervision registration record not found.'))->send(404);
                 return;
             }

             (new ApiResponse(true, 'Supervision status updated successfully', $result))->send(200);
         } catch (\Throwable $e) {
             (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
         }
     }
}
