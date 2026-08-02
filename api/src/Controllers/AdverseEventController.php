<?php

namespace App\Controllers;

use App\Config\Database;
use App\Middleware\AuthMiddleware;
use App\Helpers\RequestHelper;
use App\Models\ApiResponse;
use PDO;

class AdverseEventController
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
     * Retrieve adverse events with pagination, filtering, and summary statistics.
     */
    public function get()
    {
        $user = AuthMiddleware::authorize(['affiliator', 'admin', 'reviewer']);

        try {
            $pdo = Database::getConnection();
            $userId = $user['data']['id'];

            // Resolve affiliator_id if logged in as affiliator
            $affiliatorId = $_GET['affiliator_id'] ?? null;
            if ($affiliatorId === null && $user['data']['role_name'] === 'affiliator') {
                $affiliatorId = $this->resolveAffiliatorId($user);
            }

            // Read filters
            $isFinished = $_GET['is_finished'] ?? "";
            $protocolId = $_GET['protocol_id'] ?? "";
            $startDate = $_GET['start_date'] ?? "";
            $endDate = $_GET['end_date'] ?? "";

            // Build conditions
            $conditions = [];
            $params = [];

            if ($affiliatorId !== null) {
                $conditions[] = "ae.affiliator_id = :affiliator_id";
                $params['affiliator_id'] = $affiliatorId;
            }

            if ($protocolId !== "" && $protocolId !== "semua") {
                $conditions[] = "ae.protocol_id = :protocol_id";
                $params['protocol_id'] = (int)$protocolId;
            }

            if (!empty($startDate)) {
                $conditions[] = "ae.report_date >= :start_date";
                $params['start_date'] = $startDate . ' 00:00:00';
            }

            if (!empty($endDate)) {
                $conditions[] = "ae.report_date <= :end_date";
                $params['end_date'] = $endDate . ' 23:59:59';
            }

            if (!empty($isFinished)) {
                $conditions[] = "ae.is_finished = :is_finished";
                $params['is_finished'] = $isFinished == "1" ? "true" : "false";
            }

            $customWhere = "";
            if (!empty($conditions)) {
                $customWhere = "WHERE " . implode(" AND ", $conditions);
            }

            // Base query with custom filters inside the subquery
            $query = "
                SELECT * FROM (
                    SELECT
                        ae.*,
                        p.patient_initial,
                        p.registration_number as patient_registration_number,
                        ap.protocol_name,
                        ap.protocol_version,
                        aff.affiliator_name
                    FROM adverse_events ae
                    LEFT JOIN patient_ecrfs p ON ae.patient_id = p.id
                    LEFT JOIN affiliator_protocols ap ON ae.protocol_id = ap.id
                    LEFT JOIN affiliators aff ON ae.affiliator_id = aff.id
                    {$customWhere}
                    ORDER BY ae.id DESC
                ) A
            ";

            // Dynamic table expression for pagination counting
            $tableName = "(
                SELECT ae.*, p.patient_initial, p.registration_number, aff.affiliator_name, ae.report_number 
                FROM adverse_events ae
                LEFT JOIN patient_ecrfs p ON ae.patient_id = p.id
                LEFT JOIN affiliators aff ON ae.affiliator_id = aff.id
                {$customWhere}
            ) A";

            $responseData = RequestHelper::paginate(
                pdo: $pdo,
                query: $query,
                tableName: $tableName,
                params: $params,
                filterFields: ['patient_initial', 'affiliator_name']
            );

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
            $affiliatorId = $this->resolveAffiliatorId($user);
            if (!$affiliatorId) {
                (new ApiResponse(false, 'User has no associated affiliator faskes.'))->send(403);
                return;
            }

            $data = RequestHelper::getBody();

            $patientId = $data['patient_id'] ?? null;
            $protocolId = $data['protocol_id'] ?? null;
            $eventType = trim($data['event_type'] ?? '');
            $severity = trim($data['severity'] ?? '');
            $actionTaken = trim($data['action_taken'] ?? '');
            $reporterName = trim($data['reporter_name'] ?? '');
            $isFinished = isset($data['is_finished']) ? ($data['is_finished'] === '1' || $data['is_finished'] === 1 || $data['is_finished'] === 'true' || $data['is_finished'] === true) : false;

            if ($patientId === null || $protocolId === null || $eventType === '' || $severity === '') {
                (new ApiResponse(false, 'patient_id, protocol_id, event_type, and severity are required'))->send(400);
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
                    event_type, severity, action_taken, reporter_name,
                    is_finished, created_by, updated_by
                ) VALUES (
                    :affiliator_id, :report_number, :patient_id, :protocol_id,
                    :event_type, :severity, :action_taken, :reporter_name,
                    :is_finished, :user_id, :user_id
                ) RETURNING *
            ");

            $stmt->execute([
                'affiliator_id' => $affiliatorId,
                'report_number'  => $reportNumber,
                'patient_id'    => $patientId,
                'protocol_id'   => $protocolId,
                'event_type'    => $eventType,
                'severity'      => $severity,
                'action_taken'  => $actionTaken === '' ? null : $actionTaken,
                'reporter_name' => $reporterName === '' ? null : $reporterName,
                'is_finished'   => $isFinished ? 'true' : 'false',
                'user_id'       => $userId
            ]);

            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            (new ApiResponse(true, 'Adverse event reported successfully', $result))->send(201);

        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }

    /**
     * Update an existing adverse event.
     */
    public function put()
    {
        $user = AuthMiddleware::authorize(['affiliator', 'admin']);

        try {
            $pdo = Database::getConnection();
            $data = RequestHelper::getBody();

            $id = $_GET['id'] ?? $data['id'] ?? null;
            if ($id === null) {
                (new ApiResponse(false, 'Adverse event ID is required.'))->send(400);
                return;
            }

            // Fetch current record to verify ownership
            $checkStmt = $pdo->prepare("SELECT affiliator_id FROM adverse_events WHERE id = :id");
            $checkStmt->execute(['id' => $id]);
            $aeRecord = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if (!$aeRecord) {
                (new ApiResponse(false, 'Adverse event record not found.'))->send(404);
                return;
            }

            // Resolve affiliator_id of logged in user
            $userId = $user['data']['id'];
            $roles = $user['data']['roles'] ?? [];
            $isAffiliator = in_array('affiliator', $roles);

            if ($isAffiliator) {
                $affiliatorId = $this->resolveAffiliatorId($user);
                if ($affiliatorId === null || (int)$aeRecord['affiliator_id'] !== $affiliatorId) {
                    (new ApiResponse(false, 'Forbidden: You do not own this adverse event record.'))->send(403);
                    return;
                }
            }

            // Get edit data
            $patientId = $data['patient_id'] ?? null;
            $protocolId = $data['protocol_id'] ?? null;
            $eventType = isset($data['event_type']) ? trim($data['event_type']) : null;
            $severity = isset($data['severity']) ? trim($data['severity']) : null;
            $actionTaken = isset($data['action_taken']) ? trim($data['action_taken']) : null;
            $reporterName = isset($data['reporter_name']) ? trim($data['reporter_name']) : null;
            $isFinished = isset($data['is_finished']) ? ($data['is_finished'] === '1' || $data['is_finished'] === 1 || $data['is_finished'] === 'true' || $data['is_finished'] === true) : false;

            if ($patientId === null || $protocolId === null || $eventType === '' || $severity === '') {
                (new ApiResponse(false, 'patient_id, protocol_id, event_type, and severity are required.'))->send(400);
                return;
            }

            $stmt = $pdo->prepare("
                UPDATE adverse_events
                SET patient_id = :patient_id,
                    protocol_id = :protocol_id,
                    event_type = :event_type,
                    severity = :severity,
                    action_taken = :action_taken,
                    reporter_name = :reporter_name,
                    is_finished = :is_finished,
                    updated_at = NOW(),
                    updated_by = :user_id
                WHERE id = :id
                RETURNING *
            ");

            $stmt->execute([
                'patient_id'    => $patientId,
                'protocol_id'   => $protocolId,
                'event_type'    => $eventType,
                'severity'      => $severity,
                'action_taken'  => $actionTaken === '' ? null : $actionTaken,
                'reporter_name' => $reporterName === '' ? null : $reporterName,
                'is_finished'   => $isFinished ? 'true' : 'false',
                'user_id'       => $userId,
                'id'            => $id
            ]);

            $updated = $stmt->fetch(PDO::FETCH_ASSOC);

            (new ApiResponse(true, 'Adverse event updated successfully', $updated))->send(200);

        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }

    /**
     * Delete an adverse event record.
     */
    public function delete()
    {
        $user = AuthMiddleware::authorize(['affiliator', 'admin']);

        try {
            $pdo = Database::getConnection();
            $id = $_GET['id'] ?? null;

            if ($id === null) {
                (new ApiResponse(false, 'Adverse event ID is required.'))->send(400);
                return;
            }

            // Fetch current record to verify ownership
            $checkStmt = $pdo->prepare("SELECT affiliator_id FROM adverse_events WHERE id = :id");
            $checkStmt->execute(['id' => $id]);
            $aeRecord = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if (!$aeRecord) {
                (new ApiResponse(false, 'Adverse event record not found.'))->send(404);
                return;
            }

            // Resolve affiliator_id of logged in user
            $userId = $user['data']['id'];
            $roles = $user['data']['roles'] ?? [];
            $isAffiliator = in_array('affiliator', $roles);

            if ($isAffiliator) {
                $affiliatorId = $this->resolveAffiliatorId($user);
                if ($affiliatorId === null || (int)$aeRecord['affiliator_id'] !== $affiliatorId) {
                    (new ApiResponse(false, 'Forbidden: You do not own this adverse event record.'))->send(403);
                    return;
                }
            }

            $stmt = $pdo->prepare("DELETE FROM adverse_events WHERE id = :id");
            $stmt->execute(['id' => $id]);

            (new ApiResponse(true, 'Adverse event deleted successfully'))->send(200);

        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }

    /**
     * Get statistics for adverse events (status and severity counts).
     */
    public function stats()
    {
        $user = AuthMiddleware::authorize(['affiliator', 'admin']);

        try {
            $pdo = Database::getConnection();
            $affiliatorId = null;

            if (in_array('affiliator', $user['data']['roles'] ?? [])) {
                $affiliatorId = $this->resolveAffiliatorId($user);
                if (!$affiliatorId) {
                    (new ApiResponse(false, 'User has no associated affiliator faskes.'))->send(400);
                    return;
                }
            }

            // Filters
            $protocolId = $_GET['protocol_id'] ?? "";
            $startDate = $_GET['start_date'] ?? "";
            $endDate = $_GET['end_date'] ?? "";

            $conditions = [];
            $params = [];

            if ($affiliatorId !== null) {
                $conditions[] = "affiliator_id = :affiliator_id";
                $params['affiliator_id'] = $affiliatorId;
            }

            if ($protocolId !== "" && $protocolId !== "semua" && $protocolId !== "all") {
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
                    COUNT(CASE WHEN is_finished = true THEN 1 END) as is_finished,
                    COUNT(CASE WHEN is_finished = false THEN 1 END) as is_not_finished,
                    COUNT(CASE WHEN severity = 0 THEN 1 END) as severity_0,
                    COUNT(CASE WHEN severity = 1 THEN 1 END) as severity_1,
                    COUNT(CASE WHEN severity = 2 THEN 1 END) as severity_2
                FROM adverse_events
                $whereClause
            ";

            $stmt = $pdo->prepare($sql);
            foreach ($params as $key => $val) {
                $stmt->bindValue(':' . $key, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $stmt->execute();
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);

            $responseData = [
                'status' => [
                    'is_finished' => (int)($stats['is_finished'] ?? 0),
                    'is_not_finished' => (int)($stats['is_not_finished'] ?? 0)
                ],
                'severity' => [
                    '0' => (int)($stats['severity_0'] ?? 0),
                    '1' => (int)($stats['severity_1'] ?? 0),
                    '2' => (int)($stats['severity_2'] ?? 0)
                ]
            ];

            (new ApiResponse(true, 'Adverse event stats retrieved successfully', $responseData))->send(200);

        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }
}
