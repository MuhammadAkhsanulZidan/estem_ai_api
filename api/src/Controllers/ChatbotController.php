<?php

namespace App\Controllers;

use App\Config\Database;
use App\Models\ApiResponse;
use App\Middleware\AuthMiddleware;
use App\Helpers\RequestHelper;
use PDO;

class ChatbotController
{
    private string $pythonPath = __DIR__ . '/../../nlp/venv/bin/python3';
    private string $nlpDir = __DIR__ . '/../../nlp';

    /**
     * POST /v1/chat
     * Handles the chatbot query, intent classification, and database/FTS routing.
     */
    public function chat()
    {
        try {
            // 1. Authorize User
            $userData = AuthMiddleware::authorize();
            $userRole = $userData['data']['role_name'] ?? '';
            $userId = $userData['data']['id'] ?? null;
            $affiliatorId = $userData['data']['affiliator_id'] ?? null;

            // 2. Parse Input Query
            $input = json_decode(file_get_contents('php://input'), true);
            $queryText = $input['query'] ?? '';

            if (empty(trim($queryText))) {
                (new ApiResponse(false, 'Query cannot be empty'))->send(400);
                return;
            }

            // 3. Classify Intent via Python Script
            $escapedQuery = escapeshellarg($queryText);
            $predictScript = $this->nlpDir . '/predict_intent.py';
            $predictOutput = shell_exec("{$this->pythonPath} {$predictScript} --query {$escapedQuery}");
            $predictResult = json_decode($predictOutput, true);

            $intent = $predictResult['intent'] ?? 'general_search';
            $confidence = $predictResult['confidence'] ?? 0.0;

            // If confidence is low, fall back to general document search
            if ($confidence < 0.3) {
                $intent = 'general_search';
            }

            $responseMessage = '';
            $responseData = [];

            // 4. Route based on Intent
            switch ($intent) {
                case 'definition':
                    list($responseMessage, $responseData) = $this->handleGeneralSearch($queryText, 'definition');
                    break;
                case 'procedure':
                    list($responseMessage, $responseData) = $this->handleGeneralSearch($queryText, 'procedure');
                    break;
                case 'safety_monitoring':
                    list($responseMessage, $responseData) = $this->handleGeneralSearch($queryText, 'safety_monitoring');
                    break;
                case 'general_search':
                default:
                    list($responseMessage, $responseData) = $this->handleGeneralSearch($queryText);
                    break;
            }

            (new ApiResponse(true, 'Chatbot response generated', [
                'intent' => $intent,
                'confidence' => $confidence,
                'message' => $responseMessage,
                'data' => $responseData
            ]))->send(200);

        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Chatbot error: ' . $e->getMessage()))->send(500);
        }
    }

    /**
     * POST /v1/chat/feedback
     * Collects user thumbs up feedback to grow training datasets.
     */
    public function feedback()
    {
        try {
            AuthMiddleware::authorize();
            $pdo = Database::getConnection();
            $input = json_decode(file_get_contents('php://input'), true);

            $query = trim($input['query'] ?? '');
            $intent = trim($input['intent'] ?? '');

            if (empty($query) || empty($intent)) {
                (new ApiResponse(false, 'Query and Intent are required for feedback'))->send(400);
                return;
            }

            // Verify if intent exists in chatbot_intents to avoid foreign key violation
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM chatbot_intents WHERE name = :name");
            $stmt->execute(['name' => $intent]);
            if ((int)$stmt->fetchColumn() === 0) {
                (new ApiResponse(false, 'Invalid intent name'))->send(400);
                return;
            }

            // Insert phrase into chatbot_training_data
            $insStmt = $pdo->prepare("
                INSERT INTO chatbot_training_data (phrase, intent)
                VALUES (:phrase, :intent)
                ON CONFLICT (phrase) DO NOTHING
            ");
            $insStmt->execute([
                'phrase' => strtolower($query),
                'intent' => $intent
            ]);

            (new ApiResponse(true, 'Feedback recorded successfully'))->send(200);

        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Feedback error: ' . $e->getMessage()))->send(500);
        }
    }

    /**
     * Handler: general_search (Document Search via Sastrawi & PostgreSQL FTS)
     */
    private function handleGeneralSearch(string $queryText, ?string $intent = null): array
    {
        $escapedQuery = escapeshellarg($queryText);
        $queryScript = $this->nlpDir . '/process_query.py';
        $cmd = "{$this->pythonPath} {$queryScript} --query {$escapedQuery}";
        if ($intent !== null) {
            $cmd .= " --intent " . escapeshellarg($intent);
        }
        $queryOutput = shell_exec($cmd);
        $queryResult = json_decode($queryOutput, true);

        if (!isset($queryResult['success']) || !$queryResult['success']) {
            $errorMsg = $queryResult['error'] ?? 'Unknown script error';
            return ["Maaf, terjadi kesalahan saat melakukan pencarian dokumen: $errorMsg", []];
        }

        $matches = $queryResult['matches'] ?? [];

        // Filter matches below similarity threshold (0.3)
        $filteredMatches = array_filter($matches, function($match) {
            return ($match['score'] ?? 0.0) >= 0.3;
        });

        if (empty($filteredMatches)) {
            return ["Saya tidak dapat menemukan informasi yang cukup relevan terkait \"" . htmlspecialchars($queryText) . "\" di dokumen panduan stem cell yang tersedia.", []];
        }

        $message = "Berdasarkan dokumen medis stem cell yang saya cari, berikut informasi yang relevan:\n\n";
        foreach ($filteredMatches as $match) {
            $docName = $match['file_name'];
            $page = $match['page_number'];
            $excerpt = trim($match['content']);
            $score = round($match['score'] * 100, 1);

            // Generate link to specific PDF page served via Nginx alias
            $pdfLink = "/assets/" . urlencode($docName) . "#page=" . $page;

            $message .= "> ... " . $excerpt . " ...\n\n";
            $message .= "*(Sumber: [" . $docName . "](" . $pdfLink . "), Halaman " . $page . " - Kecocokan: " . $score . "% )*\n";
            $message .= "---\n\n";
        }

        return [$message, $filteredMatches];
    }
}
