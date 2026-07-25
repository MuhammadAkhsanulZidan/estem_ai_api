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

            // 1. Fetch eCRF template sections
            $stmt = $pdo->prepare("
                SELECT 
                    es.id AS section_id,
                    es.section_name,
                    ape.questions_schema
                FROM ecrf_sections es
                LEFT JOIN admin_protocol_ecrfs ape 
                    ON es.id = ape.section_id AND ape.protocol_id = :protocol_id
                ORDER BY es.id ASC
            ");
            $stmt->execute(['protocol_id' => $protocolId]);
            $templateRows = $stmt->fetchAll();

            // 2. Fetch patient responses
            $resStmt = $pdo->prepare("
                SELECT section_id, answers_data, is_submitted 
                FROM patient_ecrf_responses 
                WHERE patient_id = :patient_id AND protocol_id = :protocol_id
            ");
            $resStmt->execute(['patient_id' => $patientId, 'protocol_id' => $protocolId]);
            $responseRows = $resStmt->fetchAll();

            $responses = [];
            foreach ($responseRows as $r) {
                $responses[(int)$r['section_id']] = [
                    'answers' => json_decode($r['answers_data'] ?? '{}', true) ?: new \stdClass(),
                    'is_submitted' => (bool)$r['is_submitted']
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

            // 3. Compute dynamic locking logic based on preceding submissions
            $s1Submitted = !empty($responses[1]['is_submitted']);
            $s2Submitted = $s1Submitted && !empty($responses[2]['is_submitted']);
            $s3Submitted = $s2Submitted && !empty($responses[3]['is_submitted']);

            $isL2Locked = !$s1Submitted;
            $isL3Locked = $isL2Locked || !$s2Submitted;
            $isL4Locked = $isL3Locked || !$s3Submitted;

            $sections = [
                'persiapan' => [
                    'section_id' => 1,
                    'questions' => $rawSections[1],
                    'answers' => $responses[1]['answers'] ?? new \stdClass(),
                    'is_submitted' => $responses[1]['is_submitted'] ?? false,
                    'is_locked' => false
                ],
                'pelaksanaan' => [
                    'section_id' => 2,
                    'questions' => $rawSections[2],
                    'answers' => $responses[2]['answers'] ?? new \stdClass(),
                    'is_submitted' => $responses[2]['is_submitted'] ?? false,
                    'is_locked' => $isL2Locked
                ],
                'monitoring' => [
                    'section_id' => 3,
                    'questions' => $rawSections[3],
                    'answers' => $responses[3]['answers'] ?? new \stdClass(),
                    'is_submitted' => $responses[3]['is_submitted'] ?? false,
                    'is_locked' => $isL3Locked
                ],
                'evaluasi' => [
                    'section_id' => 4,
                    'questions' => $rawSections[4],
                    'answers' => $responses[4]['answers'] ?? new \stdClass(),
                    'is_submitted' => $responses[4]['is_submitted'] ?? false,
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
            $isSubmitted = !empty($data['is_submitted']);

            if ($patientId === null || $protocolId === null || $sectionId === null) {
                (new ApiResponse(false, 'Patient ID, Protocol ID, and Section ID are required'))->send(400);
            }

            // 1. Perform template and lock checking before saving
            // (e.g. section 2 cannot be saved if section 1 is not submitted)
            if ((int)$sectionId > 1) {
                $prevSecId = (int)$sectionId - 1;
                $chk = $pdo->prepare("SELECT is_submitted FROM patient_ecrf_responses WHERE patient_id = :patient_id AND protocol_id = :protocol_id AND section_id = :section_id");
                $chk->execute([
                    'patient_id' => $patientId,
                    'protocol_id' => $protocolId,
                    'section_id' => $prevSecId
                ]);
                $prevRes = $chk->fetch();
                if (!$prevRes || !$prevRes['is_submitted']) {
                    (new ApiResponse(false, 'Cannot save answers because previous section is locked or not submitted.'))->send(403);
                }
            }

            // 2. Perform validations if is_submitted is true
            if ($isSubmitted) {
                $tStmt = $pdo->prepare("SELECT questions_schema FROM admin_protocol_ecrfs WHERE protocol_id = :protocol_id AND section_id = :section_id");
                $tStmt->execute(['protocol_id' => $protocolId, 'section_id' => $sectionId]);
                $tRow = $tStmt->fetch();
                $questions = $tRow ? json_decode($tRow['questions_schema'] ?? '[]', true) : [];

                foreach ($questions as $q) {
                    if (!empty($q['required'])) {
                        $qId = $q['id'];
                        $ansVal = $answersData[$qId] ?? null;
                        
                        // Check empty/blank answers
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
                INSERT INTO patient_ecrf_responses (patient_id, protocol_id, section_id, answers_data, is_submitted, created_by, updated_by, created_at, updated_at)
                VALUES (:patient_id, :protocol_id, :section_id, :answers_data::jsonb, :is_submitted, :user_id, :user_id, NOW(), NOW())
                ON CONFLICT (patient_id, protocol_id, section_id)
                DO UPDATE SET
                    answers_data = EXCLUDED.answers_data,
                    is_submitted = EXCLUDED.is_submitted,
                    updated_by = EXCLUDED.updated_by,
                    updated_at = NOW()
                RETURNING *
            ");

            $stmt->bindValue(':patient_id', $patientId, PDO::PARAM_INT);
            $stmt->bindValue(':protocol_id', $protocolId, PDO::PARAM_INT);
            $stmt->bindValue(':section_id', $sectionId, PDO::PARAM_INT);
            $stmt->bindValue(':answers_data', $jsonAnswers, PDO::PARAM_STR);
            $stmt->bindValue(':is_submitted', $isSubmitted, PDO::PARAM_BOOL);
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);

            $stmt->execute();
            $result = $stmt->fetch();

            (new ApiResponse(true, 'Answers saved successfully', $result))->send(200);
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }
}
