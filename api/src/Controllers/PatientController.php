<?php

namespace App\Controllers;

use App\Config\Database;
use App\Middleware\AuthMiddleware;
use App\Helpers\RequestHelper;
use App\Models\ApiResponse;
use PDO;

class PatientController
{
    /**
     * Resolve affiliator_id from user token.
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
     * Get patient list. Filter by affiliator if logged in as affiliator.
     */
    public function get()
    {
        $user = AuthMiddleware::authorize(['affiliator', 'admin']);

        try {
            $pdo = Database::getConnection();
            $affiliatorId = null;

            if (in_array('affiliator', $user['data']['roles'] ?? [])) {
                $affiliatorId = $this->resolveAffiliatorId($user);
                if (!$affiliatorId) {
                    (new ApiResponse(false, 'Akun pengguna tidak terasosiasi dengan institusi faskes'))->send(400);
                    return;
                }
            }

            $query = "
                SELECT * FROM (
                    SELECT
                        pe.*,
                        COALESCE(aff_ap.protocol_name, ap.protocol_name) AS protocol_name,
                        COALESCE(aff_ap.protocol_version, ap.protocol_version) AS protocol_version,
                        COALESCE(aff_ap.indication, ap.indication) AS protocol_indication
                    FROM patient_ecrfs pe
                    JOIN admin_protocols ap ON pe.protocol_id = ap.id
                    LEFT JOIN affiliator_protocols aff_ap ON pe.protocol_id = aff_ap.protocol_reference_id AND pe.affiliator_id = aff_ap.affiliator_id
                    ORDER BY pe.id DESC
                ) A
            ";

            $tableName = "(SELECT pe.*, COALESCE(aff_ap.protocol_name, ap.protocol_name) AS protocol_name FROM patient_ecrfs pe JOIN admin_protocols ap ON pe.protocol_id = ap.id LEFT JOIN affiliator_protocols aff_ap ON pe.protocol_id = aff_ap.protocol_reference_id AND pe.affiliator_id = aff_ap.affiliator_id) A";
            $queryWhere = "";
            $params = [];

            if ($affiliatorId !== null) {
                $queryWhere = "AND affiliator_id = :affiliator_id";
                $params['affiliator_id'] = $affiliatorId;
            }

            $responseData = RequestHelper::paginate(
                pdo: $pdo,
                query: $query,
                tableName: $tableName,
                queryWhere: $queryWhere,
                filterFields: ['patient_initial', 'pic_doctor', 'registration_number', 'protocol_name'],
                params: $params
            );

            (new ApiResponse(true, 'Pasien berhasil dimuat', $responseData))->send(200);
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }

    /**
     * Create/Register a new patient eCRF entry.
     */
    public function post()
    {
        $user = AuthMiddleware::authorize(['affiliator']);

        try {
            $pdo = Database::getConnection();
            $data = RequestHelper::getBody();

            $affiliatorId = $this->resolveAffiliatorId($user);
            if (!$affiliatorId) {
                (new ApiResponse(false, 'Akun pengguna tidak terasosiasi dengan institusi faskes'))->send(400);
            }

            $protocolId = $data['protocol_id'] ?? null;
            $patientInitial = trim($data['patient_initial'] ?? '');
            $gender = trim($data['gender'] ?? '');
            $picDoctor = trim($data['pic_doctor'] ?? '');
            $birthDate = !empty($data['birth_date']) ? $data['birth_date'] : null;
            $registrationDate = !empty($data['registration_date']) ? $data['registration_date'] : date('Y-m-d');
            $userId = $user['data']['id'];

            if (empty($protocolId) || empty($patientInitial)) {
                (new ApiResponse(false, 'protocol_id and patient_initial are required'))->send(400);
            }

            // Fetch affiliator_code
            $stmtCode = $pdo->prepare("SELECT affiliator_code FROM affiliators WHERE id = :id");
            $stmtCode->execute(['id' => $affiliatorId]);
            $affiliatorCode = $stmtCode->fetchColumn() ?: 'UNK';

            // Generate registration number dynamically
            $prefix = $affiliatorCode . "/RP/" . date('ymd') . "/";
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM patient_ecrfs WHERE registration_number LIKE :prefix");
            $countStmt->execute(['prefix' => $prefix . '%']);
            $count = (int)$countStmt->fetchColumn();

            $counter = $count + 1;
            $registrationNumber = $prefix . str_pad($counter, 4, '0', STR_PAD_LEFT);

            $stmt = $pdo->prepare("
                INSERT INTO patient_ecrfs (affiliator_id, protocol_id, registration_number, patient_initial, gender, pic_doctor, birth_date, registration_date, created_by, updated_by, created_at, updated_at)
                VALUES (:affiliator_id, :protocol_id, :registration_number, :patient_initial, :gender, :pic_doctor, :birth_date, :registration_date, :created_by, :updated_by, NOW(), NOW())
                RETURNING *
            ");

            $stmt->bindValue(':affiliator_id', $affiliatorId, PDO::PARAM_INT);
            $stmt->bindValue(':protocol_id', $protocolId, PDO::PARAM_INT);
            $stmt->bindValue(':registration_number', $registrationNumber, PDO::PARAM_STR);
            $stmt->bindValue(':patient_initial', $patientInitial, PDO::PARAM_STR);
            $stmt->bindValue(':gender', $gender, PDO::PARAM_STR);
            $stmt->bindValue(':pic_doctor', $picDoctor, PDO::PARAM_STR);
            $stmt->bindValue(':birth_date', $birthDate, $birthDate === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':registration_date', $registrationDate, PDO::PARAM_STR);
            $stmt->bindValue(':created_by', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':updated_by', $userId, PDO::PARAM_INT);

            $stmt->execute();
            $newPatient = $stmt->fetch();

            (new ApiResponse(true, 'Pasien berhasil didaftarkan', $newPatient))->send(201);
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }

    /**
     * Compute and retrieve the next registration number for UI preview.
     */
    public function getNextRegistrationNumber()
    {
        $user = AuthMiddleware::authorize(['affiliator']);

        try {
            $pdo = Database::getConnection();
            $affiliatorId = $this->resolveAffiliatorId($user);
            if (!$affiliatorId) {
                (new ApiResponse(false, 'Akun pengguna tidak terasosiasi dengan institusi faskes'))->send(400);
            }

            // Fetch affiliator_code
            $stmtCode = $pdo->prepare("SELECT affiliator_code FROM affiliators WHERE id = :id");
            $stmtCode->execute(['id' => $affiliatorId]);
            $affiliatorCode = $stmtCode->fetchColumn() ?: 'UNK';

            $prefix = $affiliatorCode . "/RP/" . date('ymd') . "/";
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM patient_ecrfs WHERE registration_number LIKE :prefix");
            $countStmt->execute(['prefix' => $prefix . '%']);
            $count = (int)$countStmt->fetchColumn();

            $counter = $count + 1;
            $nextRegNo = $prefix . str_pad($counter, 4, '0', STR_PAD_LEFT);

            (new ApiResponse(true, 'Next registration number computed', $nextRegNo))->send(200);
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }

    /**
     * Update an existing patient record.
     */
    public function put()
    {
        $user = AuthMiddleware::authorize(['affiliator']);

        try {
            $pdo = Database::getConnection();
            $data = RequestHelper::getBody();

            $id = $_GET['id'] ?? $data['id'] ?? null;
            if ($id === null) {
                (new ApiResponse(false, 'ID Pasien wajib diisi'))->send(400);
                return;
            }

            $protocolId = $data['protocol_id'] ?? null;
            $patientInitial = trim($data['patient_initial'] ?? '');
            $gender = trim($data['gender'] ?? '');
            $picDoctor = trim($data['pic_doctor'] ?? '');
            $birthDate = !empty($data['birth_date']) ? $data['birth_date'] : null;
            $registrationDate = !empty($data['registration_date']) ? $data['registration_date'] : null;

            if (empty($protocolId) || empty($patientInitial)) {
                (new ApiResponse(false, 'protocol_id and patient_initial are required'))->send(400);
                return;
            }

            $stmt = $pdo->prepare("
                UPDATE patient_ecrfs
                SET protocol_id = :protocol_id,
                    patient_initial = :patient_initial,
                    gender = :gender,
                    pic_doctor = :pic_doctor,
                    birth_date = :birth_date,
                    registration_date = :registration_date,
                    updated_by = :user_id,
                    updated_at = NOW()
                WHERE id = :id
                RETURNING *
            ");

            $stmt->bindValue(':protocol_id', $protocolId, PDO::PARAM_INT);
            $stmt->bindValue(':patient_initial', $patientInitial, PDO::PARAM_STR);
            $stmt->bindValue(':gender', $gender, PDO::PARAM_STR);
            $stmt->bindValue(':pic_doctor', $picDoctor, PDO::PARAM_STR);
            $stmt->bindValue(':birth_date', $birthDate, $birthDate === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':registration_date', $registrationDate, $registrationDate === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':user_id', $user['data']['id'], PDO::PARAM_INT);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);

            $stmt->execute();
            $updated = $stmt->fetch();

            (new ApiResponse(true, 'Pasien berhasil diperbarui', $updated))->send(200);
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }
}
