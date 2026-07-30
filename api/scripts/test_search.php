<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config\Database;

if ($argc < 2) {
    echo "Usage: php test_search.php \"<your query>\"\n";
    exit(1);
}

$queryText = $argv[1];
$pythonPath = __DIR__ . '/../nlp/venv/bin/python3';
$queryScript = __DIR__ . '/../nlp/process_query.py';

echo "=== Original Query: \"$queryText\" ===\n";

// 1. Process query in Python (Indonesian Stemming & Stopword Removal)
$escapedQuery = escapeshellarg($queryText);
$cmd = "$pythonPath $queryScript --query $escapedQuery";
$output = shell_exec($cmd);
$result = json_decode($output, true);

if (!isset($result['success']) || !$result['success']) {
    echo "Failed to process query: " . ($result['error'] ?? 'Unknown error') . "\n";
    exit(1);
}

$stemmedQuery = $result['stemmed_query'];
echo "=== Stemmed Query: \"$stemmedQuery\" ===\n";

if (empty(trim($stemmedQuery))) {
    echo "Query only contained stopwords. No results.\n";
    exit(0);
}

// 2. Perform Full-Text Search in PostgreSQL
try {
    $sql = "
        SELECT c.content, d.file_name, c.page_number, ts_rank_cd(c.search_vector, query) as rank
        FROM chatbot_document_chunks c
        JOIN chatbot_documents d ON c.document_id = d.id,
        plainto_tsquery('simple', :stemmed) query
        WHERE c.search_vector @@ query
        ORDER BY rank DESC
        LIMIT 3
    ";
    
    $matches = Database::fetchAll($sql, [':stemmed' => $stemmedQuery]);
    
    if (empty($matches)) {
        echo "No matches found.\n";
    } else {
        echo "=== Found " . count($matches) . " Matches ===\n\n";
        foreach ($matches as $idx => $match) {
            echo "[" . ($idx + 1) . "] Document: " . $match['file_name'] . " (Page " . $match['page_number'] . ")\n";
            echo "Rank/Score: " . round($match['rank'], 4) . "\n";
            echo "Excerpt: " . substr(trim($match['content']), 0, 300) . "...\n";
            echo "----------------------------------------\n\n";
        }
    }
} catch (\Throwable $e) {
    echo "Search failed: " . $e->getMessage() . "\n";
}
