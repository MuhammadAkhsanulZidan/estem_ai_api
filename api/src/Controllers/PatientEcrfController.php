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
        $user = AuthMiddleware::authorize(['affiliator', 'admin', 'reviewer']);

        try {
            $pdo = Database::getConnection();

            $patientId = $_GET['patient_id'] ?? null;
            $protocolId = $_GET['protocol_id'] ?? null;
            $sessionNumber = isset($_GET['session_number']) ? (int)$_GET['session_number'] : 1;

            if ($patientId === null || $protocolId === null) {
                (new ApiResponse(false, 'Patient ID and Protocol ID are required'))->send(400);
            }

            // 1. Fetch E-CRF template sections
            $stmt = $pdo->prepare("
                SELECT id AS section_id, section_name
                FROM ecrf_sections
                ORDER BY id ASC
            ");
            $stmt->execute();
            $templateRows = $stmt->fetchAll();

            // Fetch E-CRF template questions
            $globalStmt = $pdo->prepare("SELECT section_id, questions_schema FROM ecrf_templates");
            $globalStmt->execute();
            $globals = $globalStmt->fetchAll(PDO::FETCH_ASSOC);
            $globalSchemaMap = [];
            foreach ($globals as $g) {
                $globalSchemaMap[(int)$g['section_id']] = json_decode($g['questions_schema'] ?? '[]', true) ?: [];
            }

            // 2. Fetch patient responses for this specific session
            $resStmt = $pdo->prepare("
                SELECT id, section_id, answers_data, is_posted, is_approved, is_revised, is_reviewed, reviewer_note
                FROM patient_ecrf_responses
                WHERE patient_id = :patient_id AND protocol_id = :protocol_id AND session_number = :session_number
            ");
            $resStmt->execute([
                'patient_id'     => $patientId,
                'protocol_id'    => $protocolId,
                'session_number' => $sessionNumber
            ]);
            $responseRows = $resStmt->fetchAll();

            $responses = [];
            foreach ($responseRows as $r) {
                $responses[(int)$r['section_id']] = [
                    'id' => (int)$r['id'],
                    'answers' => json_decode($r['answers_data'] ?? '{}', true) ?: new \stdClass(),
                    'is_posted' => (bool)$r['is_posted'],
                    'is_approved' => (bool)$r['is_approved'],
                    'is_revised' => (bool)$r['is_revised'],
                    'is_reviewed' => (bool)$r['is_reviewed'],
                    'reviewer_note' => $r['reviewer_note']
                ];
            }

            // 3. Fetch all saved sessions / iterations for this patient
            $sessionsStmt = $pdo->prepare("
                SELECT DISTINCT id, section_id, session_number, is_posted, is_approved, is_revised, is_reviewed, reviewer_note
                FROM patient_ecrf_responses
                WHERE patient_id = :patient_id AND protocol_id = :protocol_id
                ORDER BY session_number ASC
            ");
            $sessionsStmt->execute([
                'patient_id'  => $patientId,
                'protocol_id' => $protocolId
            ]);
            $allSessionsList = $sessionsStmt->fetchAll(PDO::FETCH_ASSOC);

            $rawSections = [
                1 => [],
                2 => [],
                3 => [],
                4 => []
            ];

            foreach ($templateRows as $row) {
                $secId = (int)$row['section_id'];
                $customQuestions = json_decode($row['questions_schema'] ?? '[]', true) ?: [];
                $globalQuestions = $globalSchemaMap[$secId] ?? [];
                
                // Merge global and custom questions (with global questions prepended)
                $rawSections[$secId] = array_merge($globalQuestions, $customQuestions);
            }

            // Lock logic is disabled because flow is non-sequential as requested
            $sections = [
                'persiapan' => [
                    'id' => $responses[1]['id'] ?? null,
                    'section_id' => 1,
                    'questions' => $rawSections[1],
                    'answers' => $responses[1]['answers'] ?? new \stdClass(),
                    'is_posted' => $responses[1]['is_posted'] ?? false,
                    'is_approved' => $responses[1]['is_approved'] ?? false,
                    'is_reviewed' => $responses[1]['is_reviewed'] ?? false,
                    'is_revised' => $responses[1]['is_revised'] ?? false,
                    'reviewer_note' => $responses[1]['reviewer_note'] ?? null,
                    'is_locked' => false
                ],
                'pelaksanaan' => [
                    'id' => $responses[2]['id'] ?? null,
                    'section_id' => 2,
                    'questions' => $rawSections[2],
                    'answers' => $responses[2]['answers'] ?? new \stdClass(),
                    'is_posted' => $responses[2]['is_posted'] ?? false,
                    'is_approved' => $responses[2]['is_approved'] ?? false,
                    'is_reviewed' => $responses[2]['is_reviewed'] ?? false,
                    'is_revised' => $responses[2]['is_revised'] ?? false,
                    'reviewer_note' => $responses[2]['reviewer_note'] ?? null,
                    'is_locked' => false
                ],
                'monitoring' => [
                    'id' => $responses[3]['id'] ?? null,
                    'section_id' => 3,
                    'questions' => $rawSections[3],
                    'answers' => $responses[3]['answers'] ?? new \stdClass(),
                    'is_posted' => $responses[3]['is_posted'] ?? false,
                    'is_approved' => $responses[3]['is_approved'] ?? false,
                    'is_reviewed' => $responses[3]['is_reviewed'] ?? false,
                    'is_revised' => $responses[3]['is_revised'] ?? false,
                    'reviewer_note' => $responses[3]['reviewer_note'] ?? null,
                    'is_locked' => false
                ],
                'evaluasi' => [
                    'id' => $responses[4]['id'] ?? null,
                    'section_id' => 4,
                    'questions' => $rawSections[4],
                    'answers' => $responses[4]['answers'] ?? new \stdClass(),
                    'is_posted' => $responses[4]['is_posted'] ?? false,
                    'is_approved' => $responses[4]['is_approved'] ?? false,
                    'is_reviewed' => $responses[4]['is_reviewed'] ?? false,
                    'is_revised' => $responses[4]['is_revised'] ?? false,
                    'reviewer_note' => $responses[4]['reviewer_note'] ?? null,
                    'is_locked' => false
                ]
            ];

            $responsePayload = [
                'sections' => $sections,
                'all_sessions' => $allSessionsList
            ];

            (new ApiResponse(true, 'Patient eCRF data retrieved', $responsePayload))->send(200);
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
            $sessionNumber = isset($data['session_number']) ? (int)$data['session_number'] : 1;

            if ($patientId === null || $protocolId === null || $sectionId === null) {
                (new ApiResponse(false, 'Patient ID, Protocol ID, and Section ID are required'))->send(400);
            }

            // 1. Perform validations if is_posted is true
            if ($isPosted) {
                // Fetch template questions
                $gStmt = $pdo->prepare("
                    SELECT questions_schema 
                    FROM ecrf_templates 
                    WHERE section_id = :section_id
                ");
                $gStmt->execute(['section_id' => $sectionId]);
                $gRow = $gStmt->fetch();
                $questions = $gRow ? json_decode($gRow['questions_schema'] ?? '[]', true) : [];

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

            // Check if response already exists
            $stmt = $pdo->prepare("
                SELECT * FROM patient_ecrf_responses 
                WHERE patient_id = :patient_id 
                  AND protocol_id = :protocol_id 
                  AND section_id = :section_id 
                  AND session_number = :session_number
            ");
            $stmt->execute([
                'patient_id'     => $patientId,
                'protocol_id'    => $protocolId,
                'section_id'     => $sectionId,
                'session_number' => $sessionNumber
            ]);
            $existing = $stmt->fetch();

            // Map flags depending on submission state
            $isReviewed = $existing ? $existing['is_reviewed'] : false;
            $isRevised = $existing ? $existing['is_revised'] : false;
            $isApproved = $existing ? $existing['is_approved'] : false;
            $reviewerNote = $existing ? $existing['reviewer_note'] : null;

            if ($isPosted) {
                // If submitted, reset review status flags back to false
                $isReviewed = false;
                $isRevised = false;
                $isApproved = false;
                $reviewerNote = null;
            }

            // Insert or Update depending on existence
            if ($existing) {
                $stmt = $pdo->prepare("
                    UPDATE patient_ecrf_responses
                    SET answers_data = :answers_data::jsonb,
                        is_posted = :is_posted,
                        is_reviewed = :is_reviewed,
                        is_revised = :is_revised,
                        is_approved = :is_approved,
                        reviewer_note = :reviewer_note,
                        updated_by = :user_id,
                        updated_at = NOW()
                    WHERE id = :id
                    RETURNING *
                ");
                $stmt->execute([
                    'answers_data' => $jsonAnswers,
                    'is_posted'    => $isPosted ? 'true' : 'false',
                    'is_reviewed'  => $isReviewed ? 'true' : 'false',
                    'is_revised'   => $isRevised ? 'true' : 'false',
                    'is_approved'  => $isApproved ? 'true' : 'false',
                    'reviewer_note'=> $reviewerNote,
                    'user_id'      => $userId,
                    'id'           => $existing['id']
                ]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO patient_ecrf_responses (patient_id, protocol_id, section_id, session_number, answers_data, is_posted, is_reviewed, is_revised, is_approved, reviewer_note, created_by, updated_by, created_at, updated_at)
                    VALUES (:patient_id, :protocol_id, :section_id, :session_number, :answers_data::jsonb, :is_posted, :is_reviewed, :is_revised, :is_approved, :reviewer_note, :user_id, :user_id, NOW(), NOW())
                    RETURNING *
                ");
                $stmt->execute([
                    'patient_id'     => $patientId,
                    'protocol_id'    => $protocolId,
                    'section_id'     => $sectionId,
                    'session_number' => $sessionNumber,
                    'answers_data'   => $jsonAnswers,
                    'is_posted'      => $isPosted ? 'true' : 'false',
                    'is_reviewed'    => $isReviewed ? 'true' : 'false',
                    'is_revised'     => $isRevised ? 'true' : 'false',
                    'is_approved'    => $isApproved ? 'true' : 'false',
                    'reviewer_note'  => $reviewerNote,
                    'user_id'        => $userId,
                ]);
            }
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
            $status = $_GET['status'] ?? "all"; // all, submitted, review, revision, approved, rejected
            $pageNo = isset($_GET['page_no']) ? (int)$_GET['page_no'] : 1;
            $pageRow = isset($_GET['page_row']) ? (int)$_GET['page_row'] : 10;
            $startDate = $_GET['start_date'] ?? "";
            $endDate = $_GET['end_date'] ?? "";

            $where = "WHERE per.is_posted = TRUE OR (per.is_posted = FALSE AND per.reviewer_note IS NOT NULL AND per.reviewer_note != '')";
            $params = [];

            // Apply date filters
            if ($startDate !== "") {
                $where .= " AND per.updated_at >= :start_date";
                $params['start_date'] = $startDate . ' 00:00:00';
            }
            if ($endDate !== "") {
                $where .= " AND per.updated_at <= :end_date";
                $params['end_date'] = $endDate . ' 23:59:59';
            }

            // Apply search term filter
            if ($searchTerm !== "") {
                $where .= " AND (ap.protocol_name ILIKE :search OR pe.patient_initial ILIKE :search OR pe.registration_number ILIKE :search)";
                $params['search'] = '%' . $searchTerm . '%';
            }

            // Apply status filter matching flags pattern
            if ($status !== "all") {
                if ($status === "submitted") {
                    $where .= " AND per.is_posted = TRUE AND per.is_reviewed = FALSE";
                } else if ($status === "review") {
                    $where .= " AND per.is_posted = TRUE AND per.is_reviewed = TRUE AND per.is_revised = FALSE AND per.is_approved = FALSE";
                } else if ($status === "revision") {
                    $where .= " AND per.is_revised = TRUE";
                } else if ($status === "approved") {
                    $where .= " AND per.is_approved = TRUE";
                }
            }

            // Count query
            $countStmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM patient_ecrf_responses per
                JOIN patient_ecrfs pe ON per.patient_id = pe.id
                JOIN affiliator_protocols ap ON per.protocol_id = ap.id
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
                    per.session_number,
                    per.is_posted,
                    per.is_approved,
                    per.is_reviewed,
                    per.is_revised,
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
                JOIN affiliator_protocols ap ON per.protocol_id = ap.id
                JOIN ecrf_sections es ON per.section_id = es.id
                LEFT JOIN affiliator_protocol_ecrfs ape ON ap.id = ape.protocol_id AND per.section_id = ape.section_id
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
            $decision = trim($data['decision'] ?? ''); // approve, revision
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

            $userId = $user['data']['id'];

            $isApproved = ($decision === 'approve');
            $isPosted = true; // Always remains posted/submitted in both Approve and Revision states
            $isReviewed = true;
            $isRevised = ($decision === 'revision');

            $updateStmt = $pdo->prepare("
                UPDATE patient_ecrf_responses
                SET is_approved = :is_approved,
                    is_posted = :is_posted,
                    is_reviewed = :is_reviewed,
                    is_revised = :is_revised,
                    reviewer_note = :reviewer_note,
                    approved_by = CASE WHEN :is_approved = TRUE THEN :user_id ELSE approved_by END,
                    approved_at = CASE WHEN :is_approved = TRUE THEN NOW() ELSE approved_at END,
                    updated_by = :user_id,
                    updated_at = NOW()
                WHERE id = :id
                RETURNING *
            ");

            $updateStmt->execute([
                'is_approved'   => $isApproved ? 'true' : 'false',
                'is_posted'     => $isPosted ? 'true' : 'false',
                'is_reviewed'   => $isReviewed ? 'true' : 'false',
                'is_revised'    => $isRevised ? 'true' : 'false',
                'reviewer_note' => $reviewerNote === '' ? null : $reviewerNote,
                'user_id'       => $userId,
                'id'            => $id,
            ]);
            $result = $updateStmt->fetch();

            (new ApiResponse(true, 'Review decision saved successfully', $result))->send(200);
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }
}
