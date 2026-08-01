<?php

if ($argc < 4) {
    echo "Usage: php ingest_single_document.php <temp_file_path> <persistent_file_path> <sanitized_file_name>\n";
    exit(1);
}

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config\Database;

$tempPath = $argv[1];
$persistentPath = $argv[2];
$fileName = $argv[3];

$pythonPath = __DIR__ . '/../nlp/venv/bin/python3';
$parserScript = __DIR__ . '/../nlp/parse_document.py';

try {
    if (!file_exists($tempPath)) {
        throw new \Exception("Temporary file not found at: $tempPath");
    }

    $lastModified = date('Y-m-d H:i:s', filemtime($tempPath));

    // Call Python Parser Script
    $escapedPath = escapeshellarg($tempPath);
    $cmd = "$pythonPath $parserScript --file $escapedPath";
    
    $output = shell_exec($cmd);
    $result = json_decode($output, true);
    
    if (isset($result['success']) && $result['success']) {
        $pdo = Database::getConnection();
        
        // Start Transaction to avoid partial ingestion
        $pdo->beginTransaction();
        
        // Remove existing chatbot document index if any for this file path
        $delStmt = $pdo->prepare("DELETE FROM chatbot_documents WHERE file_path = :file_path");
        $delStmt->execute(['file_path' => $persistentPath]);

        // Insert new document metadata
        $insDoc = $pdo->prepare("
            INSERT INTO chatbot_documents (file_path, file_name, last_modified)
            VALUES (:file_path, :file_name, :last_modified)
            RETURNING id
        ");
        $insDoc->execute([
            'file_path' => $persistentPath,
            'file_name' => $fileName,
            'last_modified' => $lastModified
        ]);
        $docId = $insDoc->fetchColumn();

        // Insert chunks
        $chunks = $result['chunks'];
        $insChunk = $pdo->prepare("
            INSERT INTO chatbot_document_chunks (document_id, page_number, chunk_index, content, intent, search_vector, embedding)
            VALUES (:document_id, :page_number, :chunk_index, :content, :intent, to_tsvector('simple', :stemmed), :embedding)
        ");

        foreach ($chunks as $chunk) {
            $embeddingStr = isset($chunk['embedding']) ? '{' . implode(',', $chunk['embedding']) . '}' : null;
            $intent = $chunk['intent'] ?? 'general_search';
            $insChunk->execute([
                'document_id' => $docId,
                'page_number' => $chunk['page_number'],
                'chunk_index' => $chunk['chunk_index'],
                'content' => $chunk['content'],
                'intent' => $intent,
                'stemmed' => $chunk['stemmed'],
                'embedding' => $embeddingStr
            ]);
        }

        $pdo->commit();
        echo "Successfully indexed $fileName into " . count($chunks) . " chunks.\n";
    } else {
        throw new \Exception($result['error'] ?? 'Unknown script error');
    }
} catch (\Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Ensure logs folder exists
    $logsDir = __DIR__ . '/../logs';
    if (!is_dir($logsDir)) {
        mkdir($logsDir, 0777, true);
    }
    file_put_contents($logsDir . '/chatbot_ingestion_error.log', "[" . date('Y-m-d H:i:s') . "] Failed to parse $fileName. Error: " . $e->getMessage() . "\n", FILE_APPEND);
    echo "Ingestion failed: " . $e->getMessage() . "\n";
} finally {
    // Ensure temporary file cleanup
    if (file_exists($tempPath)) {
        unlink($tempPath);
    }
}
