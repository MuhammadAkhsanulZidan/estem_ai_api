<?php

namespace App\Controllers;

use App\Config\Database;
use App\Middleware\AuthMiddleware;
use App\Helpers\RequestHelper;
use App\Models\ApiResponse;
use PDO;

class PatientEcrfController
{
    /**
     * Get patient eCRF template schema and answers.
     */
    public function get()
    {
        $user = AuthMiddleware::authorize(['affiliator', 'admin']);

        try {
            $pdo = Database::getConnection();

            $patientId = $_GET['patient_id'] ?? null;
            $protocolId = $_GET['protocol_id'] ?? null;

            if ($patientId === null || $protocolId === null) {
                (new ApiResponse(false, 'Patient ID and Protocol ID are required'))->send(400);
            }

            // 1. Fetch eCRF template sections using admin protocol questions
            $stmt = $pdo->prepare("
                SELECT
                    es.id AS section_id,
                    es.section_name,
                    ape.questions_schema
                FROM ecrf_sections es
                LEFT JOIN admin_protocol_ecrfs ape
                    ON es.id = ape.section_id AND ape.protocol_id = (
                        SELECT protocol_reference_id 
                        FROM affiliator_protocols 
                        WHERE id = :protocol_id
                    )
                ORDER BY es.id ASC
            ");
            $stmt->execute(['protocol_id' => $protocolId]);
            $templateRows = $stmt->fetchAll();

            // 2. Fetch patient responses
            $resStmt = $pdo->prepare("
                SELECT section_id, answers_data, is_posted, is_approved, reviewer_note
                FROM patient_ecrf_responses
                WHERE patient_id = :patient_id AND protocol_id = :protocol_id
            ");
            $resStmt->execute(['patient_id' => $patientId, 'protocol_id' => $protocolId]);
            $responseRows = $resStmt->fetchAll();

            $responses = [];
            foreach ($responseRows as $r) {
                $responses[(int)$r['section_id']] = [
                    'answers' => json_decode($r['answers_data'] ?? '{}', true) ?: new \stdClass(),
                    'is_posted' => (bool)$r['is_posted'],
                    'is_approved' => (bool)$r['is_approved'],
                    'reviewer_note' => $r['reviewer_note']
                ];
            }

            $rawSections = [
                1 => [],
                2 => [],
                3 => [],
                4 => []
            ];

            foreach ($templateRows as $row) {
                $secId = (int)$row['section_id'];
                $rawSections[$secId] = json_decode($row['questions_schema'] ?? '[]', true) ?: [];
            }

            // 3. Compute dynamic locking logic based on preceding approvals
            $s1Approved = !empty($responses[1]['is_approved']);
            $s2Approved = $s1Approved && !empty($responses[2]['is_approved']);
            $s3Approved = $s2Approved && !empty($responses[3]['is_approved']);

            $isL2Locked = !$s1Approved;
            $isL3Locked = $isL2Locked || !$s2Approved;
            $isL4Locked = $isL3Locked || !$s3Approved;

            $sections = [
                'persiapan' => [
                    'section_id' => 1,
                    'questions' => $rawSections[1],
                    'answers' => $responses[1]['answers'] ?? new \stdClass(),
                    'is_posted' => $responses[1]['is_posted'] ?? false,
                    'is_approved' => $responses[1]['is_approved'] ?? false,
                    'reviewer_note' => $responses[1]['reviewer_note'] ?? null,
                    'is_locked' => false
                ],
                'pelaksanaan' => [
                    'section_id' => 2,
                    'questions' => $rawSections[2],
                    'answers' => $responses[2]['answers'] ?? new \stdClass(),
                    'is_posted' => $responses[2]['is_posted'] ?? false,
                    'is_approved' => $responses[2]['is_approved'] ?? false,
                    'reviewer_note' => $responses[2]['reviewer_note'] ?? null,
                    'is_locked' => $isL2Locked
                ],
                'monitoring' => [
                    'section_id' => 3,
                    'questions' => $rawSections[3],
                    'answers' => $responses[3]['answers'] ?? new \stdClass(),
                    'is_posted' => $responses[3]['is_posted'] ?? false,
                    'is_approved' => $responses[3]['is_approved'] ?? false,
                    'reviewer_note' => $responses[3]['reviewer_note'] ?? null,
                    'is_locked' => $isL3Locked
                ],
                'evaluasi' => [
                    'section_id' => 4,
                    'questions' => $rawSections[4],
                    'answers' => $responses[4]['answers'] ?? new \stdClass(),
                    'is_posted' => $responses[4]['is_posted'] ?? false,
                    'is_approved' => $responses[4]['is_approved'] ?? false,
                    'reviewer_note' => $responses[4]['reviewer_note'] ?? null,
                    'is_locked' => $isL4Locked
                ]
            ];

            (new ApiResponse(true, 'Patient eCRF data retrieved', $sections))->send(200);
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }

    /**
     * Upsert patient eCRF section answers.
     */
    public function post()
    {
        $user = AuthMiddleware::authorize(['affiliator']);

        try {
            $pdo = Database::getConnection();
            $data = RequestHelper::getBody();

            $patientId = $data['patient_id'] ?? null;
            $protocolId = $data['protocol_id'] ?? null;
            $sectionId = $data['section_id'] ?? null;
            $answersData = $data['answers_data'] ?? [];
            $isPosted = !empty($data['is_posted']);

            if ($patientId === null || $protocolId === null || $sectionId === null) {
                (new ApiResponse(false, 'Patient ID, Protocol ID, and Section ID are required'))->send(400);
            }

            // 1. Perform template and lock checking before saving
            if ((int)$sectionId > 1) {
                $prevSecId = (int)$sectionId - 1;
                $chk = $pdo->prepare("SELECT is_approved FROM patient_ecrf_responses WHERE patient_id = :patient_id AND protocol_id = :protocol_id AND section_id = :section_id");
                $chk->execute([
                    'patient_id' => $patientId,
                    'protocol_id' => $protocolId,
                    'section_id' => $prevSecId
                ]);
                $prevRes = $chk->fetch();
                if (!$prevRes || !$prevRes['is_approved']) {
                    (new ApiResponse(false, 'Cannot save answers because previous section is locked or not approved by reviewer.'))->send(403);
                }
            }

            // 2. Perform validations if is_posted is true
            if ($isPosted) {
                $tStmt = $pdo->prepare("
                    SELECT questions_schema 
                    FROM admin_protocol_ecrfs 
                    WHERE section_id = :section_id AND protocol_id = (
                        SELECT protocol_reference_id 
                        FROM affiliator_protocols 
                        WHERE id = :protocol_id
                    )
                ");
                $tStmt->execute(['protocol_id' => $protocolId, 'section_id' => $sectionId]);
                $tRow = $tStmt->fetch();
                $questions = $tRow ? json_decode($tRow['questions_schema'] ?? '[]', true) : [];

                foreach ($questions as $q) {
                    if (!empty($q['required'])) {
                        $qId = $q['id'];
                        $ansVal = $answersData[$qId] ?? null;

                        if ($ansVal === null || $ansVal === '' || (is_array($ansVal) && empty($ansVal))) {
                            (new ApiResponse(false, "Pertanyaan '{$q['label']}' wajib diisi sebelum mengirim pengajuan."))->send(400);
                        }
                    }
                }
            }

            $userId = $user['data']['id'];
            $jsonAnswers = json_encode($answersData);

            // 3. PostgreSQL UPSERT
            $stmt = $pdo->prepare("
                INSERT INTO patient_ecrf_responses (patient_id, protocol_id, section_id, answers_data, is_posted, created_by, updated_by, created_at, updated_at)
                VALUES (:patient_id, :protocol_id, :section_id, :answers_data::jsonb, :is_posted, :user_id, :user_id, NOW(), NOW())
                ON CONFLICT (patient_id, protocol_id, section_id)
                DO UPDATE SET
                    answers_data = EXCLUDED.answers_data,
                    is_posted = EXCLUDED.is_posted,
                    updated_by = EXCLUDED.updated_by,
                    updated_at = NOW()
                RETURNING *
            ");

            // FIX: Pass the array of parameters to execute()
            $stmt->execute([
                'patient_id'   => $patientId,
                'protocol_id'  => $protocolId,
                'section_id'   => $sectionId,
                'answers_data' => $jsonAnswers,
                'is_posted'    => $isPosted ? 'true' : 'false',
                'user_id'      => $userId,
            ]);
            $result = $stmt->fetch();

            (new ApiResponse(true, 'Answers saved successfully', $result))->send(200);
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }

    /**
     * Get list of eCRF sections submitted for review.
     */
    public function getReviewList()
    {
        AuthMiddleware::authorize(['reviewer', 'admin']);

        try {
            $pdo = Database::getConnection();

            $searchTerm = $_GET['filter_value'] ?? "";
            $status = $_GET['status'] ?? "Semua"; // Semua, submitted, review, revision, approved, rejected
            $pageNo = isset($_GET['page_no']) ? (int)$_GET['page_no'] : 1;
            $pageRow = isset($_GET['page_row']) ? (int)$_GET['page_row'] : 10;

            $where = "WHERE per.is_posted = TRUE OR (per.is_posted = FALSE AND per.reviewer_note IS NOT NULL AND per.reviewer_note != '')";
            $params = [];

            // Apply search term filter
            if ($searchTerm !== "") {
                $where .= " AND (ap.protocol_name ILIKE :search OR pe.patient_initial ILIKE :search OR pe.registration_number ILIKE :search)";
                $params['search'] = '%' . $searchTerm . '%';
            }

            // Apply status filter
            if ($status !== "Semua") {
                if ($status === "submitted" || $status === "review") {
                    $where .= " AND per.is_posted = TRUE AND per.is_approved = FALSE AND (per.reviewer_note IS NULL OR per.reviewer_note = '')";
                } else if ($status === "revision") {
                    $where .= " AND per.is_posted = TRUE AND per.is_approved = FALSE AND per.reviewer_note IS NOT NULL AND per.reviewer_note != ''";
                } else if ($status === "approved") {
                    $where .= " AND per.is_approved = TRUE";
                } else if ($status === "rejected") {
                    $where .= " AND per.is_posted = FALSE AND per.reviewer_note IS NOT NULL AND per.reviewer_note != ''";
                }
            }

            // Count query
            $countStmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM patient_ecrf_responses per
                JOIN patient_ecrfs pe ON per.patient_id = pe.id
                JOIN admin_protocols ap ON per.protocol_id = ap.id
                JOIN ecrf_sections es ON per.section_id = es.id
                $where
            ");
            $countStmt->execute($params);
            $totalItems = (int)$countStmt->fetchColumn();

            // Paginated data query
            $sql = "
                SELECT
                    per.id,
                    per.patient_id,
                    per.protocol_id,
                    per.section_id,
                    per.is_posted,
                    per.is_approved,
                    per.reviewer_note,
                    per.answers_data,
                    per.updated_at AS submission_date,
                    pe.registration_number,
                    pe.patient_initial,
                    pe.gender,
                    pe.pic_doctor,
                    ap.protocol_name,
                    ap.protocol_version,
                    es.section_name,
                    ape.questions_schema
                FROM patient_ecrf_responses per
                JOIN patient_ecrfs pe ON per.patient_id = pe.id
                JOIN admin_protocols ap ON per.protocol_id = ap.id
                JOIN ecrf_sections es ON per.section_id = es.id
                LEFT JOIN admin_protocol_ecrfs ape ON per.protocol_id = ape.protocol_id AND per.section_id = ape.section_id
                $where
                ORDER BY per.updated_at DESC
                LIMIT :limit OFFSET :offset
            ";

            $offset = ($pageNo - 1) * $pageRow;
            $stmt = $pdo->prepare($sql);
            foreach ($params as $key => $val) {
                $stmt->bindValue(':' . $key, $val, PDO::PARAM_STR);
            }
            $stmt->bindValue(':limit', $pageRow, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $items = $stmt->fetchAll();

            // Format documents count and description metadata dynamically
            foreach ($items as &$item) {
                $item['answers'] = json_decode($item['answers_data'], true) ?: new \stdClass();
                $item['questions_schema'] = json_decode($item['questions_schema'] ?? '[]', true) ?: [];
                $item['documents_count'] = 6; // Mock standard/clinical guideline docs count for detail panel
                $item['description'] = "eCRF desain untuk pengumpulan data baseline dan follow-up pasien.";
            }

            $responseData = [
                'items' => $items,
                'total_items' => $totalItems,
                'page_no' => $pageNo,
                'page_row' => $pageRow
            ];

            (new ApiResponse(true, 'Submitted eCRFs retrieved successfully', $responseData))->send(200);
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }

    /**
     * Submit reviewer decision (approve, revision, reject) for a patient's eCRF stage.
     */
    public function postReview()
    {
        $user = AuthMiddleware::authorize(['reviewer', 'admin']);

        try {
            $pdo = Database::getConnection();
            $data = RequestHelper::getBody();

            $id = $data['id'] ?? null;
            $decision = trim($data['decision'] ?? ''); // approve, revision, reject
            $reviewerNote = trim($data['reviewer_note'] ?? '');

            if ($id === null || empty($decision)) {
                (new ApiResponse(false, 'Response ID and Decision are required'))->send(400);
            }

            // Check if response exists
            $stmt = $pdo->prepare("SELECT * FROM patient_ecrf_responses WHERE id = :id");
            $stmt->execute(['id' => $id]);
            $existing = $stmt->fetch();

            if (!$existing) {
                (new ApiResponse(false, 'eCRF response record not found'))->send(404);
            }

            $isApproved = ($decision === 'approve');
            $isPosted = ($decision !== 'reject'); // Set is_posted to false if rejected, allowing reload

            $isApprovedStr = $isApproved ? 'true' : 'false';
            $isPostedStr = $isPosted ? 'true' : 'false';

            $updateStmt = $pdo->prepare("
                UPDATE patient_ecrf_responses
                SET is_approved = :is_approved,
                    is_posted = :is_posted,
                    reviewer_note = :reviewer_note,
                    approved_by = CASE WHEN :is_approved = TRUE THEN :user_id ELSE approved_by END,
                    approved_at = CASE WHEN :is_approved = TRUE THEN NOW() ELSE approved_at END,
                    updated_by = :user_id,
                    updated_at = NOW()
                WHERE id = :id
                RETURNING *
            ");

            // FIX: Pass parameters array directly to execute()
            $updateStmt->execute([
                'is_approved'   => $isApprovedStr,
                'is_posted'     => $isPostedStr,
                'reviewer_note' => $reviewerNote === '' ? null : $reviewerNote,
                'user_id'       => $userId,
                'id'            => $id,
            ]);
            $result = $updateStmt->fetch();
            $updateStmt->bindValue(':is_approved', $isApproved, PDO::PARAM_BOOL);
            $updateStmt->bindValue(':is_posted', $isPosted, PDO::PARAM_BOOL);
            $updateStmt->bindValue(':reviewer_note', $reviewerNote === '' ? null : $reviewerNote, $reviewerNote === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $updateStmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $updateStmt->bindValue(':id', $id, PDO::PARAM_INT);

            $updateStmt->execute();
            $result = $updateStmt->fetch();

            (new ApiResponse(true, 'Review decision saved successfully', $result))->send(200);
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }
}
