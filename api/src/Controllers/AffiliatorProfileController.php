<?php

namespace App\Controllers;

use App\Config\Database;
use App\Middleware\AuthMiddleware;
use App\Models\ApiResponse;
use App\Helpers\RequestHelper;
use PDO;

class AffiliatorProfileController
{
    /**
     * Retrieve the logged-in user's affiliator profile and support documents.
     */
    public function get()
    {
        $user = AuthMiddleware::authorize(['affiliator']);

        try {
            $pdo = Database::getConnection();
            $userId = $user['data']['id'] ?? null;

            // Fetch the user's affiliator_id
            $userStmt = $pdo->prepare("SELECT affiliator_id FROM users WHERE id = :id");
            $userStmt->execute(['id' => $userId]);
            $userData = $userStmt->fetch();
            $affiliatorId = $userData ? $userData['affiliator_id'] : null;

            if (!$affiliatorId) {
                (new ApiResponse(false, 'Akun pengguna ini tidak terasosiasi dengan institusi jejaring manapun'))->send(400);
            }

            // Fetch affiliator profile details
            $profileStmt = $pdo->prepare("SELECT * FROM affiliators WHERE id = :id");
            $profileStmt->execute(['id' => $affiliatorId]);
            $profile = $profileStmt->fetch();

            if (!$profile) {
                (new ApiResponse(false, 'Profil faskes tidak ditemukan'))->send(404);
            }

            // Fetch associated documents
            $docsStmt = $pdo->prepare("SELECT * FROM affiliator_profile_documents WHERE affiliator_id = :id ORDER BY id DESC");
            $docsStmt->execute(['id' => $affiliatorId]);
            $documents = $docsStmt->fetchAll();

            $responseData = [
                'profile' => $profile,
                'documents' => $documents
            ];

            (new ApiResponse(true, 'Profil RS berhasil dimuat', $responseData))->send(200);
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }

    /**
     * Update the logged-in user's affiliator profile details.
     */
    public function put()
    {
        $user = AuthMiddleware::authorize(['affiliator']);

        try {
            $pdo = Database::getConnection();
            $userId = $user['data']['id'] ?? null;
            $data = RequestHelper::getBody();

            // Fetch the user's affiliator_id
            $userStmt = $pdo->prepare("SELECT affiliator_id FROM users WHERE id = :id");
            $userStmt->execute(['id' => $userId]);
            $userData = $userStmt->fetch();
            $affiliatorId = $userData ? $userData['affiliator_id'] : null;

            if (!$affiliatorId) {
                (new ApiResponse(false, 'Akun pengguna ini tidak terasosiasi dengan institusi jejaring manapun'))->send(400);
            }

            // Perform direct column updates in the database
            $stmt = $pdo->prepare("
                UPDATE affiliators
                SET affiliator_name = :name,
                    affiliator_type = :type,
                    address = :address,
                    contact_phone = :phone,
                    contact_email = :email,
                    operational_number = :operational_number,
                    director_name = :director_name,
                    bed_number = :bed_number,
                    icu_bed = :icu_bed,
                    isolation_bed = :isolation_bed,
                    policlinic = :policlinic,
                    supporting_facility = :supporting_facility,
                    specialist_number = :specialist_number,
                    generalist_number = :generalist_number,
                    nurse_number = :nurse_number,
                    other_labor_number = :other_labor_number,
                    research_head = :research_head,
                    reasearch_head_contact = :reasearch_head_contact,
                    updated_at = NOW(),
                    updated_by = :userId
                WHERE id = :id
                RETURNING *
            ");

            $stmt->bindValue(':name', trim($data['affiliator_name'] ?? ''), PDO::PARAM_STR);
            $stmt->bindValue(':type', trim($data['affiliator_type'] ?? 'Rumah Sakit'), PDO::PARAM_STR);
            $stmt->bindValue(':address', trim($data['address'] ?? ''), PDO::PARAM_STR);
            $stmt->bindValue(':phone', trim($data['contact_phone'] ?? ''), PDO::PARAM_STR);
            $stmt->bindValue(':email', trim($data['contact_email'] ?? ''), PDO::PARAM_STR);
            $stmt->bindValue(':operational_number', $data['operational_number'] ?? null, PDO::PARAM_STR);
            $stmt->bindValue(':director_name', $data['director_name'] ?? null, PDO::PARAM_STR);
            $stmt->bindValue(':bed_number', isset($data['bed_number']) ? (int)$data['bed_number'] : null, PDO::PARAM_INT);
            $stmt->bindValue(':icu_bed', isset($data['icu_bed']) ? (int)$data['icu_bed'] : null, PDO::PARAM_INT);
            $stmt->bindValue(':isolation_bed', isset($data['isolation_bed']) ? (int)$data['isolation_bed'] : null, PDO::PARAM_INT);
            $stmt->bindValue(':policlinic', isset($data['policlinic']) ? (int)$data['policlinic'] : null, PDO::PARAM_INT);
            $stmt->bindValue(':supporting_facility', $data['supporting_facility'] ?? null, PDO::PARAM_STR);
            $stmt->bindValue(':specialist_number', isset($data['specialist_number']) ? (int)$data['specialist_number'] : null, PDO::PARAM_INT);
            $stmt->bindValue(':generalist_number', isset($data['generalist_number']) ? (int)$data['generalist_number'] : null, PDO::PARAM_INT);
            $stmt->bindValue(':nurse_number', isset($data['nurse_number']) ? (int)$data['nurse_number'] : null, PDO::PARAM_INT);
            $stmt->bindValue(':other_labor_number', isset($data['other_labor_number']) ? (int)$data['other_labor_number'] : null, PDO::PARAM_INT);
            $stmt->bindValue(':research_head', $data['research_head'] ?? null, PDO::PARAM_STR);
            $stmt->bindValue(':reasearch_head_contact', $data['reasearch_head_contact'] ?? null, PDO::PARAM_STR);
            $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':id', $affiliatorId, PDO::PARAM_INT);

            $stmt->execute();
            $updatedProfile = $stmt->fetch();

            (new ApiResponse(true, 'Profil RS berhasil diperbarui', $updatedProfile))->send(200);
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }

    /**
     * Upload profile document.
     */
    public function postDocument()
    {
        $user = AuthMiddleware::authorize(['affiliator']);

        try {
            $pdo = Database::getConnection();
            $userId = $user['data']['id'] ?? null;
            $data = RequestHelper::getBody();

            $userStmt = $pdo->prepare("SELECT affiliator_id FROM users WHERE id = :id");
            $userStmt->execute(['id' => $userId]);
            $userData = $userStmt->fetch();
            $affiliatorId = $userData ? $userData['affiliator_id'] : null;

            if (!$affiliatorId) {
                (new ApiResponse(false, 'Akun pengguna ini tidak terasosiasi dengan institusi jejaring manapun'))->send(400);
            }

            $docName = trim($data['document_name'] ?? '');
            $docPath = trim($data['document_path'] ?? '');

            if (empty($docName) || empty($docPath)) {
                (new ApiResponse(false, 'document_name and document_path are required'))->send(400);
            }

            $stmt = $pdo->prepare("
                INSERT INTO affiliator_profile_documents (affiliator_id, document_name, document_path, created_at, updated_at)
                VALUES (:affiliator_id, :document_name, :document_path, NOW(), NOW())
                RETURNING *
            ");

            $stmt->bindValue(':affiliator_id', $affiliatorId, PDO::PARAM_INT);
            $stmt->bindValue(':document_name', $docName, PDO::PARAM_STR);
            $stmt->bindValue(':document_path', $docPath, PDO::PARAM_STR);

            $stmt->execute();
            $newDoc = $stmt->fetch();

            (new ApiResponse(true, 'Dokumen berhasil diunggah', $newDoc))->send(201);
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }

    /**
     * Delete profile document.
     */
    public function deleteDocument()
    {
        $user = AuthMiddleware::authorize(['affiliator']);

        try {
            $pdo = Database::getConnection();
            $id = $_GET['id'] ?? null;

            if ($id === null) {
                $data = RequestHelper::getBody();
                $id = $data['id'] ?? null;
            }

            if ($id === null) {
                (new ApiResponse(false, 'Document ID is required'))->send(400);
            }

            $stmt = $pdo->prepare("DELETE FROM affiliator_profile_documents WHERE id = :id");
            $stmt->execute(['id' => $id]);

            (new ApiResponse(true, 'Dokumen berhasil dihapus'))->send(200);
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }
}
