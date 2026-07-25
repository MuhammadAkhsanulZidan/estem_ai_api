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
        $user = AuthMiddleware::authorize(['affiliator', 'admin']);

        try {
            $pdo = Database::getConnection();
            $userId = $user['data']['id'];

            // 1. Resolve affiliator_id
            $affiliatorId = $_GET['affiliator_id'] ?? null;
            if ($affiliatorId === null && in_array('affiliator', $user['data']['roles'] ?? [])) {
                $affStmt = $pdo->prepare("SELECT id FROM affiliators WHERE create_by = :user_id");
                $affStmt->execute(['user_id' => $userId]);
                $affRow = $affStmt->fetch();
                $affiliatorId = $affRow ? $affRow['id'] : null;
            }

            if ($affiliatorId === null) {
                if (in_array('admin', $user['data']['roles'] ?? [])) {
                    // Fetch all supervisions for admin list
                    $stmt = $pdo->query("
                        SELECT 
                            asup.*,
                            aff.affiliator_name AS hospital_name,
                            aff.affiliator_type AS institution_type,
                            aff.address
                        FROM affiliator_supervisions asup
                        JOIN affiliators aff ON asup.affiliator_id = aff.id
                        ORDER BY asup.id DESC
                    ");
                    $supervisions = $stmt->fetchAll();

                    foreach ($supervisions as &$sup) {
                        $docStmt = $pdo->prepare("SELECT document_key, document_path FROM affiliator_supervision_documents WHERE supervision_id = :id");
                        $docStmt->execute(['id' => $sup['id']]);
                        $docs = $docStmt->fetchAll();

                        $documents = [];
                        foreach ($docs as $doc) {
                            $documents[$doc['document_key']] = $doc['document_path'];
                        }
                        $sup['documents'] = (object)$documents;
                    }

                    (new ApiResponse(true, 'All supervisions retrieved', $supervisions))->send(200);
                } else {
                    (new ApiResponse(true, 'No affiliator associated', null))->send(200);
                }
            }

            // 2. Fetch supervision data joined with affiliator details
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
                    'documents' => new \stdClass()
                ];
                (new ApiResponse(true, 'No supervision progress found, returning profile defaults', $defaultData))->send(200);
            }

            // 3. Fetch documents list
            $docStmt = $pdo->prepare("SELECT document_key, document_path FROM affiliator_supervision_documents WHERE supervision_id = :supervision_id");
            $docStmt->execute(['supervision_id' => $supervision['id']]);
            $docs = $docStmt->fetchAll();

            $documents = [];
            foreach ($docs as $doc) {
                $documents[$doc['document_key']] = $doc['document_path'];
            }

            $supervision['documents'] = (object)$documents;

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
            $data = RequestHelper::getBody();

            // 1. Resolve affiliator_id
            $affStmt = $pdo->prepare("SELECT id FROM affiliators WHERE create_by = :user_id");
            $affStmt->execute(['user_id' => $userId]);
            $affRow = $affStmt->fetch();
            $affiliatorId = $affRow ? $affRow['id'] : null;

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

            // 3. Process File Uploads
            $uploadDir = __DIR__ . '/../../public/bck/affiliator/supervisions/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            $docKeys = ['suratPermohonan', 'profilInstitusi', 'izinInstitusi', 'skPenanggungJawab', 'sopPelayanan'];
            $publicDir = __DIR__ . '/../../public/';

            foreach ($docKeys as $key) {
                if (isset($_FILES[$key]) && $_FILES[$key]['error'] === UPLOAD_ERR_OK) {
                    $file = $_FILES[$key];
                    $originalName = basename($file['name']);
                    $extension = pathinfo($originalName, PATHINFO_EXTENSION);
                    $filename = pathinfo($originalName, PATHINFO_FILENAME);
                    $randomId = bin2hex(random_bytes(2));
                    $sanitizedName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $filename) . '_' . time() . '_' . $randomId . '.' . $extension;
                    $targetPath = $uploadDir . $sanitizedName;

                    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                        $dbPath = 'public/bck/affiliator/supervisions/' . $sanitizedName;

                        // Delete previous physical file for this key if exists
                        $prevStmt = $pdo->prepare("SELECT id, document_path FROM affiliator_supervision_documents WHERE supervision_id = :supervision_id AND document_key = :document_key");
                        $prevStmt->execute([
                            'supervision_id' => $supervisionId,
                            'document_key' => $key
                        ]);
                        $prevDoc = $prevStmt->fetch();

                        if ($prevDoc) {
                            $absPath = realpath($publicDir . $prevDoc['document_path']);
                            if ($absPath && file_exists($absPath) && is_file($absPath)) {
                                unlink($absPath);
                            }
                            // Delete DB record
                            $delStmt = $pdo->prepare("DELETE FROM affiliator_supervision_documents WHERE id = :id");
                            $delStmt->execute(['id' => $prevDoc['id']]);
                        }

                        // Insert new document record
                        $insStmt = $pdo->prepare("
                            INSERT INTO affiliator_supervision_documents (supervision_id, document_key, document_path)
                            VALUES (:supervision_id, :document_key, :document_path)
                        ");
                        $insStmt->execute([
                            'supervision_id' => $supervisionId,
                            'document_key' => $key,
                            'document_path' => $dbPath
                        ]);
                    }
                }
            }

            // 4. Retrieve all uploaded documents to return complete status
            $docStmt = $pdo->prepare("SELECT document_key, document_path FROM affiliator_supervision_documents WHERE supervision_id = :supervision_id");
            $docStmt->execute(['supervision_id' => $supervisionId]);
            $docs = $docStmt->fetchAll();

            $documents = [];
            foreach ($docs as $doc) {
                $documents[$doc['document_key']] = $doc['document_path'];
            }
            $supervision['documents'] = (object)$documents;

            (new ApiResponse(true, 'Supervision registration saved successfully', $supervision))->send(200);
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
            }

            if (!in_array($status, ['draft', 'submitted', 'review', 'revision', 'approved', 'active'])) {
                (new ApiResponse(false, 'Invalid status.'))->send(400);
            }

            $userId = $user['data']['id'];

            $stmt = $pdo->prepare("
                UPDATE affiliator_supervisions
                SET status = :status,
                    review_notes = :review_notes,
                    approved_by = CASE WHEN :status = 'approved' OR :status = 'active' THEN :user_id ELSE approved_by END,
                    approved_at = CASE WHEN :status = 'approved' OR :status = 'active' THEN NOW() ELSE approved_at END,
                    updated_by = :user_id,
                    updated_at = NOW()
                WHERE id = :id
                RETURNING *
            ");
            
            $stmt->bindValue(':status', $status, PDO::PARAM_STR);
            $stmt->bindValue(':review_notes', $reviewNotes === '' ? null : $reviewNotes, $reviewNotes === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);

            $stmt->execute();
            $result = $stmt->fetch();

            if (!$result) {
                (new ApiResponse(false, 'Supervision registration record not found.'))->send(404);
            }

            (new ApiResponse(true, 'Supervision status updated successfully', $result))->send(200);
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }
}
