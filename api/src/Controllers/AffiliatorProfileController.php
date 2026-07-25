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
     * Helper: resolve affiliator_id from the logged-in user's JWT.
     */
    private function resolveAffiliatorId(array $user): ?int
    {
        $pdo = Database::getConnection();
        $userId = $user['data']['id'] ?? null;
        $stmt = $pdo->prepare("SELECT affiliator_id FROM users WHERE id = :id");
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();
        return $row ? (int)$row['affiliator_id'] : null;
    }

    /**
     * Retrieve the logged-in user's affiliator profile (without documents).
     */
    public function get()
    {
        $user = AuthMiddleware::authorize(['affiliator']);

        try {
            $pdo = Database::getConnection();
            $affiliatorId = $this->resolveAffiliatorId($user);

            if (!$affiliatorId) {
                (new ApiResponse(false, 'Akun pengguna ini tidak terasosiasi dengan institusi jejaring manapun'))->send(400);
            }

            $stmt = $pdo->prepare("SELECT * FROM affiliators WHERE id = :id");
            $stmt->execute(['id' => $affiliatorId]);
            $profile = $stmt->fetch();

            if (!$profile) {
                (new ApiResponse(false, 'Profil faskes tidak ditemukan'))->send(404);
            }

            (new ApiResponse(true, 'Profil RS berhasil dimuat', $profile))->send(200);
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }

    /**
     * Update only the fields present in the request body (dynamic SET).
     */
    public function put()
    {
        $user = AuthMiddleware::authorize(['affiliator']);

        try {
            $pdo = Database::getConnection();
            $userId = $user['data']['id'] ?? null;
            $data = RequestHelper::getBody();
            $affiliatorId = $this->resolveAffiliatorId($user);

            if (!$affiliatorId) {
                (new ApiResponse(false, 'Akun pengguna ini tidak terasosiasi dengan institusi jejaring manapun'))->send(400);
            }

            // Map of allowed request keys to DB column names and their PDO types
            $allowedFields = [
                'affiliator_name'       => ['col' => 'affiliator_name',       'type' => PDO::PARAM_STR],
                'affiliator_type'       => ['col' => 'affiliator_type',       'type' => PDO::PARAM_STR],
                'address'               => ['col' => 'address',               'type' => PDO::PARAM_STR],
                'contact_phone'         => ['col' => 'contact_phone',         'type' => PDO::PARAM_STR],
                'contact_email'         => ['col' => 'contact_email',         'type' => PDO::PARAM_STR],
                'operational_number'    => ['col' => 'operational_number',    'type' => PDO::PARAM_STR],
                'director_name'         => ['col' => 'director_name',         'type' => PDO::PARAM_STR],
                'bed_number'            => ['col' => 'bed_number',            'type' => PDO::PARAM_INT],
                'icu_bed'               => ['col' => 'icu_bed',               'type' => PDO::PARAM_INT],
                'isolation_bed'         => ['col' => 'isolation_bed',         'type' => PDO::PARAM_INT],
                'policlinic'            => ['col' => 'policlinic',            'type' => PDO::PARAM_INT],
                'supporting_facility'   => ['col' => 'supporting_facility',   'type' => PDO::PARAM_STR],
                'specialist_number'     => ['col' => 'specialist_number',     'type' => PDO::PARAM_INT],
                'generalist_number'     => ['col' => 'generalist_number',     'type' => PDO::PARAM_INT],
                'nurse_number'          => ['col' => 'nurse_number',          'type' => PDO::PARAM_INT],
                'other_labor_number'    => ['col' => 'other_labor_number',    'type' => PDO::PARAM_INT],
                'research_head'         => ['col' => 'research_head',         'type' => PDO::PARAM_STR],
                'reasearch_head_contact'=> ['col' => 'reasearch_head_contact','type' => PDO::PARAM_STR],
            ];

            // Build dynamic SET clause from only the keys present in the request body
            $setClauses = [];
            $params = [];
            foreach ($allowedFields as $key => $meta) {
                if (array_key_exists($key, $data)) {
                    $setClauses[] = "{$meta['col']} = :{$key}";
                    $params[$key] = [
                        'value' => $meta['type'] === PDO::PARAM_INT ? (int)$data[$key] : trim($data[$key] ?? ''),
                        'type'  => $meta['type'],
                    ];
                }
            }

            if (empty($setClauses)) {
                (new ApiResponse(false, 'No fields to update'))->send(400);
            }

            // Always update the audit columns
            $setClauses[] = "updated_at = NOW()";
            $setClauses[] = "updated_by = :userId";

            $sql = "UPDATE affiliators SET " . implode(', ', $setClauses) . " WHERE id = :id RETURNING *";
            $stmt = $pdo->prepare($sql);

            foreach ($params as $key => $p) {
                $stmt->bindValue(":{$key}", $p['value'], $p['type']);
            }
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
     * Retrieve profile documents for the logged-in user's affiliator.
     */
    public function getDocuments()
    {
        $user = AuthMiddleware::authorize(['affiliator']);

        try {
            $pdo = Database::getConnection();
            $affiliatorId = $this->resolveAffiliatorId($user);

            if (!$affiliatorId) {
                (new ApiResponse(false, 'Akun pengguna ini tidak terasosiasi dengan institusi jejaring manapun'))->send(400);
            }

            $stmt = $pdo->prepare("SELECT * FROM affiliator_profile_documents WHERE affiliator_id = :id ORDER BY id DESC");
            $stmt->execute(['id' => $affiliatorId]);
            $documents = $stmt->fetchAll();

            (new ApiResponse(true, 'Dokumen berhasil dimuat', $documents))->send(200);
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
            $data = RequestHelper::getBody();
            $affiliatorId = $this->resolveAffiliatorId($user);

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
