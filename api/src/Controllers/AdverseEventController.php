<?php

namespace App\Controllers;

use App\Config\Database;
use App\Middleware\AuthMiddleware;
use App\Models\ApiResponse;
use App\Helpers\RequestHelper;
use PDO;

class AdverseEventController
{
    /**
     * Retrieve adverse events with pagination, filtering, and summary statistics.
     */
    public function get()
    {
        $user = AuthMiddleware::authorize(['affiliator', 'admin', 'reviewer']);

        try {
            $pdo = Database::getConnection();
            $userId = $user['data']['id'];

            // 1. Resolve affiliator_id
            $affiliatorId = $_GET['affiliator_id'] ?? null;
            if ($affiliatorId === null && $user['data']['role_name'] === 'affiliator') {
                $affStmt = $pdo->prepare("SELECT affiliator_id FROM users WHERE id = :user_id");
                $affStmt->execute(['user_id' => $userId]);
                $affRow = $affStmt->fetch();
                $affiliatorId = $affRow ? $affRow['affiliator_id'] : null;
            }

            // Read filters
            $filterValue = $_GET['filter_value'] ?? ""; // General search query (patient initial, event type)
            $status = $_GET['status'] ?? ""; // Semua status / specific
            $protocolId = $_GET['protocol_id'] ?? "";
            $pageNo = isset($_GET['page_no']) ? (int)$_GET['page_no'] : 1;
            $pageRow = isset($_GET['page_row']) ? (int)$_GET['page_row'] : 10;

            // Build conditions
            $conditions = [];
            $params = [];

            if ($affiliatorId !== null) {
                $conditions[] = "ae.affiliator_id = :affiliator_id";
                $params['affiliator_id'] = $affiliatorId;
            }

            if ($status !== "" && strtolower($status) !== 'semua status') {
                $conditions[] = "ae.status = :status";
                $params['status'] = $status;
            }

            if ($protocolId !== "" && $protocolId !== "semua") {
                $conditions[] = "ae.protocol_id = :protocol_id";
                $params['protocol_id'] = (int)$protocolId;
            }

            if ($filterValue !== "") {
                $conditions[] = "(ae.event_type ILIKE :val OR p.patient_initial ILIKE :val OR p.registration_number ILIKE :val)";
                $params['val'] = '%' . $filterValue . '%';
            }

            $whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";

            // Query Statistics (Total, Sedang Dipantau, Selesai, Serius (SAE))
            $statConditions = [];
            $statParams = [];
            if ($affiliatorId !== null) {
                $statConditions[] = "affiliator_id = :affiliator_id";
                $statParams['affiliator_id'] = $affiliatorId;
            }
            $statWhere = !empty($statConditions) ? "WHERE " . implode(" AND ", $statConditions) : "";

            $statStmt = $pdo->prepare("
                SELECT 
                    COUNT(*) as total,
                    COUNT(CASE WHEN status = 'Sedang Dipantau' THEN 1 END) as pending,
                    COUNT(CASE WHEN status = 'Selesai' THEN 1 END) as resolved,
                    COUNT(CASE WHEN severity = 'Serius (SAE)' THEN 1 END) as sae
                FROM adverse_events
                $statWhere
            ");
            $statStmt->execute($statParams);
            $stats = $statStmt->fetch(PDO::FETCH_ASSOC);

            // 1. Get total items count
            $countQuery = "
                SELECT COUNT(*) 
                FROM adverse_events ae
                LEFT JOIN patient_ecrfs p ON ae.patient_id = p.id
                $whereClause
            ";
            $stmt = $pdo->prepare($countQuery);
            foreach ($params as $key => $val) {
                $stmt->bindValue(':' . $key, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $stmt->execute();
            $totalItems = (int)$stmt->fetchColumn();

            // 2. Get paginated results
            $query = "
                SELECT
                    ae.*,
                    p.patient_initial,
                    p.registration_number as patient_registration_number,
                    ap.protocol_name,
                    ap.protocol_version
                FROM adverse_events ae
                LEFT JOIN patient_ecrfs p ON ae.patient_id = p.id
                LEFT JOIN admin_protocols ap ON ae.protocol_id = ap.id
                $whereClause
                ORDER BY ae.id DESC
            ";

            $useLimit = $pageNo > 0 && $pageRow > 0;
            if ($useLimit) {
                $offset = ($pageNo - 1) * $pageRow;
                $query .= " LIMIT :limit OFFSET :offset";
            }

            $stmt = $pdo->prepare($query);
            foreach ($params as $key => $val) {
                $stmt->bindValue(':' . $key, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            if ($useLimit) {
                $stmt->bindValue(':limit', $pageRow, PDO::PARAM_INT);
                $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            }

            $stmt->execute();
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $responseData = [
                'items'       => $items,
                'total_items' => $totalItems,
                'page_no'     => $pageNo,
                'page_row'    => $pageRow,
                'stats'       => [
                    'total'   => (int)($stats['total'] ?? 0),
                    'pending' => (int)($stats['pending'] ?? 0),
                    'resolved'=> (int)($stats['resolved'] ?? 0),
                    'sae'     => (int)($stats['sae'] ?? 0)
                ]
            ];

            (new ApiResponse(true, 'Adverse events retrieved successfully', $responseData))->send(200);

        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }

    /**
     * Report / create a new adverse event.
     */
    public function post()
    {
        $user = AuthMiddleware::authorize(['affiliator']);

        try {
            $pdo = Database::getConnection();
            $userId = $user['data']['id'];

            // Resolve affiliator_id
            $affStmt = $pdo->prepare("SELECT affiliator_id FROM users WHERE id = :user_id");
            $affStmt->execute(['user_id' => $userId]);
            $affRow = $affStmt->fetch();
            $affiliatorId = $affRow ? $affRow['affiliator_id'] : null;

            if (!$affiliatorId) {
                (new ApiResponse(false, 'User has no associated affiliator faskes.'))->send(403);
                return;
            }

            $data = RequestHelper::getBody();

            $patientId = $data['patient_id'] ?? null;
            $protocolId = $data['protocol_id'] ?? null;
            $eventType = trim($data['event_type'] ?? '');
            $severity = trim($data['severity'] ?? '');
            $status = trim($data['status'] ?? 'Sedang Dipantau');
            $actionTaken = trim($data['action_taken'] ?? '');
            $reporterName = trim($data['reporter_name'] ?? '');

            if (!$patientId || !$protocolId || empty($eventType) || empty($severity)) {
                (new ApiResponse(false, 'patient_id, protocol_id, event_type, and severity are required.'))->send(400);
                return;
            }

            // Generate report number (AE-YYYY-XXXXX)
            $year = date('Y');
            $numStmt = $pdo->prepare("SELECT COUNT(*) FROM adverse_events WHERE report_number LIKE :pattern");
            $numStmt->execute(['pattern' => "AE-{$year}-%"]);
            $count = (int)$numStmt->fetchColumn() + 1;
            $reportNumber = sprintf("AE-%s-%05d", $year, $count);

            $stmt = $pdo->prepare("
                INSERT INTO adverse_events (
                    affiliator_id, report_number, patient_id, protocol_id,
                    event_type, severity, status, action_taken, reporter_name,
                    created_by, updated_by
                ) VALUES (
                    :affiliator_id, :report_number, :patient_id, :protocol_id,
                    :event_type, :severity, :status, :action_taken, :reporter_name,
                    :user_id, :user_id
                ) RETURNING *
            ");

            $stmt->execute([
                'affiliator_id' => $affiliatorId,
                'report_number'  => $reportNumber,
                'patient_id'    => $patientId,
                'protocol_id'   => $protocolId,
                'event_type'    => $eventType,
                'severity'      => $severity,
                'status'        => $status,
                'action_taken'  => $actionTaken === '' ? null : $actionTaken,
                'reporter_name' => $reporterName === '' ? null : $reporterName,
                'user_id'       => $userId
            ]);

            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            (new ApiResponse(true, 'Adverse event reported successfully', $result))->send(201);

        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }
}
