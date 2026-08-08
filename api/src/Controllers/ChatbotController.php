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
            $fileName = $input['file_name'] ?? null; // Specific document filter if selected

            if (trim($queryText) === '') {
                (new ApiResponse(false, 'Query cannot be empty'))->send(400);
                return;
            }

            $queryTextLower = strtolower(trim($queryText));

            // Check for Menu Choice / Navigation Commands (WhatsApp style)
            if ($queryTextLower === 'menu' || $queryTextLower === '0' || $queryTextLower === 'batal' || $queryTextLower === 'kembali') {
                $responseMessage = "🤖 **Menu Utama eSTEM AI Assistant**\n\nSilakan pilih menu dengan membalas angka:\n\n" .
                                   "**[1]** Tanya Medis & SOP (Definisi, Prosedur, Keamanan)\n" .
                                   "**[2]** Cek Status Pengajuan Protokol\n" .
                                   "**[3]** Info Reviewer Protokol\n" .
                                   "**[4]** Cek Laporan Efek Samping Pasien\n" .
                                   "**[5]** Cari di Dokumen Spesifik\n\n" .
                                   "Atau ketik langsung pertanyaan Anda secara manual kapan saja!";
                (new ApiResponse(true, 'Menu rendered', [
                    'intent' => 'menu',
                    'confidence' => 1.0,
                    'message' => $responseMessage,
                    'data' => ['menu' => 'main']
                ]))->send(200);
                return;
            }

            if ($queryTextLower === '1') {
                $responseMessage = "📁 **Tanya Medis & SOP**\n\nSilakan pilih kategori informasi yang Anda cari:\n\n" .
                                   "**[1.1]** Pengertian & Definisi (e.g. *Apa itu UC-MSC*)\n" .
                                   "**[1.2]** Prosedur Tindakan (e.g. *Bagaimana langkah microneedling*)\n" .
                                   "**[1.3]** Keamanan & Observasi (e.g. *Berapa lama observasi alergi*)\n\n" .
                                   "**[0]** Kembali ke Menu Utama\n\n" .
                                   "Atau langsung ketik pertanyaan Anda secara manual!";
                (new ApiResponse(true, 'Menu rendered', [
                    'intent' => 'menu',
                    'confidence' => 1.0,
                    'message' => $responseMessage,
                    'data' => ['menu' => 'tanya_medis']
                ]))->send(200);
                return;
            }

            if ($queryTextLower === '2') {
                list($responseMessage, $responseData) = $this->handleProtokolDetail($userRole, $affiliatorId, $queryText);
                (new ApiResponse(true, 'Menu query executed', [
                    'intent' => 'cek_protokol_detail',
                    'confidence' => 1.0,
                    'message' => $responseMessage,
                    'data' => $responseData
                ]))->send(200);
                return;
            }

            if ($queryTextLower === '3') {
                list($responseMessage, $responseData) = $this->handleRegistrasiPengampuanDetail($userRole, $affiliatorId);
                (new ApiResponse(true, 'Menu query executed', [
                    'intent' => 'cek_registrasi_pengampuan_detail',
                    'confidence' => 1.0,
                    'message' => $responseMessage,
                    'data' => $responseData
                ]))->send(200);
                return;
            }

            if ($queryTextLower === '4') {
                list($responseMessage, $responseData) = $this->handleEfekSampingDetail($userRole, $affiliatorId, $queryText);
                (new ApiResponse(true, 'Menu query executed', [
                    'intent' => 'cek_efek_samping_detail',
                    'confidence' => 1.0,
                    'message' => $responseMessage,
                    'data' => $responseData
                ]))->send(200);
                return;
            }

            if ($queryTextLower === '5') {
                $docs = Database::fetchAll("SELECT file_name FROM chatbot_documents ORDER BY file_name ASC");
                $responseMessage = "📚 **Pilih Dokumen Spesifik**\n\nSilakan ketik pertanyaan Anda secara manual, atau pilih menu dokumen dari sidebar kanan di layar Anda untuk menyaring hasil pencarian.\n\nBerikut daftar dokumen tersedia di pustaka kami:\n\n";
                foreach ($docs as $idx => $doc) {
                    $num = $idx + 1;
                    $responseMessage .= "**[$num]** `{$doc['file_name']}`\n";
                }
                $responseMessage .= "\n**[0]** Kembali ke Menu Utama";
                (new ApiResponse(true, 'Menu rendered', [
                    'intent' => 'menu',
                    'confidence' => 1.0,
                    'message' => $responseMessage,
                    'data' => ['menu' => 'pilih_dokumen', 'documents' => $docs]
                ]))->send(200);
                return;
            }

            if ($queryTextLower === '1.1') {
                $responseMessage = "📖 **Pengertian & Definisi**\n\nSilakan ketik definisi medis yang ingin Anda tanyakan (contoh: *'Apa itu Secretome UC-MSC?'*).";
                (new ApiResponse(true, 'Menu guide', [
                    'intent' => 'menu_guide',
                    'confidence' => 1.0,
                    'message' => $responseMessage,
                    'data' => []
                ]))->send(200);
                return;
            }

            if ($queryTextLower === '1.2') {
                $responseMessage = "⚙️ **Prosedur Tindakan**\n\nSilakan ketik tindakan medis yang ingin Anda tanyakan (contoh: *'Bagaimana cara microneedling?'*).";
                (new ApiResponse(true, 'Menu guide', [
                    'intent' => 'menu_guide',
                    'confidence' => 1.0,
                    'message' => $responseMessage,
                    'data' => []
                ]))->send(200);
                return;
            }

            if ($queryTextLower === '1.3') {
                $responseMessage = "⚠️ **Keamanan & Observasi**\n\nSilakan ketik pertanyaan seputar keselamatan atau monitoring (contoh: *'Apa efek samping dari secretome?'*).";
                (new ApiResponse(true, 'Menu guide', [
                    'intent' => 'menu_guide',
                    'confidence' => 1.0,
                    'message' => $responseMessage,
                    'data' => []
                ]))->send(200);
                return;
            }

            // 3. Classify Intent via Python Script for direct manual typing
            $escapedQuery = escapeshellarg($queryText);
            $predictScript = $this->nlpDir . '/predict_intent.py';
            $predictOutput = shell_exec("{$this->pythonPath} {$predictScript} --query {$escapedQuery}");
            $predictResult = json_decode($predictOutput, true);

            $intent = $predictResult['intent'] ?? 'general_search';
            $confidence = $predictResult['confidence'] ?? 0.0;

            if ($confidence < 0.3) {
                $intent = 'general_search';
            }

            $responseMessage = '';
            $responseData = [];

            // 4. Route manual chat based on Intent
            switch ($intent) {
                case 'cek_protokol_detail':
                    list($responseMessage, $responseData) = $this->handleProtokolDetail($userRole, $affiliatorId, $queryText);
                    break;
                case 'cek_registrasi_pengampuan_detail':
                    list($responseMessage, $responseData) = $this->handleRegistrasiPengampuanDetail($userRole, $affiliatorId);
                    break;
                case 'cek_pasien_detail':
                    list($responseMessage, $responseData) = $this->handlePasienDetail($userRole, $affiliatorId, $queryText);
                    break;
                case 'cek_efek_samping_detail':
                    list($responseMessage, $responseData) = $this->handleEfekSampingDetail($userRole, $affiliatorId, $queryText);
                    break;
                case 'cek_kriteria_pasien':
                    list($responseMessage, $responseData) = $this->handleKriteriaPasienDetail($userRole, $affiliatorId, $queryText);
                    break;
                case 'cek_ecrf_template':
                    list($responseMessage, $responseData) = $this->handleEcrfTemplateDetail($userRole, $affiliatorId, $queryText);
                    break;
                case 'definition':
                case 'procedure':
                case 'safety_monitoring':
                case 'general_search':
                default:
                    list($responseMessage, $responseData) = $this->handleGeneralSearch($queryText, $intent, $fileName);
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
     * Handler: cek_protokol_detail
     */
    private function handleProtokolDetail(?string $role, ?int $affiliatorId, string $queryText): array
    {
        // Try parsing protocol name keyword from queryText (e.g. "secretome" or "estetika")
        $search = null;
        $words = explode(' ', $queryText);
        $excludeWords = [
            'protokol', 'pengajuan', 'bagaimana', 'progres', 'status', 'detail',
            'saya', 'anda', 'kami', 'kita', 'untuk', 'dengan', 'dari', 'pada', 
            'yang', 'atau', 'adalah', 'yaitu', 'tentang', 'oleh'
        ];
        foreach ($words as $word) {
            $wordClean = preg_replace('/[^a-zA-Z]/', '', $word);
            if (strlen($wordClean) >= 4 && !in_array(strtolower($wordClean), $excludeWords)) {
                $search = $wordClean;
                break;
            }
        }

        $sql = "
            SELECT p.id, p.protocol_name, p.protocol_version, p.is_approved, p.is_revised, p.is_reviewed, p.is_posted, p.created_at, 
                   a.affiliator_name, u.username as reviewer_name
            FROM affiliator_protocols p
            LEFT JOIN affiliators a ON p.affiliator_id = a.id
            LEFT JOIN users u ON p.updated_by = u.id
        ";

        $params = [];
        $whereClauses = [];

        if ($role === 'Affiliator' && $affiliatorId !== null) {
            $whereClauses[] = "p.affiliator_id = :affiliator_id";
            $params[':affiliator_id'] = $affiliatorId;
        }

        if ($search !== null) {
            $whereClauses[] = "p.protocol_name ILIKE :search";
            $params[':search'] = '%' . $search . '%';
        }

        if (!empty($whereClauses)) {
            $sql .= " WHERE " . implode(" AND ", $whereClauses);
        }

        $sql .= " ORDER BY p.updated_at DESC";
        $protocols = Database::fetchAll($sql, $params);

        if (empty($protocols)) {
            if ($search !== null) {
                return ["Maaf, pengajuan protokol dengan nama **$search** tidak ditemukan.", []];
            }
            return ["Maaf, tidak ada data pengajuan protokol yang ditemukan di sistem saat ini.", []];
        }

        // Case A: User specifically requested a protocol, or they only have exactly 1 protocol
        if ($search !== null || count($protocols) === 1) {
            $p = $protocols[0];
            $title = $p['protocol_name'];
            $version = $p['protocol_version'];
            
            // Calculate status string
            $status = 'DRAFT';
            if ($p['is_approved']) {
                $status = 'APPROVED';
            } elseif ($p['is_revised']) {
                $status = 'REVISED';
            } elseif ($p['is_reviewed']) {
                $status = 'REVIEWED';
            } elseif ($p['is_posted']) {
                $status = 'POSTED';
            }

            $hospital = $p['affiliator_name'] ?? 'N/A';
            $reviewer = $p['reviewer_name'] ?? 'Belum Ditugaskan';
            $date = date('d-m-Y', strtotime($p['created_at']));

            $message = "Berikut adalah rincian pengajuan protokol Anda:\n\n";
            $message .= "📄 **Protokol: $title (Versi $version)**\n";
            $message .= "• Faskes: $hospital\n";
            $message .= "• Status: **$status**\n";
            $message .= "• Reviewer terakhir: $reviewer\n";
            $message .= "• Tanggal Pengajuan: $date\n\n";
            
            // Retrieve documents for this protocol
            $docStmt = Database::getConnection()->prepare("SELECT id, document_path FROM affiliator_protocol_documents WHERE protocol_id = :pid");
            $docStmt->execute(['pid' => $p['id']]);
            $docs = $docStmt->fetchAll();
            if (!empty($docs)) {
                $message .= "📎 **Dokumen Lampiran:**\n";
                foreach ($docs as $doc) {
                    $docName = basename($doc['document_path']);
                    $relPath = str_replace('public/bck/', '', $doc['document_path']);
                    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['SERVER_PORT'] ?? 80) == 443) ? "https://" : "http://";
                    $apiBase = $protocol . ($_SERVER['HTTP_HOST'] ?? 'api-estemai.zendekia.com');
                    $docUrl = $apiBase . "/v1/public/bck/" . implode('/', array_map('rawurlencode', explode('/', $relPath)));
                    $message .= "  - [" . $docName . "](" . $docUrl . ")\n";
                }
                $message .= "\n";
            }
            $message .= "---\n\n";
            $message .= "💡 *Butuh bantuan lain? Balas:* **[0]** Menu Utama";
            return [$message, $protocols];
        }

        // Case B: User has multiple protocols, list them
        $message = "Anda memiliki beberapa pengajuan protokol aktif. Silakan balas/ketik nama protokol secara spesifik (misal: 'Secretome') untuk melihat detailnya:\n\n";
        foreach ($protocols as $p) {
            $title = $p['protocol_name'];
            $version = $p['protocol_version'];
            $status = 'DRAFT';
            if ($p['is_approved']) {
                $status = 'APPROVED';
            } elseif ($p['is_revised']) {
                $status = 'REVISED';
            } elseif ($p['is_reviewed']) {
                $status = 'REVIEWED';
            } elseif ($p['is_posted']) {
                $status = 'POSTED';
            }
            $message .= "• **$title** (Versi $version) - Status: **$status**\n";
        }
        $message .= "\n💡 *Butuh bantuan lain? Balas:* **[0]** Menu Utama";
        return [$message, $protocols];
    }

    /**
     * Handler: cek_registrasi_pengampuan_detail
     */
    private function handleRegistrasiPengampuanDetail(?string $role, ?int $affiliatorId): array
    {
        $sql = "
            SELECT s.id, s.reference_id, s.pic_name, s.status, s.review_notes, s.approved_at, 
                   a.affiliator_name, u.username as approver_name
            FROM affiliator_supervisions s
            LEFT JOIN affiliators a ON s.affiliator_id = a.id
            LEFT JOIN users u ON s.approved_by = u.id
        ";

        $params = [];
        if ($role === 'Affiliator' && $affiliatorId !== null) {
            $sql .= " WHERE s.affiliator_id = :affiliator_id";
            $params[':affiliator_id'] = $affiliatorId;
        }

        $sql .= " ORDER BY s.updated_at DESC LIMIT 3";
        $supervisions = Database::fetchAll($sql, $params);

        if (empty($supervisions)) {
            return ["Maaf, tidak ada catatan registrasi pengampuan yang ditemukan untuk RS Anda saat ini.", []];
        }

        $message = "Berikut adalah rincian **Registrasi Pengampuan** terbaru:\n\n";
        foreach ($supervisions as $s) {
            $ref = $s['reference_id'] ?? 'N/A';
            $pic = $s['pic_name'] ?? 'N/A';
            $status = strtoupper($s['status'] ?? 'Draft');
            $notes = $s['review_notes'] ?? 'Tidak ada catatan perbaikan';
            $hospital = $s['affiliator_name'] ?? 'N/A';
            $approver = $s['approver_name'] ?? 'N/A';

            $message .= "🏥 **Pengampuan RS: $hospital**\n";
            $message .= "• Ref ID: $ref\n";
            $message .= "• PIC Pelaksana: $pic\n";
            $message .= "• Status: **$status**\n";
            $message .= "• Catatan Revisi/Review: *\"$notes\"*\n";
            if ($status === 'APPROVED' && $s['approved_at']) {
                $message .= "• Disetujui Oleh: $approver pada " . date('d-m-Y', strtotime($s['approved_at'])) . "\n";
            }
            $message .= "\n";
            
            // Retrieve supervision documents
            $docStmt = Database::getConnection()->prepare("SELECT id, document_path FROM affiliator_supervision_documents WHERE supervision_id = :sid");
            $docStmt->execute(['sid' => $s['id']]);
            $docs = $docStmt->fetchAll();
            if (!empty($docs)) {
                $message .= "📎 **Dokumen Visitasi/Audit:**\n";
                foreach ($docs as $doc) {
                    $docName = basename($doc['document_path']);
                    $relPath = str_replace('public/bck/', '', $doc['document_path']);
                    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['SERVER_PORT'] ?? 80) == 443) ? "https://" : "http://";
                    $apiBase = $protocol . ($_SERVER['HTTP_HOST'] ?? 'api-estemai.zendekia.com');
                    $docUrl = $apiBase . "/v1/public/bck/" . implode('/', array_map('rawurlencode', explode('/', $relPath)));
                    $message .= "  - [" . $docName . "](" . $docUrl . ")\n";
                }
                $message .= "\n";
            }
            $message .= "---\n\n";
        }

        $message .= "💡 *Butuh bantuan lain? Balas:* **[0]** Menu Utama";
        return [$message, $supervisions];
    }

    /**
     * Handler: cek_pasien_detail
     */
    private function handlePasienDetail(?string $role, ?int $affiliatorId, string $queryText): array
    {
        $search = null;
        if (preg_match('/pasien-\d+/i', $queryText, $matches)) {
            $search = $matches[0];
        } else {
            $words = explode(' ', $queryText);
            foreach ($words as $word) {
                $wordClean = preg_replace('/[^a-zA-Z0-9-]/', '', $word);
                if (strlen($wordClean) >= 2 && !in_array(strtolower($wordClean), ['pasien', 'detail', 'cek', 'rekam', 'medis'])) {
                    $search = $wordClean;
                    break;
                }
            }
        }

        $sql = "
            SELECT p.id, p.registration_number, p.patient_initial, p.gender, p.pic_doctor, p.registration_date,
                   a.affiliator_name
            FROM patient_ecrfs p
            LEFT JOIN affiliators a ON p.affiliator_id = a.id
        ";
        
        $params = [];
        $whereClauses = [];
        if ($role === 'Affiliator' && $affiliatorId !== null) {
            $whereClauses[] = "p.affiliator_id = :affiliator_id";
            $params[':affiliator_id'] = $affiliatorId;
        }

        if ($search !== null) {
            $whereClauses[] = "(p.registration_number ILIKE :search OR p.patient_initial ILIKE :search)";
            $params[':search'] = '%' . $search . '%';
        }

        if (!empty($whereClauses)) {
            $sql .= " WHERE " . implode(" AND ", $whereClauses);
        }

        $sql .= " ORDER BY p.registration_date DESC LIMIT 3";
        $patients = Database::fetchAll($sql, $params);

        if (empty($patients)) {
            return ["Maaf, tidak ada data pasien stem cell yang cocok dengan pencarian Anda saat ini.", []];
        }

        $message = "Berikut adalah rincian data pasien stem cell:\n\n";
        foreach ($patients as $pt) {
            $regNum = $pt['registration_number'];
            $initial = $pt['patient_initial'];
            $gender = $pt['gender'] === 'L' ? 'Laki-laki' : ($pt['gender'] === 'P' ? 'Perempuan' : 'N/A');
            $doctor = $pt['pic_doctor'] ?? 'N/A';
            $date = date('d-m-Y', strtotime($pt['registration_date']));
            $hospital = $pt['affiliator_name'] ?? 'N/A';

            $message .= "👤 **Pasien: $regNum ($pt[patient_initial])**\n";
            $message .= "• Inisial: $initial\n";
            $message .= "• Jenis Kelamin: $gender\n";
            $message .= "• Dokter Penanggungjawab: $doctor\n";
            $message .= "• RS Pelaksana: $hospital\n";
            $message .= "• Tanggal Registrasi: $date\n";

            $respStmt = Database::getConnection()->prepare("
                SELECT count(*) as count 
                FROM patient_ecrf_responses 
                WHERE patient_id = :pid
            ");
            $respStmt->execute(['pid' => $pt['id']]);
            $responsesCount = $respStmt->fetchColumn();
            $message .= "• Form eCRF Terisi: **$responsesCount** Bagian\n\n";
            $message .= "---\n\n";
        }

        $message .= "💡 *Butuh bantuan lain? Balas:* **[0]** Menu Utama";
        return [$message, $patients];
    }

    /**
     * Handler: cek_ecrf_template
     */
    private function handleEcrfTemplateDetail(?string $role, ?int $affiliatorId, string $queryText): array
    {
        $excludeWords = [
            'kriteria', 'inklusi', 'eksklusi', 'pasien', 'syarat', 'kelayakan',
            'apakah', 'bagaimana', 'status', 'pemeriksaan', 'detail', 'lihat',
            'tampilkan', 'saya', 'anda', 'untuk', 'dengan', 'dari', 'pada',
            'apa', 'saja', 'dan', 'atau', 'dalam', 'ini', 'itu', 'protokol',
            'wajib', 'diisi', 'pertanyaan', 'ecrf', 'variabel', 'data', 'yang', 'di'
        ];
        
        $words = explode(' ', $queryText);
        $protocolSearch = null;
        foreach ($words as $word) {
            $wordClean = preg_replace('/[^a-zA-Z]/', '', $word);
            if (strlen($wordClean) >= 4 && !in_array(strtolower($wordClean), $excludeWords)) {
                $protocolSearch = $wordClean;
                break;
            }
        }

        $sql = "
            SELECT ap.questions_schema, am.protocol_name, s.section_name
            FROM admin_protocol_ecrfs ap
            JOIN admin_protocols am ON ap.protocol_id = am.id
            JOIN ecrf_sections s ON ap.section_id = s.id
        ";
        
        $params = [];
        if ($protocolSearch !== null) {
            $sql .= " WHERE am.protocol_name ILIKE :search";
            $params[':search'] = '%' . $protocolSearch . '%';
        }
        $sql .= " ORDER BY am.id ASC, s.id ASC";
        $records = Database::fetchAll($sql, $params);

        if (empty($records)) {
            return ["Maaf, tidak ada konfigurasi template eCRF yang ditemukan untuk pencarian Anda.", []];
        }

        // Group by protocol name
        $grouped = [];
        foreach ($records as $r) {
            $grouped[$r['protocol_name']][] = [
                'section_name' => $r['section_name'],
                'schema' => json_decode($r['questions_schema'], true)
            ];
        }

        // If multiple protocols are found and they didn't specify one, ask them to clarify which eCRF
        if (count($grouped) > 1 && $protocolSearch === null) {
            $message = "Anda memiliki beberapa konfigurasi eCRF protokol aktif. Silakan ketik nama protokol secara spesifik (contoh: *'eCRF Secretome'*) untuk melihat daftar variabel pengisian eCRF-nya:\n\n";
            foreach (array_keys($grouped) as $pName) {
                $message .= "• **$pName**\n";
            }
            $message .= "\n💡 *Butuh bantuan lain? Balas:* **[0]** Menu Utama";
            return [$message, $records];
        }

        // Display details for the matched protocol
        $pName = array_keys($grouped)[0];
        $sections = $grouped[$pName];

        $message = "Berikut adalah daftar pertanyaan/variabel yang harus diisi dalam eCRF untuk **Protokol $pName**:\n\n";
        foreach ($sections as $sec) {
            $message .= "📁 **Section: " . $sec['section_name'] . "**\n";
            if (!empty($sec['schema'])) {
                foreach ($sec['schema'] as $q) {
                    $req = isset($q['required']) && $q['required'] ? ' **(Wajib)**' : '';
                    $optionsStr = '';
                    if (isset($q['options']) && is_array($q['options']) && !empty($q['options'])) {
                        $optionsStr = ' - *Pilihan: ' . implode(', ', $q['options']) . '*';
                    }
                    $message .= "  • " . $q['label'] . $req . $optionsStr . "\n";
                }
            } else {
                $message .= "  - (Belum dikonfigurasi)\n";
            }
            $message .= "\n";
        }

        $message .= "---\n\n";
        $message .= "💡 *Butuh bantuan lain? Balas:* **[0]** Menu Utama";
        return [$message, $records];
    }

    /**
     * Handler: cek_efek_samping_detail
     */
    private function handleEfekSampingDetail(?string $role, ?int $affiliatorId, string $queryText): array
    {
        $sql = "
            SELECT ae.id, ae.event_type, ae.severity, ae.report_date, ae.action_taken, 
                   pt.patient_initial, a.affiliator_name
            FROM adverse_events ae
            LEFT JOIN patient_ecrfs pt ON ae.patient_id = pt.id
            LEFT JOIN affiliators a ON ae.affiliator_id = a.id
        ";

        $params = [];
        $whereClauses = [];
        if ($role === 'Affiliator' && $affiliatorId !== null) {
            $whereClauses[] = "ae.affiliator_id = :affiliator_id";
            $params[':affiliator_id'] = $affiliatorId;
        }

        $words = explode(' ', $queryText);
        $searchName = null;
        foreach ($words as $word) {
            $wordClean = preg_replace('/[^a-zA-Z]/', '', $word);
            if (strlen($wordClean) >= 3 && !in_array(strtolower($wordClean), ['efek', 'samping', 'detail', 'laporan', 'kejadian'])) {
                $searchName = $wordClean;
                break;
            }
        }

        if ($searchName !== null) {
            $whereClauses[] = "pt.patient_initial ILIKE :search";
            $params[':search'] = '%' . $searchName . '%';
        }

        if (!empty($whereClauses)) {
            $sql .= " WHERE " . implode(" AND ", $whereClauses);
        }

        $sql .= " ORDER BY ae.created_at DESC LIMIT 3";
        $events = Database::fetchAll($sql, $params);

        if (empty($events)) {
            return ["Bagus! Tidak ada laporan kejadian efek samping (adverse events) terbaru yang tercatat untuk pencarian Anda.", []];
        }

        $message = "Berikut adalah rincian laporan kejadian efek samping (adverse events) terbaru:\n\n";
        foreach ($events as $e) {
            $initial = $e['patient_initial'] ?? 'N/A';
            $type = $e['event_type'];
            $severity = $e['severity'] === 1 ? 'Mild' : ($e['severity'] === 2 ? 'Moderate' : 'Severe');
            $date = date('d-m-Y', strtotime($e['report_date']));
            $hospital = $e['affiliator_name'] ?? 'N/A';
            $action = $e['action_taken'] ?? 'Tidak ada tindakan khusus';

            $message .= "⚠️ **Laporan KTD: Pasien $initial** ($hospital)\n";
            $message .= "• Gejala: *\"$type\"*\n";
            $message .= "• Tingkat Keparahan: **$severity**\n";
            $message .= "• Tanggal Laporan: $date\n";
            $message .= "• Tindakan Diambil: $action\n\n";
            $message .= "---\n\n";
        }

        $message .= "💡 *Butuh bantuan lain? Balas:* **[0]** Menu Utama";
        return [$message, $events];
    }

    /**
     * Handler: cek_kriteria_pasien
     */
    private function handleKriteriaPasienDetail(?string $role, ?int $affiliatorId, string $queryText): array
    {
        // Try to identify patient initial or search keyword from queryText
        $words = explode(' ', $queryText);
        $search = null;
        $excludeWords = [
            'kriteria', 'inklusi', 'eksklusi', 'pasien', 'syarat', 'kelayakan',
            'apakah', 'bagaimana', 'status', 'pemeriksaan', 'detail', 'lihat',
            'tampilkan', 'saya', 'anda', 'untuk', 'dengan', 'dari', 'pada',
            'apa', 'saja', 'dan', 'atau', 'dalam', 'ini', 'itu', 'protokol'
        ];
        foreach ($words as $word) {
            $wordClean = preg_replace('/[^a-zA-Z0-9]/', '', $word);
            if (strlen($wordClean) >= 1 && !in_array(strtolower($wordClean), $excludeWords)) {
                $search = $wordClean;
                break;
            }
        }

        // Case A: Look for a specific patient's criteria response
        if ($search !== null) {
            // Extract numeric registration number patterns (e.g. 002) if specified in queryText
            $searchReg = null;
            foreach ($words as $word) {
                $wordClean = preg_replace('/[^a-zA-Z0-9\/]/', '', $word);
                if (preg_match('/[0-9]/', $wordClean)) {
                    $searchReg = $wordClean;
                    break;
                }
            }

            $sql = "
                SELECT pe.id as patient_id, pe.patient_initial, pe.registration_number, 
                       ap.questions_schema, r.answers_data, a.affiliator_name
                FROM patient_ecrfs pe
                LEFT JOIN affiliators a ON pe.affiliator_id = a.id
                LEFT JOIN affiliator_protocols ap_inst ON pe.protocol_id = ap_inst.id
                LEFT JOIN affiliator_protocol_ecrfs ap ON ap_inst.id = ap.protocol_id AND ap.section_id = 1
                LEFT JOIN patient_ecrf_responses r ON pe.id = r.patient_id AND r.section_id = 1
                WHERE pe.patient_initial ILIKE :search
            ";
            $params = [':search' => '%' . $search . '%'];

            if ($searchReg !== null) {
                $sql .= " AND pe.registration_number ILIKE :reg";
                $params[':reg'] = '%' . $searchReg . '%';
            }

            if ($role === 'Affiliator' && $affiliatorId !== null) {
                $sql .= " AND pe.affiliator_id = :affiliator_id";
                $params[':affiliator_id'] = $affiliatorId;
            }
            $sql .= " LIMIT 1";

            $patientData = Database::fetch($sql, $params);

            if ($patientData) {
                $initial = $patientData['patient_initial'];
                $regNum = $patientData['registration_number'];
                $hospital = $patientData['affiliator_name'] ?? 'N/A';
                
                $answersJson = $patientData['answers_data'] ? json_decode($patientData['answers_data'], true) : [];
                // Fetch template questions for section 1 (Persiapan)
                $gStmt = $pdo->prepare("SELECT questions_schema FROM ecrf_templates WHERE section_id = 1");
                $gStmt->execute();
                $gRow = $gStmt->fetch();
                $schemaJson = $gRow ? json_decode($gRow['questions_schema'] ?? '[]', true) : [];

                $inclusionLabel = 'N/A';
                $exclusionLabel = 'N/A';
                $kelayakanLabel = 'Belum Dinilai';

                foreach ($schemaJson as $question) {
                    $qId = $question['id'];
                    $qLabel = strtolower($question['label']);
                    if (strpos($qLabel, 'inklusi') !== false && isset($answersJson[$qId])) {
                        $inclusionLabel = $answersJson[$qId];
                    }
                    if (strpos($qLabel, 'eksklusi') !== false && isset($answersJson[$qId])) {
                        $exclusionLabel = $answersJson[$qId];
                    }
                    if (strpos($qLabel, 'layak') !== false && isset($answersJson[$qId])) {
                        $kelayakanLabel = $answersJson[$qId];
                    }
                }

                $message = "Berikut adalah kriteria kelayakan untuk pasien **$initial** ($hospital):\n\n";
                $message .= "🆔 **No. Registrasi:** $regNum\n";
                $message .= "• Kriteria Inklusi yang dipenuhi: **$inclusionLabel**\n";
                $message .= "• Kriteria Eksklusi yang dialami: **$exclusionLabel**\n";
                $message .= "• Rekomendasi Kelayakan Terapi: **$kelayakanLabel**\n\n";
                $message .= "---\n\n";
                $message .= "💡 *Butuh bantuan lain? Balas:* **[0]** Menu Utama";

                return [$message, [$patientData]];
            }
        }

        // Case B: General template query for a protocol if they typed protocol name
        $protocolSearch = null;
        foreach ($words as $word) {
            $wordClean = preg_replace('/[^a-zA-Z]/', '', $word);
            if (strlen($wordClean) >= 4 && !in_array(strtolower($wordClean), $excludeWords)) {
                $protocolSearch = $wordClean;
                break;
            }
        }

        if ($protocolSearch !== null) {
            $sql = "
                SELECT ap.questions_schema, am.protocol_name
                FROM affiliator_protocol_ecrfs ap
                JOIN affiliator_protocols am ON ap.protocol_id = am.id
                WHERE ap.section_id = 1 AND am.protocol_name ILIKE :search
                LIMIT 1
            ";
            $protoData = Database::fetch($sql, [':search' => '%' . $protocolSearch . '%']);

            if ($protoData) {
                $name = $protoData['protocol_name'];
                // Fetch template questions for section 1 (Persiapan)
                $gStmt = $pdo->prepare("SELECT questions_schema FROM ecrf_templates WHERE section_id = 1");
                $gStmt->execute();
                $gRow = $gStmt->fetch();
                $schemaJson = $gRow ? json_decode($gRow['questions_schema'] ?? '[]', true) : [];

                $inclusionOpts = [];
                $exclusionOpts = [];

                foreach ($schemaJson as $question) {
                    $qLabel = strtolower($question['label']);
                    if (strpos($qLabel, 'inklusi') !== false && isset($question['options'])) {
                        $inclusionOpts = $question['options'];
                    }
                    if (strpos($qLabel, 'eksklusi') !== false && isset($question['options'])) {
                        $exclusionOpts = $question['options'];
                    }
                }

                $message = "Berikut adalah kriteria inklusi & eksklusi standar untuk **Protokol $name**:\n\n";
                
                $message .= "✅ **Kriteria Inklusi:**\n";
                if (!empty($inclusionOpts)) {
                    foreach ($inclusionOpts as $opt) {
                        $message .= "  - $opt\n";
                    }
                } else {
                    $message .= "  - (Tidak dikonfigurasi)\n";
                }
                
                $message .= "\n❌ **Kriteria Eksklusi:**\n";
                if (!empty($exclusionOpts)) {
                    foreach ($exclusionOpts as $opt) {
                        $message .= "  - $opt\n";
                    }
                } else {
                    $message .= "  - (Tidak dikonfigurasi)\n";
                }

                $message .= "\n---\n\n";
                $message .= "💡 *Butuh bantuan lain? Balas:* **[0]** Menu Utama";

                return [$message, [$protoData]];
            }
        }

        return ["Silakan ketik inisial nama pasien (misal: 'A') atau nama protokol (misal: 'Secretome') untuk melihat kriteria inklusi dan eksklusinya secara detail.", []];
    }

    /**
     * Handler: general_search (Document Search via Sastrawi & PostgreSQL FTS)
     */
    private function handleGeneralSearch(string $queryText, ?string $intent = null, ?string $fileName = null): array
    {
        $escapedQuery = escapeshellarg($queryText);
        $queryScript = $this->nlpDir . '/process_query.py';
        $cmd = "{$this->pythonPath} {$queryScript} --query {$escapedQuery}";
        if ($intent !== null) {
            $cmd .= " --intent " . escapeshellarg($intent);
        }
        if ($fileName !== null) {
            $cmd .= " --file " . escapeshellarg($fileName);
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
        $finalMatches = [];
        foreach ($filteredMatches as $match) {
            $docName = $match['file_name'];
            $page = $match['page_number'];
            $excerpt = trim($match['content']);
            $score = round($match['score'] * 100, 1);

            // Fetch actual file_path from database to resolve correct folder location
            $stmt = Database::getConnection()->prepare("SELECT file_path FROM chatbot_documents WHERE file_name = :name");
            $stmt->execute(['name' => $docName]);
            $filePath = $stmt->fetchColumn();

            // Strip local public/bck prefix to get relative path
            $relPath = str_replace('/var/www/html/estem_ai_api/api/public/bck/', '', $filePath);
            
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['SERVER_PORT'] ?? 80) == 443) ? "https://" : "http://";
            $apiBase = $protocol . ($_SERVER['HTTP_HOST'] ?? 'api-estemai.zendekia.com');
            // Build correct absolute URL served by Nginx /v1/public/ location block
            $pdfLink = $apiBase . "/v1/public/bck/" . implode('/', array_map('rawurlencode', explode('/', $relPath))) . "#page=" . $page;

            $message .= "> ... " . $excerpt . " ...\n\n";
            $message .= "*(Sumber: [" . $docName . "](" . $pdfLink . "), Halaman " . $page . " - Kecocokan: " . $score . "% )*\n";
            $message .= "---\n\n";

            $match['pdf_link'] = $pdfLink;
            $finalMatches[] = $match;
        }

        // Append navigation context suggestion at the bottom
        $message .= "💡 *Butuh bantuan lain? Balas:* **[0]** Menu Utama | **[5]** Cari di Dokumen Spesifik";

        return [$message, $finalMatches];
    }
}
