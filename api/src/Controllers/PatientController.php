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
                }
            }

            $sql = "
                SELECT
                    pe.*,
                    ap.protocol_name,
                    ap.protocol_version,
                    ap.indication AS protocol_indication
                FROM patient_ecrfs pe
                JOIN admin_protocols ap ON pe.protocol_id = ap.id
            ";

            if ($affiliatorId !== null) {
                $sql .= " WHERE pe.affiliator_id = :affiliator_id";
            }
            $sql .= " ORDER BY pe.id DESC";

            $stmt = $pdo->prepare($sql);
            if ($affiliatorId !== null) {
                $stmt->bindValue(':affiliator_id', $affiliatorId, PDO::PARAM_INT);
            }
            $stmt->execute();
            $patients = $stmt->fetchAll();

            (new ApiResponse(true, 'Pasien berhasil dimuat', $patients))->send(200);
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

            // Generate registration number dynamically
            $prefix = "RP-" . date('ymd') . "-" . $affiliatorId . "-";
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM patient_ecrfs WHERE registration_number LIKE :prefix");
            $countStmt->execute(['prefix' => $prefix . '%']);
            $count = (int)$countStmt->fetchColumn();

            $counter = $count + 1;
            $registrationNumber = $prefix . str_pad($counter, 4, '0', STR_PAD_LEFT);

            $stmt = $pdo->prepare("
                INSERT INTO patient_ecrfs (affiliator_id, protocol_id, registration_number, patient_initial, gender, pic_doctor, birth_date, registration_date, created_by, updated_by, created_at, updated_at)
                VALUES (:affiliator_id, :protocol_id, :registration_number, :patient_initial, :gender, :pic_doctor, :birth_date, :registration_date, :user_id, :user_id, NOW(), NOW())
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
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);

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

            $prefix = "RP-" . date('ymd') . "-" . $affiliatorId . "-";
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
}
