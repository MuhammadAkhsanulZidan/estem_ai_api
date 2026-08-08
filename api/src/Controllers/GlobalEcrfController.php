<?php

namespace App\Controllers;

use App\Config\Database;
use App\Middleware\AuthMiddleware;
use App\Models\ApiResponse;
use App\Helpers\RequestHelper;
use PDO;

class GlobalEcrfController
{
    /**
     * Retrieve global E-CRF templates.
     */
    public function get()
    {
        AuthMiddleware::authorize(['admin', 'affiliator']);

        try {
            $pdo = Database::getConnection();
            $sectionId = $_GET['section_id'] ?? null;

            if ($sectionId !== null) {
                $stmt = $pdo->prepare("SELECT * FROM ecrf_templates WHERE section_id = :section_id");
                $stmt->execute(['section_id' => (int)$sectionId]);
                $ecrf = $stmt->fetch();
                $data = $ecrf ? json_decode($ecrf['questions_schema'], true) : [];
                (new ApiResponse(true, 'E-CRF template retrieved successfully', $data))->send(200);
            } else {
                $stmt = $pdo->query("
                    SELECT 
                        es.id AS section_id,
                        es.section_name,
                        get.questions_schema
                    FROM ecrf_sections es
                    LEFT JOIN ecrf_templates get ON es.id = get.section_id
                    ORDER BY es.id ASC
                ");
                $rows = $stmt->fetchAll();

                $rawSections = [
                    'persiapan' => [],
                    'pelaksanaan' => [],
                    'monitoring' => [],
                    'evaluasi' => [],
                ];

                $sectionMap = [
                    1 => 'persiapan',
                    2 => 'pelaksanaan',
                    3 => 'monitoring',
                    4 => 'evaluasi'
                ];

                foreach ($rows as $row) {
                    $secId = (int)$row['section_id'];
                    $secKey = $sectionMap[$secId] ?? ('section_' . $secId);
                    $rawSections[$secKey] = json_decode($row['questions_schema'] ?? '[]', true) ?: [];
                }

                $sections = [
                    'persiapan' => [
                        'questions' => $rawSections['persiapan'],
                        'is_locked' => false
                    ],
                    'pelaksanaan' => [
                        'questions' => $rawSections['pelaksanaan'],
                        'is_locked' => false
                    ],
                    'monitoring' => [
                        'questions' => $rawSections['monitoring'],
                        'is_locked' => false
                    ],
                    'evaluasi' => [
                        'questions' => $rawSections['evaluasi'],
                        'is_locked' => false
                    ]
                ];

                (new ApiResponse(true, 'Global E-CRF templates retrieved successfully', $sections))->send(200);
            }
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }

    /**
     * Save/Update E-CRF template for global template.
     */
    public function post()
    {
        $user = AuthMiddleware::authorize(['admin']);

        try {
            $pdo = Database::getConnection();
            $data = RequestHelper::getBody();

            $sectionId = $data['section_id'] ?? null;
            $questionsSchema = $data['questions_schema'] ?? [];

            if ($sectionId === null) {
                (new ApiResponse(false, 'Section ID is required'))->send(400);
            }

            if (!in_array((int)$sectionId, [1, 2, 3, 4])) {
                (new ApiResponse(false, 'Invalid Section ID. Must be 1, 2, 3, or 4'))->send(400);
            }

            // Backend UUID assignment
            if (is_array($questionsSchema)) {
                foreach ($questionsSchema as &$q) {
                    $qId = $q['id'] ?? null;
                    if (!is_string($qId) || strlen($qId) !== 36 || substr_count($qId, '-') !== 4) {
                        $q['id'] = sprintf(
                            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                            mt_rand(0, 0xffff),
                            mt_rand(0, 0x0fff) | 0x4000,
                            mt_rand(0, 0x3fff) | 0x8000,
                            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
                        );
                    }
                    // Ensure is_global is true
                    $q['is_global'] = true;
                }
                unset($q);
            }

            $jsonSchema = json_encode($questionsSchema);
            $userId = $user['data']['id'];

            // PostgreSQL UPSERT (INSERT ... ON CONFLICT DO UPDATE)
            $stmt = $pdo->prepare("
                INSERT INTO ecrf_templates (section_id, questions_schema, created_by, updated_by, created_at, updated_at)
                VALUES (:section_id, :questions_schema::jsonb, :user_id, :user_id, NOW(), NOW())
                ON CONFLICT (section_id) 
                DO UPDATE SET 
                	questions_schema = EXCLUDED.questions_schema,
                	updated_by = EXCLUDED.updated_by,
                	updated_at = NOW()
                RETURNING *
            ");

            $stmt->bindValue(':section_id', $sectionId, PDO::PARAM_INT);
            $stmt->bindValue(':questions_schema', $jsonSchema, PDO::PARAM_STR);
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);

            $stmt->execute();
            $result = $stmt->fetch();

            (new ApiResponse(true, 'E-CRF saved successfully', $result))->send(200);
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }
}
