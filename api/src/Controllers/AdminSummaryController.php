<?php

namespace App\Controllers;

use App\Config\Database;
use App\Middleware\AuthMiddleware;
use App\Models\ApiResponse;
use App\Helpers\RequestHelper;
use PDO;

class AdminSummaryController
{
    /**
     * Endpoint 1: GET /v1/admin-summary/stats/protocols
     * Retrieve total active protocols and top 5 affiliators by protocol count.
     */
    public function protocols()
    {
        AuthMiddleware::authorize(['admin']);

        try {
            $pdo = Database::getConnection();

            // Total active protocols in admin
            $totalStmt = $pdo->query("SELECT COUNT(*) FROM admin_protocols");
            $totalActive = (int)$totalStmt->fetchColumn();

            // Top 5 affiliators based on protocol count
            $topStmt = $pdo->query("
                SELECT 
                    a.affiliator_name,
                    COUNT(ap.id) as protocol_count
                FROM affiliators a
                LEFT JOIN affiliator_protocols ap ON a.id = ap.affiliator_id
                GROUP BY a.id, a.affiliator_name
                ORDER BY protocol_count DESC
                LIMIT 5
            ");
            $topAffiliators = $topStmt->fetchAll(PDO::FETCH_ASSOC);

            $responseData = [
                'total_active_protocols' => $totalActive,
                'top_5_affiliators_by_protocols' => $topAffiliators
            ];

            (new ApiResponse(true, 'Protocol stats retrieved successfully', $responseData))->send(200);

        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }

    /**
     * Endpoint 2: GET /v1/admin-summary/stats/patients
     * Retrieve total patient count, optionally filtered by date, affiliator, or protocol.
     */
    public function patients()
    {
        AuthMiddleware::authorize(['admin']);

        try {
            $pdo = Database::getConnection();

            $affiliatorId = $_GET['affiliator_id'] ?? "";
            $protocolId   = $_GET['protocol_id'] ?? "";
            $startDate    = $_GET['start_date'] ?? "";
            $endDate      = $_GET['end_date'] ?? "";

            $conditions = [];
            $params = [];

            if ($affiliatorId !== "") {
                $conditions[] = "affiliator_id = :affiliator_id";
                $params['affiliator_id'] = (int)$affiliatorId;
            }

            if ($protocolId !== "") {
                $conditions[] = "protocol_id = :protocol_id";
                $params['protocol_id'] = (int)$protocolId;
            }

            if (!empty($startDate)) {
                $conditions[] = "created_at >= :start_date";
                $params['start_date'] = $startDate . ' 00:00:00';
            }

            if (!empty($endDate)) {
                $conditions[] = "created_at <= :end_date";
                $params['end_date'] = $endDate . ' 23:59:59';
            }

            $whereClause = "";
            if (!empty($conditions)) {
                $whereClause = "WHERE " . implode(" AND ", $conditions);
            }

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM patient_ecrfs {$whereClause}");
            foreach ($params as $key => $val) {
                $stmt->bindValue(':' . $key, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $stmt->execute();
            $totalPatients = (int)$stmt->fetchColumn();

            (new ApiResponse(true, 'Patient stats retrieved successfully', ['total_patients' => $totalPatients]))->send(200);

        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }

    /**
     * Endpoint 3: GET /v1/admin-summary/stats/adverse-events
     * Retrieve total adverse events count and severity breakdown.
     */
    public function adverseEvents()
    {
        AuthMiddleware::authorize(['admin']);

        try {
            $pdo = Database::getConnection();

            $affiliatorId = $_GET['affiliator_id'] ?? "";
            $protocolId   = $_GET['protocol_id'] ?? "";
            $startDate    = $_GET['start_date'] ?? "";
            $endDate      = $_GET['end_date'] ?? "";

            $conditions = [];
            $params = [];

            if ($affiliatorId !== "") {
                $conditions[] = "affiliator_id = :affiliator_id";
                $params['affiliator_id'] = (int)$affiliatorId;
            }

            if ($protocolId !== "") {
                $conditions[] = "protocol_id = :protocol_id";
                $params['protocol_id'] = (int)$protocolId;
            }

            if (!empty($startDate)) {
                $conditions[] = "report_date >= :start_date";
                $params['start_date'] = $startDate . ' 00:00:00';
            }

            if (!empty($endDate)) {
                $conditions[] = "report_date <= :end_date";
                $params['end_date'] = $endDate . ' 23:59:59';
            }

            $whereClause = "";
            if (!empty($conditions)) {
                $whereClause = "WHERE " . implode(" AND ", $conditions);
            }

            $sql = "
                SELECT 
                    COUNT(*) as total_events,
                    COUNT(CASE WHEN severity = 0 THEN 1 END) as severity_0,
                    COUNT(CASE WHEN severity = 1 THEN 1 END) as severity_1,
                    COUNT(CASE WHEN severity = 2 THEN 1 END) as severity_2,
                    COUNT(CASE WHEN severity = 3 THEN 1 END) as severity_3
                FROM adverse_events
                {$whereClause}
            ";

            $stmt = $pdo->prepare($sql);
            foreach ($params as $key => $val) {
                $stmt->bindValue(':' . $key, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $stmt->execute();
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);

            // Group adverse events by parent admin protocol
            $distStmt = $pdo->prepare("
                SELECT 
                    afp.protocol_name,
                    COUNT(ae.id) as ae_count
                FROM affiliator_protocols afp
                LEFT JOIN patient_ecrfs pe ON afp.id = pe.protocol_id
                LEFT JOIN adverse_events ae ON pe.id = ae.patient_id
                GROUP BY afp.id, afp.protocol_name
                ORDER BY ae_count DESC
                LIMIT 5
            ");
            $distStmt->execute();
            $distribution = $distStmt->fetchAll(PDO::FETCH_ASSOC);

            // Cast counts to integer
            foreach ($distribution as &$d) {
                $d['ae_count'] = (int)$d['ae_count'];
            }

            $responseData = [
                'total_events' => (int)($stats['total_events'] ?? 0),
                'severity' => [
                    '0' => (int)($stats['severity_0'] ?? 0),
                    '1' => (int)($stats['severity_1'] ?? 0),
                    '2' => (int)($stats['severity_2'] ?? 0),
                    '3' => (int)($stats['severity_3'] ?? 0)
                ],
                'protocol_distribution' => $distribution
            ];

            (new ApiResponse(true, 'Adverse event stats retrieved successfully', $responseData))->send(200);

        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }

    /**
     * Endpoint 4: GET /v1/admin-summary/reports/protocols
     * Paginated overview mapping admin protocols through affiliator protocols to patient & adverse event counts.
     */
    public function reportsProtocols()
    {
        AuthMiddleware::authorize(['admin']);

        try {
            $pdo = Database::getConnection();

            $affiliatorId = $_GET['affiliator_id'] ?? "";
            $startDate    = $_GET['start_date'] ?? "";
            $endDate      = $_GET['end_date'] ?? "";

            // Filters to join subqueries
            $peFilter = "";
            $aeFilter = "";
            $params = [];

            if ($affiliatorId !== "") {
                $peFilter .= " AND pe.affiliator_id = :pe_aff_id";
                $aeFilter .= " AND ae_sub.affiliator_id = :ae_aff_id";
                $params['pe_aff_id'] = (int)$affiliatorId;
                $params['ae_aff_id'] = (int)$affiliatorId;
            }

            if (!empty($startDate)) {
                $peFilter .= " AND pe.created_at >= :pe_start";
                $aeFilter .= " AND ae_sub.report_date >= :ae_start";
                $params['pe_start'] = $startDate . ' 00:00:00';
                $params['ae_start'] = $startDate . ' 00:00:00';
            }

            if (!empty($endDate)) {
                $peFilter .= " AND pe.created_at <= :pe_end";
                $aeFilter .= " AND ae_sub.report_date <= :ae_end";
                $params['pe_end'] = $endDate . ' 23:59:59';
                $params['ae_end'] = $endDate . ' 23:59:59';
            }

            $query = "
                SELECT 
                    ap.id as protocol_id,
                    ap.protocol_name,
                    COALESCE(p.patient_count, 0) as total_patients,
                    COALESCE(ae.ae_count, 0) as total_adverse_events
                FROM affiliator_protocols ap
                LEFT JOIN (
                    SELECT 
                        pe.protocol_id,
                        COUNT(pe.id) as patient_count
                    FROM patient_ecrfs pe
                    WHERE 1=1 {$peFilter}
                    GROUP BY pe.protocol_id
                ) p ON ap.id = p.protocol_id
                LEFT JOIN (
                    SELECT 
                        pe_sub.protocol_id,
                        COUNT(ae_sub.id) as ae_count
                    FROM patient_ecrfs pe_sub
                    JOIN adverse_events ae_sub ON pe_sub.id = ae_sub.patient_id
                    WHERE 1=1 {$aeFilter}
                    GROUP BY pe_sub.protocol_id
                ) ae ON ap.id = ae.protocol_id
            ";

            $tableName = "affiliator_protocols ap";

            $responseData = RequestHelper::paginate(
                pdo: $pdo,
                query: $query,
                tableName: $tableName,
                params: $params,
                filterFields: ['protocol_name']
            );

            (new ApiResponse(true, 'Protocol reports retrieved successfully', $responseData))->send(200);

        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }
}
