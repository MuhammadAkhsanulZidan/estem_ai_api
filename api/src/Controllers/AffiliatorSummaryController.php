<?php

namespace App\Controllers;

use App\Config\Database;
use App\Middleware\AuthMiddleware;
use App\Models\ApiResponse;
use App\Helpers\RequestHelper;
use PDO;

class AffiliatorSummaryController
{
    private function resolveAffiliatorId(array $user): ?int
    {
        $pdo = Database::getConnection();
        $userId = $user['data']['id'] ?? null;
        $stmt = $pdo->prepare("SELECT affiliator_id FROM users WHERE id = :id");
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();
        return $row ? (int)$row['affiliator_id'] : null;
    }

    public function statusPengajuan()
    {
        $user = AuthMiddleware::authorize(['affiliator']);

        try {
            $pdo = Database::getConnection();

            // Securely resolve affiliator_id from JWT payload or DB fallback
            $affiliatorId = $user['data']['affiliator_id'] ?? null;
            if ($affiliatorId === null) {
                $affiliatorId = $this->resolveAffiliatorId($user);
            }

            if (!$affiliatorId) {
                (new ApiResponse(false, 'User has no associated affiliator faskes.'))->send(403);
                return;
            }

            $isPosted   = $_GET['is_posted'] ?? "";
            $isRevised  = $_GET['is_revised'] ?? "";
            $isReviewed = $_GET['is_reviewed'] ?? "";
            $isApproved = $_GET['is_approved'] ?? "";
            $status     = $_GET['status'] ?? "";
            $type       = $_GET['type'] ?? "";
            $search     = $_GET['search'] ?? "";
            $startDate  = $_GET['start_date'] ?? "";
            $endDate    = $_GET['end_date'] ?? "";

            $params = [
                'affiliator_id' => $affiliatorId
            ];

            $statusConditions = [
                "affiliator_id = :affiliator_id"
            ];

            // Resolve simplified status codes
            if ($status !== "" && $status !== "Semua Status") {
                if ($status === "Draft" || $status === "draft") {
                    $statusConditions[] = "is_posted = false";
                } elseif ($status === "Menunggu Review" || $status === "submitted" || $status === "pending") {
                    $statusConditions[] = "is_posted = true AND is_reviewed = false";
                } elseif ($status === "Revisi" || $status === "revisi" || $status === "need_revision") {
                    $statusConditions[] = "is_posted = true AND is_reviewed = true AND is_approved = false AND is_revised = true";
                } elseif ($status === "Approved" || $status === "approved") {
                    $statusConditions[] = "is_posted = true AND is_reviewed = true AND is_approved = true";
                } elseif ($status === "Rejected" || $status === "rejected") {
                    $statusConditions[] = "is_posted = true AND is_reviewed = true AND is_approved = false AND is_revised = false";
                }
            }

            if ($type !== "" && $type !== "Semua Jenis") {
                $statusConditions[] = "type = :type";
                $params['type'] = $type;
            }

            if (!empty($startDate)) {
                $statusConditions[] = "date >= :start_date";
                $params['start_date'] = $startDate . ' 00:00:00';
            }

            if (!empty($endDate)) {
                $statusConditions[] = "date <= :end_date";
                $params['end_date'] = $endDate . ' 23:59:59';
            }

            if (!empty($search)) {
                $statusConditions[] = "(title ILIKE :search OR type ILIKE :search)";
                $params['search'] = '%' . $search . '%';
            }

            $statusWhere = "WHERE " . implode(" AND ", $statusConditions);

            // Union all pengajuan submissions
            $query = "
                SELECT * FROM (
                    SELECT 
                        'Protokol' as type,
                        protocol_name as title,
                        created_at as date,
                        is_posted,
                        is_reviewed,
                        is_revised,
                        is_approved,
                        affiliator_id,
                        reference_id as reg_number
                    FROM (
                        SELECT ap.*, adm.reference_id 
                        FROM affiliator_protocols ap
                        LEFT JOIN admin_protocols adm ON ap.protocol_reference_id = adm.id
                    ) p
                    
                    UNION ALL
                    
                    SELECT 
                        'eCRF Pasien' as type,
                        patient_initial || ' (' || registration_number || ')' as title,
                        pe.created_at as date,
                        COALESCE(r.is_posted, false) as is_posted,
                        COALESCE(r.is_reviewed, false) as is_reviewed,
                        COALESCE(r.is_revised, false) as is_revised,
                        COALESCE(r.is_approved, false) as is_approved,
                        pe.affiliator_id,
                        registration_number as reg_number
                    FROM patient_ecrfs pe
                    LEFT JOIN (
                        SELECT 
                            patient_id,
                            bool_and(is_posted) as is_posted,
                            bool_and(is_reviewed) as is_reviewed,
                            bool_or(is_revised) as is_revised,
                            bool_and(is_approved) as is_approved
                        FROM patient_ecrf_responses
                        GROUP BY patient_id
                    ) r ON pe.id = r.patient_id
                    
                    UNION ALL
                    
                    SELECT 
                        'Pengampuan' as type,
                        COALESCE(pic_name, 'Pengampuan Pelayanan Sel Punca') as title,
                        created_at as date,
                        is_posted,
                        is_reviewed,
                        is_revised,
                        is_approved,
                        affiliator_id,
                        reference_id as reg_number
                    FROM affiliator_supervisions
                ) AS u
                {$statusWhere}
                ORDER BY date DESC
            ";

            $tableName = "(
                SELECT 
                    'Protokol' as type, protocol_name as title, created_at as date, is_posted, is_reviewed, is_revised, is_approved, affiliator_id
                FROM affiliator_protocols
                UNION ALL
                SELECT 
                    'eCRF Pasien' as type,
                    patient_initial || ' (' || registration_number || ')' as title,
                    pe.created_at as date,
                    COALESCE(r.is_posted, false) as is_posted,
                    COALESCE(r.is_reviewed, false) as is_reviewed,
                    COALESCE(r.is_revised, false) as is_revised,
                    COALESCE(r.is_approved, false) as is_approved,
                    pe.affiliator_id
                FROM patient_ecrfs pe
                LEFT JOIN (
                    SELECT 
                        patient_id,
                        bool_and(is_posted) as is_posted,
                        bool_and(is_reviewed) as is_reviewed,
                        bool_or(is_revised) as is_revised,
                        bool_and(is_approved) as is_approved
                    FROM patient_ecrf_responses
                    GROUP BY patient_id
                ) r ON pe.id = r.patient_id
                UNION ALL
                SELECT 
                    'Pengampuan' as type, COALESCE(pic_name, 'Pengampuan Pelayanan Sel Punca') as title, created_at as date, is_posted, is_reviewed, is_revised, is_approved, affiliator_id
                FROM affiliator_supervisions
            ) AS u {$statusWhere}";

            $responseData = RequestHelper::paginate(
                pdo: $pdo,
                query: $query,
                tableName: $tableName,
                params: $params,
                filterFields: ['title', 'type']
            );

            (new ApiResponse(true, 'Status pengajuan retrieved successfully', $responseData))->send(200);

        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }
}
