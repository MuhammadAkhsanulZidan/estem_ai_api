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

            // Resolve affiliator_id if logged in as affiliator
            $affiliatorId = $_GET['affiliator_id'] ?? null;
            if ($affiliatorId === null && $user['data']['role_name'] === 'affiliator') {
                $affStmt = $pdo->prepare("SELECT affiliator_id FROM users WHERE id = :user_id");
                $affStmt->execute(['user_id' => $userId]);
                $affRow = $affStmt->fetch();
                $affiliatorId = $affRow ? $affRow['affiliator_id'] : null;
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
            $status = trim($data['status'] ?? 'Submitted');
            $actionTaken = trim($data['action_taken'] ?? '');
            $reporterName = trim($data['reporter_name'] ?? '');
            $isFinished = isset($data['is_finished']) ? ($data['is_finished'] === '1' || $data['is_finished'] === 1 || $data['is_finished'] === 'true' || $data['is_finished'] === true) : false;

            if (empty($patientId) || empty($protocolId) || empty($eventType) || empty($severity)) {
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
                    event_type, severity, status, action_taken, reporter_name,
                    is_finished, created_by, updated_by
                ) VALUES (
                    :affiliator_id, :report_number, :patient_id, :protocol_id,
                    :event_type, :severity, :status, :action_taken, :reporter_name,
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
                'status'        => $status,
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
     * Submit a reviewer decision on an adverse event.
     */
    public function review()
    {
        $user = AuthMiddleware::authorize(['reviewer', 'admin']);

        try {
            $pdo = Database::getConnection();
            $data = RequestHelper::getBody();

            $id = $data['id'] ?? null;
            $status = trim($data['status'] ?? '');
            $reviewerNote = trim($data['reviewer_note'] ?? '');

            if ($id === null || empty($status)) {
                (new ApiResponse(false, 'Adverse event ID and Status are required.'))->send(400);
                return;
            }

            // Allowed review statuses
            if (!in_array($status, ['Submitted', 'Dalam Review', 'Need Clarification', 'Need Revision', 'Approved', 'Ditolak'])) {
                (new ApiResponse(false, 'Invalid status.'))->send(400);
                return;
            }

            $stmt = $pdo->prepare("
                UPDATE adverse_events
                SET status = :status,
                    reviewer_note = :reviewer_note,
                    updated_at = NOW(),
                    updated_by = :user_id
                WHERE id = :id
                RETURNING *
            ");

            $stmt->execute([
                'status'        => $status,
                'reviewer_note' => $reviewerNote === '' ? null : $reviewerNote,
                'user_id'       => $user['data']['id'],
                'id'            => $id
            ]);

            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$result) {
                (new ApiResponse(false, 'Adverse event record not found.'))->send(404);
                return;
            }

            (new ApiResponse(true, 'Adverse event review decision submitted successfully', $result))->send(200);

        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }
}
