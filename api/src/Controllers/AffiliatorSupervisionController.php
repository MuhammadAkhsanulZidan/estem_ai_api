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
            $roleName = strtolower($user['data']['role_name'] ?? '');

            // 1. Resolve affiliator_id
            $affiliatorId = $_GET['affiliator_id'] ?? null;

            if ($affiliatorId === null && $roleName === 'affiliator') {
                $affStmt = $pdo->prepare("SELECT affiliator_id FROM users WHERE id = :user_id");
                $affStmt->execute(['user_id' => $userId]);
                $affRow = $affStmt->fetch();
                $affiliatorId = $affRow ? $affRow['affiliator_id'] : null;
            }

            // 2. Paginated List Branch
            if ($affiliatorId === null) {
                if ($roleName === "admin" || $roleName === "reviewer") {
                    $filterField = $_GET['filter_field'] ?? "";
                    $filterValue = $_GET['filter_value'] ?? "";
                    $filterDate  = $_GET['filter_date'] ?? $_GET['date'] ?? ""; // Supports filter_date or date
                    $isPosted    = $_GET['is_posted'] ?? "";
                    $isReviewed  = $_GET['is_reviewed'] ?? "";
                    $isApproved  = $_GET['is_approved'] ?? "";
                    $pageNo      = isset($_GET['page_no']) ? (int)$_GET['page_no'] : 1;
                    $pageRow     = isset($_GET['page_row']) ? (int)$_GET['page_row'] : 10;
                    $useLimit    = $pageNo > 0 && $pageRow > 0;

                    $where = 'WHERE 1=1';
                    $params = [];

                    $allowedFields = [
                        'hospital_name' => 'aff.affiliator_name',
                        'pic_name'      => 'asup.pic_name'
                    ];

                    // Text search filter
                    if ($filterField !== "" && $filterValue !== "" && isset($allowedFields[$filterField])) {
                        $column = $allowedFields[$filterField];
                        $where .= " AND {$column} ILIKE :val";
                        $params['val'] = '%' . $filterValue . '%';
                    } else if ($filterValue !== "") {
                        $where .= " AND (aff.affiliator_name ILIKE :val OR asup.pic_name ILIKE :val)";
                        $params['val'] = '%' . $filterValue . '%';
                    }

                    // Date Filter (Matches updated_at / posted_date by date part)
                    if ($filterDate !== "") {
                        $where .= " AND DATE(asup.updated_at) = :filter_date";
                        $params['filter_date'] = $filterDate;
                    }

                    // Status Filters
                    if ($isPosted !== ""){
                        $where .= " AND asup.is_posted = :is_posted";
                        $params['is_posted'] = ($isPosted === "1" || $isPosted === "true") ? 'true' : 'false';
                    }
                    if ($isReviewed !== ""){
                        $where .= " AND asup.is_reviewed = :is_reviewed";
                        $params['is_reviewed'] = ($isReviewed === "1" || $isReviewed === "true") ? 'true' : 'false';
                    }
                    if ($isApproved !== ""){
                        $where .= " AND asup.is_approved = :is_approved";
                        $params['is_approved'] = ($isApproved === "1" || $isApproved === "true") ? 'true' : 'false';
                    }

                    // Count total distinct supervisions
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

                    // Single Query with aggregated documents array via PostgreSQL json_agg
                    $query = "
                        SELECT
                            asup.*,
                            asup.is_posted as is_posted,
                            aff.affiliator_name,
                            aff.affiliator_type,
                            asup.updated_at as posted_date,
                            aff.address,
                            COALESCE(
                                (
                                    SELECT json_agg(json_build_object('id', doc.id, 'document_path', doc.document_path))
                                    FROM affiliator_supervision_documents doc
                                    WHERE doc.supervision_id = asup.id
                                ), '[]'::json
                            ) AS documents
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

                    // Decode JSON documents string returned by PostgreSQL
                    foreach ($supervisions as &$sup) {
                        $sup['documents'] = json_decode($sup['documents'] ?? '[]', true);

                        $is_posted = $sup['is_posted'] ?? false;
                        $is_reviewed = $sup['is_reviewed'] ?? false;
                        $is_revised = $sup['is_revised'] ?? false;
                        $is_approved = $sup['is_approved'] ?? false;

                        if (!$is_posted) {
                            $sup['status'] = 'draft';
                        } else if ($is_reviewed && $is_approved) {
                            $sup['status'] = 'approved';
                        } else if ($is_reviewed && !$is_approved && $is_revised) {
                            $sup['status'] = 'revision';
                        } else if ($is_reviewed && !$is_approved && !$is_revised) {
                            $sup['status'] = 'rejected';
                        } else {
                            $sup['status'] = 'submitted';
                        }
                    }

                    $responseData = [
                        'items'       => $supervisions,
                        'total_items' => $totalItems,
                        'page_no'     => $pageNo,
                        'page_row'    => $pageRow
                    ];

                    (new ApiResponse(true, 'All supervisions retrieved successfully', $responseData))->send(200);
                } else {
                    (new ApiResponse(true, 'No affiliator associated', null))->send(200);
                }
            }

            // 3. Single Affiliator Detail Branch
            $stmt = $pdo->prepare("
                SELECT
                    asup.*,
                    aff.affiliator_name AS hospital_name,
                    aff.affiliator_type AS institution_type,
                    aff.address,
                    COALESCE(
                        (
                            SELECT json_agg(json_build_object('id', doc.id, 'document_path', doc.document_path))
                            FROM affiliator_supervision_documents doc
                            WHERE doc.supervision_id = asup.id
                        ), '[]'::json
                    ) AS documents
                FROM affiliator_supervisions asup
                JOIN affiliators aff ON asup.affiliator_id = aff.id
                WHERE asup.affiliator_id = :affiliator_id
            ");
            $stmt->execute(['affiliator_id' => $affiliatorId]);
            $supervision = $stmt->fetch();

            if (!$supervision) {
                $affStmt = $pdo->prepare("SELECT * FROM affiliators WHERE id = :id");
                $affStmt->execute(['id' => $affiliatorId]);
                $aff = $affStmt->fetch();

                (new ApiResponse(true, 'No supervision progress found, returning profile defaults', null))->send(200);
            }

            $supervision['documents'] = json_decode($supervision['documents'] ?? '[]', true);

            $is_posted = $supervision['is_posted'] ?? false;
            $is_reviewed = $supervision['is_reviewed'] ?? false;
            $is_revised = $supervision['is_revised'] ?? false;
            $is_approved = $supervision['is_approved'] ?? false;

            if (!$is_posted) {
                $supervision['status'] = 'draft';
            } else if ($is_reviewed && $is_approved) {
                $supervision['status'] = 'approved';
            } else if ($is_reviewed && !$is_approved && $is_revised) {
                $supervision['status'] = 'revision';
            } else if ($is_reviewed && !$is_approved && !$is_revised) {
                $supervision['status'] = 'rejected';
            } else {
                $supervision['status'] = 'submitted';
            }

            (new ApiResponse(true, 'Supervision details retrieved successfully', $supervision))->send(200);
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }

    public function post()
    {
        $user = AuthMiddleware::authorize(['affiliator']);

        try {
            $pdo = Database::getConnection();
            $userId = $user['data']['id'];
            $data = $_POST;

            $affStmt = $pdo->prepare("SELECT affiliator_id FROM users WHERE id = :user_id");
            $affStmt->execute(['user_id' => $userId]);
            $affRow = $affStmt->fetch();
            $affiliatorId = $affRow ? $affRow['affiliator_id'] : null;

            if ($affiliatorId === null) {
                (new ApiResponse(false, 'Affiliator profile is not initialized yet.'))->send(400);
            }

            $picName = trim($data['pic_name'] ?? '');

            $status = trim($data['status'] ?? 'draft');
            $isPosted = ($status === 'submitted') ? 'true' : 'false';

            // Check if record exists
            $checkStmt = $pdo->prepare("SELECT reference_id, is_posted FROM affiliator_supervisions WHERE affiliator_id = :affiliator_id");
            $checkStmt->execute(['affiliator_id' => $affiliatorId]);
            $existing = $checkStmt->fetch();

            if ($existing) {
                if ($existing['is_posted']) {
                    (new ApiResponse(false, 'Pengajuan pengampuan telah dikirim dan tidak dapat diubah.'))->send(400);
                    return;
                }
                $referenceId = $existing['reference_id'];
            } else {
                $yearMonth = date('Ym');
                $countQuery = $pdo->query("SELECT COUNT(*) FROM affiliator_supervisions");
                $count = (int)$countQuery->fetchColumn() + 1;
                $referenceId = sprintf("EA-RS-%s-%04d", $yearMonth, $count);
            }

            // 2. Perform UPSERT on supervision metadata
            $stmt = $pdo->prepare("
                INSERT INTO affiliator_supervisions (reference_id, affiliator_id, pic_name, is_posted, is_reviewed, is_approved, is_revised, created_by, updated_by, created_at, updated_at)
                VALUES (:reference_id, :affiliator_id, :pic_name, :is_posted, false, false, false, :user_id, :user_id, NOW(), NOW())
                ON CONFLICT (affiliator_id)
                DO UPDATE SET
                    pic_name = EXCLUDED.pic_name,
                    is_posted = EXCLUDED.is_posted,
                    is_reviewed = false,
                    is_approved = false,
                    is_revised = false,
                    updated_by = EXCLUDED.updated_by,
                    updated_at = NOW()
                RETURNING *
            ");
            $stmt->execute([
                'reference_id' => $referenceId,
                'affiliator_id' => $affiliatorId,
                'pic_name' => $picName,
                'is_posted' => $isPosted,
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

            if (!in_array($status, ['draft', 'submitted', 'review', 'revision', 'approved', 'active', 'rejected'])) {
                (new ApiResponse(false, 'Invalid status.'))->send(400);
                return;
            }

            $userId = $user['data']['id'];

            $stmt = $pdo->prepare("
                UPDATE affiliator_supervisions
                SET review_notes = :review_notes,
                    is_approved = CASE WHEN :status_check1::varchar IN ('approved', 'active') THEN true ELSE false END,
                    is_reviewed = true,
                    is_revised = CASE WHEN :status_check2::varchar = 'revision' THEN true ELSE false END,
                    is_posted = CASE WHEN :status_check3::varchar = 'draft' THEN false ELSE true END,
                    approved_by = CASE WHEN :status_check4::varchar IN ('approved', 'active') THEN :approved_user_id::integer ELSE approved_by END,
                    approved_at = CASE WHEN :status_check5::varchar IN ('approved', 'active') THEN NOW() ELSE approved_at END,
                    updated_by = :user_id::integer,
                    updated_at = NOW()
                WHERE id = :id::integer
                RETURNING *
            ");

            $stmt->bindValue(':status_check1', $status, PDO::PARAM_STR);
            $stmt->bindValue(':status_check2', $status, PDO::PARAM_STR);
            $stmt->bindValue(':status_check3', $status, PDO::PARAM_STR);
            $stmt->bindValue(':status_check4', $status, PDO::PARAM_STR);
            $stmt->bindValue(':status_check5', $status, PDO::PARAM_STR);
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
