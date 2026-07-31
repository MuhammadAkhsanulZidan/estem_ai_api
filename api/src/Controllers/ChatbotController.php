<?php

namespace App\Controllers;

use App\Config\Database;
use App\Models\ApiResponse;
use App\Middleware\AuthMiddleware;
use App\Helpers\RequestHelper;

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
                case 'cek_status_protokol':
                    list($responseMessage, $responseData) = $this->handleStatusProtokol($userRole, $affiliatorId);
                    break;
                case 'cek_efek_samping':
                    list($responseMessage, $responseData) = $this->handleEfekSamping($userRole, $affiliatorId);
                    break;
                case 'cek_reviewer':
                    list($responseMessage, $responseData) = $this->handleReviewerAssign($userRole, $affiliatorId);
                    break;
                case 'cek_jumlah_pasien':
                    list($responseMessage, $responseData) = $this->handleJumlahPasien($userRole, $affiliatorId);
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
     * Handler: cek_status_protokol
     */
    private function handleStatusProtokol(?string $role, ?int $affiliatorId): array
    {
        $sql = "
            SELECT p.id, p.protocol_number, p.protocol_title, p.status, p.created_at, a.affiliator_name
            FROM affiliator_protocols p
            LEFT JOIN affiliators a ON p.affiliator_id = a.id
        ";
        
        $params = [];
        if ($role === 'Affiliator' && $affiliatorId !== null) {
            $sql .= " WHERE p.affiliator_id = :affiliator_id";
            $params[':affiliator_id'] = $affiliatorId;
        }
        
        $sql .= " ORDER BY p.updated_at DESC LIMIT 5";
        $protocols = Database::fetchAll($sql, $params);
        
        if (empty($protocols)) {
            return ["Maaf, saya tidak menemukan data pengajuan protokol stem cell saat ini.", []];
        }

        $message = "Berikut adalah status pengajuan protokol stem cell terbaru:\n";
        foreach ($protocols as $p) {
            $num = $p['protocol_number'] ?? 'N/A';
            $title = $p['protocol_title'];
            $status = $p['status'] ?? 'Draft';
            $hospital = $p['affiliator_name'] ?? 'N/A';
            $message .= "- *[$num]* $title ($hospital) - Status: **$status**\n";
        }
        
        return [$message, $protocols];
    }

    /**
     * Handler: cek_efek_samping
     */
    private function handleEfekSamping(?string $role, ?int $affiliatorId): array
    {
        $sql = "
            SELECT ae.id, ae.patient_name, ae.event_description, ae.onset_date, ae.severity, a.affiliator_name
            FROM adverse_events ae
            LEFT JOIN affiliators a ON ae.affiliator_id = a.id
        ";
        
        $params = [];
        if ($role === 'Affiliator' && $affiliatorId !== null) {
            $sql .= " WHERE ae.affiliator_id = :affiliator_id";
            $params[':affiliator_id'] = $affiliatorId;
        }
        
        $sql .= " ORDER BY ae.created_at DESC LIMIT 5";
        $events = Database::fetchAll($sql, $params);
        
        if (empty($events)) {
            return ["Bagus! Tidak ada laporan efek samping (adverse events) terbaru yang tercatat.", []];
        }

        $message = "Berikut adalah daftar laporan kejadian efek samping (adverse events) terbaru:\n";
        foreach ($events as $e) {
            $name = $e['patient_name'];
            $desc = $e['event_description'];
            $severity = $e['severity'] ?? 'N/A';
            $hospital = $e['affiliator_name'] ?? 'N/A';
            $message .= "- Pasien **$name** di $hospital mengalami: \"$desc\" (Tingkat Keparahan: **$severity**)\n";
        }
        
        return [$message, $events];
    }

    /**
     * Handler: cek_reviewer
     */
    private function handleReviewerAssign(?string $role, ?int $affiliatorId): array
    {
        $sql = "
            SELECT p.protocol_number, p.protocol_title, u.username as reviewer_name, a.affiliator_name
            FROM affiliator_protocols p
            JOIN users u ON p.reviewer_id = u.id
            LEFT JOIN affiliators a ON p.affiliator_id = a.id
        ";
        
        $params = [];
        if ($role === 'Affiliator' && $affiliatorId !== null) {
            $sql .= " WHERE p.affiliator_id = :affiliator_id";
            $params[':affiliator_id'] = $affiliatorId;
        }
        
        $sql .= " LIMIT 5";
        $assignments = Database::fetchAll($sql, $params);
        
        if (empty($assignments)) {
            return ["Belum ada penetapan reviewer untuk protokol aktif saat ini.", []];
        }

        $message = "Berikut adalah daftar penetapan reviewer untuk protokol pengajuan:\n";
        foreach ($assignments as $a) {
            $num = $a['protocol_number'] ?? 'N/A';
            $title = $a['protocol_title'];
            $rev = $a['reviewer_name'];
            $message .= "- Protokol **[$num] $title** ditinjau oleh reviewer: **$rev**\n";
        }
        
        return [$message, $assignments];
    }

    /**
     * Handler: cek_jumlah_pasien
     */
    private function handleJumlahPasien(?string $role, ?int $affiliatorId): array
    {
        $sql = "SELECT COUNT(*) FROM patients";
        $params = [];
        
        if ($role === 'Affiliator' && $affiliatorId !== null) {
            $sql .= " WHERE affiliator_id = :affiliator_id";
            $params[':affiliator_id'] = $affiliatorId;
        }
        
        $count = Database::fetchColumn($sql, $params);
        $message = "Total pasien stem cell yang terdaftar di sistem saat ini adalah **$count** pasien.";
        return [$message, ['count' => $count]];
    }

    /**
     * Handler: general_search (Document Search via Sastrawi & PostgreSQL FTS)
     */
    private function handleGeneralSearch(string $queryText): array
    {
        $escapedQuery = escapeshellarg($queryText);
        $queryScript = $this->nlpDir . '/process_query.py';
        $queryOutput = shell_exec("{$this->pythonPath} {$queryScript} --query {$escapedQuery}");
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
