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

            $supervision = Database::fetch("
                SELECT
                    asup.*,
                    fn_get_module_status(asup.is_posted, asup.is_reviewed, asup.is_approved, asup.is_revised) AS status,
                    aff.affiliator_name,
                    aff.affiliator_type,
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
            ", ['affiliator_id' => $affiliatorId]);

            if (!$supervision) {
                $affStmt = $pdo->prepare("SELECT * FROM affiliators WHERE id = :id");
                $affStmt->execute(['id' => $affiliatorId]);
                $aff = $affStmt->fetch();

                $defaultSupervision = [
                    'id' => null,
                    'reference_id' => null,
                    'affiliator_id' => (int)$affiliatorId,
                    'pic_name' => '',
                    'is_posted' => false,
                    'is_revised' => false,
                    'is_reviewed' => false,
                    'is_approved' => false,
                    'review_notes' => null,
                    'approved_by' => null,
                    'approved_at' => null,
                    'created_at' => null,
                    'updated_at' => null,
                    'status' => 'draft',
                    'affiliator_name' => $aff['affiliator_name'] ?? '',
                    'affiliator_type' => $aff['affiliator_type'] ?? '',
                    'address' => $aff['address'] ?? '',
                    'documents' => []
                ];

                (new ApiResponse(true, 'No supervision progress found, returning profile defaults', $defaultSupervision))->send(200);
                return;
            }

            $docs = json_decode($supervision['documents'] ?? '[]', true);
            foreach ($docs as &$doc) {
                $doc['document_path'] = RequestHelper::getDocumentUrl($doc['document_path'], $roleName);
            }
            $supervision['documents'] = $docs;

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

            $filterDay   = $_GET['filter_day'] ?? "";
            $filterMonth = $_GET['filter_month'] ?? "";
            $filterYear  = $_GET['filter_year'] ?? "";
            $statusFilter = $_GET['filter_status'] ?? "";

            $where = '';
            $params = [];

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

            // Status Filter using fn_filter_module_status
            $where .= " AND fn_filter_module_status(:status_filter, asup.is_posted, asup.is_reviewed, asup.is_approved, asup.is_revised)";
            $params['status_filter'] = $statusFilter;

            $query = "
                SELECT * FROM (
                    SELECT
                        asup.*,
                        fn_get_module_status(asup.is_posted, asup.is_reviewed, asup.is_approved, asup.is_revised) AS status,
                        asup.is_posted as is_posted,
                        aff.affiliator_name,
                        aff.affiliator_type,
                        COALESCE(asup.posted_date, asup.updated_at) as posted_date,
                        aff.address
                    FROM affiliator_supervisions asup
                    JOIN affiliators aff ON asup.affiliator_id = aff.id
                    WHERE 1=1 {$where}
                    ORDER BY asup.id DESC
                ) A
            ";

            $tableName = "(
                SELECT asup.*, aff.affiliator_name
                FROM affiliator_supervisions asup
                JOIN affiliators aff ON asup.affiliator_id = aff.id
                WHERE 1=1 {$where}
            ) A";

            $responseData = RequestHelper::paginate(
                pdo: $pdo,
                query: $query,
                tableName: $tableName,
                queryWhere: "",
                filterFields: ['affiliator_name', 'pic_name'],
                params: $params
            );

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

            $affiliatorId = RequestHelper::getAffiliatorId($pdo, $userId, ROLE_NAME::AFFILIATOR);

            // Check if record exists
            $checkStmt = $pdo->prepare("SELECT id FROM affiliator_supervisions WHERE affiliator_id = :affiliator_id");
            $checkStmt->execute(['affiliator_id' => $affiliatorId]);
            $existing = $checkStmt->fetch();

            if ($existing) {
                (new ApiResponse(false, 'Pengajuan pengampuan sudah dibuat. Silakan gunakan metode PUT untuk mengubah.'))->send(400);
                return;
            }

            $picName = trim($data['pic_name'] ?? '');
            $status = trim($data['status'] ?? 'draft');

            // Generate reference_id in DB
            $refStmt = $pdo->prepare("SELECT fn_generate_supervision_reference_id(:affiliator_id)");
            $refStmt->execute(['affiliator_id' => $affiliatorId]);
            $referenceId = $refStmt->fetchColumn();

            // Perform INSERT on supervision metadata using fn_set_module_status
            $stmt = $pdo->prepare("
                INSERT INTO affiliator_supervisions (
                    reference_id,
                    affiliator_id,
                    pic_name,
                    is_posted,
                    is_reviewed,
                    is_approved,
                    is_revised,
                    created_by,
                    updated_by,
                    created_at,
                    updated_at,
                    posted_date
                )
                SELECT
                    :reference_id,
                    :affiliator_id,
                    :pic_name,
                    status.is_posted,
                    status.is_reviewed,
                    status.is_approved,
                    status.is_revised,
                    :user_id,
                    :user_id,
                    NOW(),
                    NOW(),
                    CASE WHEN :status = 'submitted' THEN NOW() ELSE NULL END
                FROM fn_set_module_status(:status) status
                RETURNING *, fn_get_module_status(is_posted, is_reviewed, is_approved, is_revised) AS status
            ");
            $stmt->execute([
                'reference_id' => $referenceId,
                'affiliator_id' => $affiliatorId,
                'pic_name' => $picName,
                'status' => $status,
                'user_id' => $userId
            ]);

            $supervision = $stmt->fetch();
            $supervisionId = $supervision['id'];

            // Fetch affiliator code to determine subfolder
            $codeStmt = $pdo->prepare("SELECT affiliator_code FROM affiliators WHERE id = :id");
            $codeStmt->execute(['id' => $affiliatorId]);
            $affiliatorCode = $codeStmt->fetchColumn() ?: '';

            // Process File Uploads - Accept multiple documents dynamically
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

            // Retrieve all uploaded documents to return complete status
            $docStmt = $pdo->prepare("SELECT id, document_path FROM affiliator_supervision_documents WHERE supervision_id = :supervision_id ORDER BY id DESC");
            $docStmt->execute(['supervision_id' => $supervisionId]);
            $supervision['documents'] = $docStmt->fetchAll();

            (new ApiResponse(true, 'Supervision registration saved successfully', $supervision))->send(201);
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }

    public function put()
    {
        $user = AuthMiddleware::authorize(['affiliator']);

        try {
            $pdo = Database::getConnection();
            $userId = $user['data']['id'];

            $body = RequestHelper::getBody();
            $data = array_merge($_POST, $body);

            $affiliatorId = RequestHelper::getAffiliatorId($pdo, $userId, ROLE_NAME::AFFILIATOR);

            // Check if record exists and fetch status using sql helper
            $checkStmt = $pdo->prepare("
                SELECT id, posted_date, fn_get_module_status(is_posted, is_reviewed, is_approved, is_revised) AS status
                FROM affiliator_supervisions
                WHERE affiliator_id = :affiliator_id
            ");
            $checkStmt->execute(['affiliator_id' => $affiliatorId]);
            $existing = $checkStmt->fetch();

            if (!$existing) {
                (new ApiResponse(false, 'Pengajuan pengampuan tidak ditemukan.'))->send(404);
                return;
            }

            // Block modifications if status is locked (submitted, approved, rejected)
            if (in_array($existing['status'], ['submitted', 'approved', 'rejected'])) {
                (new ApiResponse(false, 'Pengajuan pengampuan telah dikirim dan tidak dapat diubah.'))->send(400);
                return;
            }

            $picName = isset($data['pic_name']) ? trim($data['pic_name']) : '';
            $status = isset($data['status']) ? trim($data['status']) : 'draft';

            $postedDate = $existing['posted_date'];
            if ($status === 'submitted' && $postedDate === null) {
                $postedDate = date('Y-m-d H:i:s');
            }

            // Update supervision metadata using fn_set_module_status
            $stmt = $pdo->prepare("
                UPDATE affiliator_supervisions SET
                    pic_name = :pic_name,
                    (is_posted, is_reviewed, is_approved, is_revised) =
                        (SELECT is_posted, is_reviewed, is_approved, is_revised FROM fn_set_module_status(:status)),
                    updated_by = :user_id,
                    updated_at = NOW(),
                    posted_date = :posted_date
                WHERE affiliator_id = :affiliator_id
                RETURNING *, fn_get_module_status(is_posted, is_reviewed, is_approved, is_revised) AS status
            ");
            $stmt->execute([
                'pic_name' => $picName,
                'status' => $status,
                'user_id' => $userId,
                'posted_date' => $postedDate,
                'affiliator_id' => $affiliatorId
            ]);

            $supervision = $stmt->fetch();
            $supervisionId = $supervision['id'];

            // Process document deletions if requested
            $deleteDocIds = $data['delete_document_ids'] ?? [];
            if (!empty($deleteDocIds)) {
                if (is_string($deleteDocIds)) {
                    $deleteDocIds = json_decode($deleteDocIds, true) ?: [];
                }
                foreach ($deleteDocIds as $docId) {
                    $docStmt = $pdo->prepare("SELECT document_path FROM affiliator_supervision_documents WHERE id = :id AND supervision_id = :supervision_id");
                    $docStmt->execute(['id' => $docId, 'supervision_id' => $supervisionId]);
                    $doc = $docStmt->fetch();
                    if ($doc) {
                        $publicDir = __DIR__ . '/../../public/';
                        $absPath = realpath($publicDir . $doc['document_path']);
                        if ($absPath && file_exists($absPath) && is_file($absPath)) {
                            unlink($absPath);
                        }
                        $delStmt = $pdo->prepare("DELETE FROM affiliator_supervision_documents WHERE id = :id");
                        $delStmt->execute(['id' => $docId]);
                    }
                }
            }

            // Fetch affiliator code to determine subfolder
            $codeStmt = $pdo->prepare("SELECT affiliator_code FROM affiliators WHERE id = :id");
            $codeStmt->execute(['id' => $affiliatorId]);
            $affiliatorCode = $codeStmt->fetchColumn() ?: '';

            // Process File Uploads - Accept multiple documents dynamically
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

            // Retrieve all uploaded documents to return complete status
            $docStmt = $pdo->prepare("SELECT id, document_path FROM affiliator_supervision_documents WHERE supervision_id = :supervision_id ORDER BY id DESC");
            $docStmt->execute(['supervision_id' => $supervisionId]);
            $supervision['documents'] = $docStmt->fetchAll();

            (new ApiResponse(true, 'Supervision registration updated successfully', $supervision))->send(200);
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

            // Check if record exists and fetch status using sql helper
            $checkStmt = $pdo->prepare("
                SELECT fn_get_module_status(is_posted, is_reviewed, is_approved, is_revised) AS status
                FROM affiliator_supervisions
                WHERE id = :id
            ");
            $checkStmt->execute(['id' => $id]);
            $existingStatus = $checkStmt->fetchColumn();

            if (!$existingStatus) {
                (new ApiResponse(false, 'Pengajuan pengampuan tidak ditemukan.'))->send(404);
                return;
            }

            // Deny review if registration is still in draft state
            if ($existingStatus === 'draft') {
                (new ApiResponse(false, 'Pengajuan pengampuan masih berupa draft dan belum dikirim.'))->send(400);
                return;
            }

            $userId = $user['data']['id'];

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
