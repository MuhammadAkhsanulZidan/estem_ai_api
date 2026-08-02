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
                        '' as reg_number,
                        NULL::integer as patient_id
                    FROM (
                        SELECT ap.*
                        FROM affiliator_protocols ap
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
                        registration_number as reg_number,
                        pe.id as patient_id
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
                        reference_id as reg_number,
                        NULL::integer as patient_id
                    FROM affiliator_supervisions
                ) AS u
                {$statusWhere}
                ORDER BY date DESC
            ";

            $tableName = "(
                SELECT
                    'Protokol' as type, protocol_name as title, created_at as date, is_posted, is_reviewed, is_revised, is_approved, affiliator_id, NULL::integer as patient_id
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
                    pe.affiliator_id,
                    pe.id as patient_id
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
                    'Pengampuan' as type, COALESCE(pic_name, 'Pengampuan Pelayanan Sel Punca') as title, created_at as date, is_posted, is_reviewed, is_revised, is_approved, affiliator_id, NULL::integer as patient_id
                FROM affiliator_supervisions
            ) AS u";

            $queryWhere = "AND " . implode(" AND ", $statusConditions);

            $responseData = RequestHelper::paginate(
                pdo: $pdo,
                query: $query,
                tableName: $tableName,
                queryWhere: $queryWhere,
                filterFields: ['title', 'type'],
                params: $params,
                mutateItems: function ($items) use ($pdo) {
                    foreach ($items as &$item) {
                        $item['sections'] = [];
                        if ($item['type'] === 'eCRF Pasien' && !empty($item['patient_id'])) {
                            $stmt = $pdo->prepare("
                                SELECT
                                    es.id as section_id,
                                    es.section_name,
                                    COALESCE(r.is_posted, false) as is_posted,
                                    COALESCE(r.is_reviewed, false) as is_reviewed,
                                    COALESCE(r.is_revised, false) as is_revised,
                                    COALESCE(r.is_approved, false) as is_approved
                                FROM ecrf_sections es
                                LEFT JOIN patient_ecrf_responses r
                                    ON es.id = r.section_id AND r.patient_id = :patient_id
                                ORDER BY es.id ASC
                            ");
                            $stmt->execute(['patient_id' => $item['patient_id']]);
                            $item['sections'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        }
                    }
                    return $items;
                }
            );

            (new ApiResponse(true, 'Status pengajuan retrieved successfully', $responseData))->send(200);

        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }

    public function dashboard()
    {
        $user = AuthMiddleware::authorize(['affiliator']);

        try {
            $pdo = Database::getConnection();

            // Resolve affiliator_id
            $affiliatorId = $user['data']['affiliator_id'] ?? null;
            if ($affiliatorId === null) {
                $affiliatorId = $this->resolveAffiliatorId($user);
            }

            if (!$affiliatorId) {
                (new ApiResponse(false, 'User has no associated affiliator faskes.'))->send(403);
                return;
            }

            // 1. KPI Stats
            // Total Patients
            $patStmt = $pdo->prepare("SELECT COUNT(*) FROM patient_ecrfs WHERE affiliator_id = :affiliator_id");
            $patStmt->execute(['affiliator_id' => $affiliatorId]);
            $totalPatients = (int)$patStmt->fetchColumn();

            // Total Protocols
            $protStmt = $pdo->prepare("SELECT COUNT(*) FROM affiliator_protocols WHERE affiliator_id = :affiliator_id");
            $protStmt->execute(['affiliator_id' => $affiliatorId]);
            $totalProtocols = (int)$protStmt->fetchColumn();

            // Total Adverse Events
            $aeStmt = $pdo->prepare("SELECT COUNT(*) FROM adverse_events WHERE affiliator_id = :affiliator_id");
            $aeStmt->execute(['affiliator_id' => $affiliatorId]);
            $totalAdverse = (int)$aeStmt->fetchColumn();

            // Supervision Status
            $supStmt = $pdo->prepare("SELECT is_posted, is_reviewed, is_approved, is_revised FROM affiliator_supervisions WHERE affiliator_id = :affiliator_id");
            $supStmt->execute(['affiliator_id' => $affiliatorId]);
            $supervision = $supStmt->fetch(PDO::FETCH_ASSOC);

            // 2. Patient Trend (last 6 months)
            $trendStmt = $pdo->prepare("
                SELECT 
                    TO_CHAR(created_at, 'Mon YYYY') as month,
                    COUNT(*) as count
                FROM patient_ecrfs
                WHERE affiliator_id = :affiliator_id AND created_at >= NOW() - INTERVAL '6 months'
                GROUP BY TO_CHAR(created_at, 'Mon YYYY'), DATE_TRUNC('month', created_at)
                ORDER BY DATE_TRUNC('month', created_at) ASC
            ");
            $trendStmt->execute(['affiliator_id' => $affiliatorId]);
            $patientTrends = $trendStmt->fetchAll(PDO::FETCH_ASSOC);

            // 3. eCRF Compliance (Lengkap, Sedang Pengisian, Perlu Revisi)
            $compStmt = $pdo->prepare("
                SELECT
                    COUNT(CASE WHEN r.is_approved = true AND r.is_reviewed = true AND r.is_posted = true THEN 1 END) as lengkap,
                    COUNT(CASE WHEN r.is_posted = false OR (r.is_posted = true AND r.is_reviewed = false) THEN 1 END) as sedang_pengisian,
                    COUNT(CASE WHEN r.is_revised = true THEN 1 END) as perlu_revisi
                FROM patient_ecrfs pe
                LEFT JOIN (
                    SELECT
                        patient_id,
                        bool_and(is_approved) as is_approved,
                        bool_and(is_reviewed) as is_reviewed,
                        bool_and(is_posted) as is_posted,
                        bool_or(is_revised) as is_revised
                    FROM patient_ecrf_responses
                    GROUP BY patient_id
                ) r ON pe.id = r.patient_id
                WHERE pe.affiliator_id = :affiliator_id
            ");
            $compStmt->execute(['affiliator_id' => $affiliatorId]);
            $compliance = $compStmt->fetch(PDO::FETCH_ASSOC);

            // 4. Adverse Event by Protocol
            $aeProtStmt = $pdo->prepare("
                SELECT 
                    ap.protocol_name,
                    COUNT(ae.id) as count
                FROM affiliator_protocols ap
                JOIN adverse_events ae ON ap.id = ae.protocol_id
                WHERE ap.affiliator_id = :affiliator_id
                GROUP BY ap.id, ap.protocol_name
                ORDER BY count DESC
                LIMIT 5
            ");
            $aeProtStmt->execute(['affiliator_id' => $affiliatorId]);
            $aeByProtocol = $aeProtStmt->fetchAll(PDO::FETCH_ASSOC);

            $responseData = [
                'kpi' => [
                    'total_patients' => $totalPatients,
                    'total_protocols' => $totalProtocols,
                    'total_adverse_events' => $totalAdverse,
                    'supervision' => $supervision ?: null
                ],
                'patient_trends' => $patientTrends,
                'ecrf_compliance' => [
                    'lengkap' => (int)($compliance['lengkap'] ?? 0),
                    'sedang_pengisian' => (int)($compliance['sedang_pengisian'] ?? 0),
                    'perlu_revisi' => (int)($compliance['perlu_revisi'] ?? 0)
                ],
                'adverse_event_by_protocol' => $aeByProtocol
            ];

            (new ApiResponse(true, 'Affiliator dashboard stats retrieved successfully', $responseData))->send(200);

        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }
}
