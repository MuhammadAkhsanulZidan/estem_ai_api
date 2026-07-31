<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config\Database;

$uploadDir = '/var/www/html/estem_ai_api/api/public/bck';
$pythonPath = __DIR__ . '/../nlp/venv/bin/python3';
$parserScript = __DIR__ . '/../nlp/parse_document.py';

echo "=== Starting Document Watcher ===\n";

if (!is_dir($uploadDir)) {
    echo "Directory does not exist: $uploadDir\n";
    exit(1);
}

// Recursively find all documents
$directory = new RecursiveDirectoryIterator($uploadDir);
$iterator = new RecursiveIteratorIterator($directory);
$allowedExtensions = ['pdf', 'docx', 'txt', 'md'];
$foundFiles = [];

foreach ($iterator as $file) {
    if ($file->isFile()) {
        $ext = strtolower($file->getExtension());
        if (in_array($ext, $allowedExtensions)) {
            $foundFiles[] = $file->getRealPath();
        }
}
$foundFiles = array_unique($foundFiles);

echo "Found " . count($foundFiles) . " documents on disk.\n";

foreach ($foundFiles as $filePath) {
    $fileName = basename($filePath);
    $lastModified = date('Y-m-d H:i:s', filemtime($filePath));
    
    // Check if document exists and has not been modified
    $existing = Database::fetch(
        "SELECT id, last_modified FROM chatbot_documents WHERE file_path = ?",
        [$filePath]
    );
    
    if ($existing) {
        $existingTime = date('Y-m-d H:i:s', strtotime($existing['last_modified']));
        if ($existingTime === $lastModified) {
            // Document is up to date, skip
            continue;
        }
        echo "Updating modified document: $fileName\n";
        $docId = $existing['id'];
        Database::execute("UPDATE chatbot_documents SET last_modified = ?, last_parsed = NOW() WHERE id = ?", [$lastModified, $docId]);
        Database::execute("DELETE FROM chatbot_document_chunks WHERE document_id = ?", [$docId]);
    } else {
        echo "Processing new document: $fileName\n";
        Database::execute(
            "INSERT INTO chatbot_documents (file_path, file_name, last_modified) VALUES (?, ?, ?)",
            [$filePath, $fileName, $lastModified]
        );
        $docId = Database::fetchColumn("SELECT id FROM chatbot_documents WHERE file_path = ?", [$filePath]);
    }
    
    // Call Python Parser Script
    $escapedPath = escapeshellarg($filePath);
    $cmd = "$pythonPath $parserScript --file $escapedPath";
    
    $output = shell_exec($cmd);
    $result = json_decode($output, true);
    
    if (isset($result['success']) && $result['success']) {
        $chunks = $result['chunks'];
        echo "Successfully parsed $fileName into " . count($chunks) . " chunks.\n";
        
        foreach ($chunks as $chunk) {
            $embeddingStr = isset($chunk['embedding']) ? '{' . implode(',', $chunk['embedding']) . '}' : null;
            Database::execute(
                "INSERT INTO chatbot_document_chunks (document_id, page_number, chunk_index, content, search_vector, embedding) 
                 VALUES (?, ?, ?, ?, to_tsvector('simple', ?), ?)",
                [
                    $docId,
                    $chunk['page_number'],
                    $chunk['chunk_index'],
                    $chunk['content'],
                    $chunk['stemmed'],
                    $embeddingStr
                ]
            );
        }
    } else {
        echo "Failed to parse $fileName. Error: " . ($result['error'] ?? 'Unknown script error') . "\n";
    }
}

echo "=== Document Watcher Completed ===\n";
