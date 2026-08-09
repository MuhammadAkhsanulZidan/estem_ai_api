<?php

namespace App\Controllers;

use App\Config\Database;
use App\Constants\ROLE_NAME;
use App\Middleware\AuthMiddleware;
use App\Models\ApiResponse;
use App\Helpers\RequestHelper;
use App\Helpers\StatusHelper;

use PDO;

class AffiliatorSupervisionController
{
    /*
    */
    public function detail()
    {
        $user = AuthMiddleware::authorize([ROLE_NAME::ADMIN, ROLE_NAME::AFFILIATOR, ROLE_NAME::REVIEWER]);

        try {
            $pdo = Database::getConnection();
            $userId = $user['data']['id'];
            $roleName = strtolower($user['data']['role_name'] ?? '');

            $affiliatorId = RequestHelper::getAffiliatorId($pdo, $userId, $roleName);

            $stmt = $pdo->prepare("
                SELECT
                    asup.*,
                    fn_get_module_status(asup.is_posted, asup.is_reviewed, asup.is_approved, asup.is_revised) AS status,
                    aff.affiliator_name AS institution_name,
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

            (new ApiResponse(true, 'Supervision details retrieved successfully', $supervision))->send(200);

        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }

    /**
    * Get list of supervision registration details and documents.
    */
    public function get()
    {
        $user = AuthMiddleware::authorize([ROLE_NAME::ADMIN, ROLE_NAME::REVIEWER]);

        try {
            $pdo = Database::getConnection();
            $userId = $user['data']['id'];

            $filterField = $_GET['filter_field'] ?? "";
            $filterValue = $_GET['filter_value'] ?? "";
            $filterDay   = $_GET['filter_day'] ?? "";
            $filterMonth = $_GET['filter_month'] ?? "";
            $filterYear  = $_GET['filter_year'] ?? "";
            $isPosted    = $_GET['is_posted'] ?? "";
            $isReviewed  = $_GET['is_reviewed'] ?? "";
            $isApproved  = $_GET['is_approved'] ?? "";
            $statusFilter = $_GET['status'] ?? "";
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

            // Day, Month, Year Filters (extracted from posted_date / updated_at)
            if ($filterDay !== "") {
                $where .= " AND EXTRACT(DAY FROM COALESCE(asup.posted_date, asup.updated_at)) = :filter_day";
                $params['filter_day'] = (int)$filterDay;
            }
            if ($filterMonth !== "") {
                $where .= " AND EXTRACT(MONTH FROM COALESCE(asup.posted_date, asup.updated_at)) = :filter_month";
                $params['filter_month'] = (int)$filterMonth;
            }
            if ($filterYear !== "") {
                $where .= " AND EXTRACT(YEAR FROM COALESCE(asup.posted_date, asup.updated_at)) = :filter_year";
                $params['filter_year'] = (int)$filterYear;
            }

            // Status Filters
            if ($statusFilter !== "") {
                $where .= " AND fn_filter_module_status(:status_filter, asup.is_posted, asup.is_reviewed, asup.is_approved, asup.is_revised)";
                $params['status_filter'] = $statusFilter;
            } else {
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
                        fn_get_module_status(asup.is_posted, asup.is_reviewed, asup.is_approved, asup.is_revised) AS status,
                        asup.is_posted as is_posted,
                        aff.affiliator_name,
                        aff.affiliator_type,
                        COALESCE(asup.posted_date, asup.updated_at) as posted_date,
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
                    $sup['status'] = StatusHelper::resolveStatus($sup);
                }

                $responseData = [
                    'items'       => $supervisions,
                    'total_items' => $totalItems,
                    'page_no'     => $pageNo,
                    'page_row'    => $pageRow
                ];

            (new ApiResponse(true, 'All supervisions retrieved successfully', $responseData))->send(200);

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
            $checkStmt = $pdo->prepare("SELECT reference_id, is_posted, is_revised FROM affiliator_supervisions WHERE affiliator_id = :affiliator_id");
            $checkStmt->execute(['affiliator_id' => $affiliatorId]);
            $existing = $checkStmt->fetch();

            if ($existing) {
                if ($existing['is_posted'] && !$existing['is_revised']) {
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

            $postedDate = ($isPosted === 'true') ? date('Y-m-d H:i:s') : null;

            $isRevisedUpdate = 'false';
            if ($existing && $existing['is_revised'] && $isPosted === 'false') {
                $isRevisedUpdate = 'true';
            }

            // 2. Perform UPSERT on supervision metadata
            $stmt = $pdo->prepare("
                INSERT INTO affiliator_supervisions (reference_id, affiliator_id, pic_name, is_posted, is_reviewed, is_approved, is_revised, created_by, updated_by, created_at, updated_at, posted_date)
                VALUES (:reference_id, :affiliator_id, :pic_name, :is_posted, false, false, false, :user_id, :user_id, NOW(), NOW(), :posted_date)
                ON CONFLICT (affiliator_id)
                DO UPDATE SET
                    pic_name = EXCLUDED.pic_name,
                    is_posted = EXCLUDED.is_posted,
                    is_reviewed = false,
                    is_approved = false,
                    is_revised = :is_revised_update,
                    updated_by = EXCLUDED.updated_by,
                    updated_at = NOW(),
                    posted_date = CASE WHEN EXCLUDED.is_posted = true AND affiliator_supervisions.posted_date IS NULL THEN NOW() ELSE affiliator_supervisions.posted_date END
                RETURNING *, fn_get_module_status(is_posted, is_reviewed, is_approved, is_revised) AS status
            ");
            $stmt->execute([
                'reference_id' => $referenceId,
                'affiliator_id' => $affiliatorId,
                'pic_name' => $picName,
                'is_posted' => $isPosted,
                'is_revised_update' => $isRevisedUpdate,
                'user_id' => $userId,
                'posted_date' => $postedDate
            ]);

            $supervision = $stmt->fetch();
            $supervisionId = $supervision['id'];

            // Fetch affiliator code to determine subfolder
            $codeStmt = $pdo->prepare("SELECT affiliator_code FROM affiliators WHERE id = :id");
            $codeStmt->execute(['id' => $affiliatorId]);
            $affiliatorCode = $codeStmt->fetchColumn() ?: 'default';

            // 3. Process File Uploads - Accept multiple documents dynamically
            $uploadDir = __DIR__ . '/../../public/bck/affiliator/supervision/' . $affiliatorCode . '/';
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
                            $dbPath = 'public/bck/affiliator/supervision/' . $affiliatorCode . '/' . $sanitizedName;

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
        $user = AuthMiddleware::authorize(['admin']);

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
            $flags = StatusHelper::mapStatusToFlags($status);

            // Determine if approval fields should be updated
            $isApprovedStatus = in_array($status, ['approved', 'active']);

            $stmt = $pdo->prepare("
                UPDATE affiliator_supervisions
                SET review_notes = :review_notes,
                    (is_posted, is_reviewed, is_approved, is_revised) =
                        (SELECT is_posted, is_reviewed, is_approved, is_revised FROM fn_set_module_status(:status)),
                    approved_by = CASE WHEN :is_approved_status = true THEN :approved_user_id::integer ELSE approved_by END,
                    approved_at = CASE WHEN :is_approved_status2 = true THEN NOW() ELSE approved_at END,
                    updated_by = :user_id::integer,
                    updated_at = NOW()
                WHERE id = :id::integer
                RETURNING *, fn_get_module_status(is_posted, is_reviewed, is_approved, is_revised) AS status
            ");

            $stmt->bindValue(':review_notes', $reviewNotes === '' ? null : $reviewNotes, $reviewNotes === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':status', $status, PDO::PARAM_STR);
            $stmt->bindValue(':is_approved_status', $isApprovedStatus, PDO::PARAM_BOOL);
            $stmt->bindValue(':is_approved_status2', $isApprovedStatus, PDO::PARAM_BOOL);
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
