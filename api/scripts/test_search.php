<?php

require_once __DIR__ . '/../vendor/autoload.php';

if ($argc < 2) {
    echo "Usage: php test_search.php \"<your query>\"\n";
    exit(1);
}

$queryText = $argv[1];
$pythonPath = __DIR__ . '/../nlp/venv/bin/python3';
$queryScript = __DIR__ . '/../nlp/process_query.py';

echo "=== Original Query: \"$queryText\" ===\n";

$escapedQuery = escapeshellarg($queryText);
$cmd = "$pythonPath $queryScript --query $escapedQuery";
$output = shell_exec($cmd);
$result = json_decode($output, true);

if (!isset($result['success']) || !$result['success']) {
    echo "Failed to process query: " . ($result['error'] ?? 'Unknown error') . "\n";
    exit(1);
}

$matches = $result['matches'] ?? [];

if (empty($matches)) {
    echo "No matches found.\n";
} else {
    echo "=== Found " . count($matches) . " Matches ===\n\n";
    foreach ($matches as $idx => $match) {
        echo "[" . ($idx + 1) . "] Document: " . $match['file_name'] . " (Page " . $match['page_number'] . ")\n";
        echo "Semantic Score: " . round($match['score'] * 100, 2) . "%\n";
        echo "Excerpt: " . substr(trim($match['content']), 0, 300) . "...\n";
        echo "----------------------------------------\n\n";
    }
}
