<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config\Database;

try {
    echo "=== Fetching Training Data from PostgreSQL ===\n";
    $phrases = Database::fetchAll("SELECT phrase, intent FROM chatbot_training_data");
    
    if (empty($phrases)) {
        echo "No training data found in database.\n";
        exit(1);
    }
    
    $jsonData = json_encode($phrases);
    
    echo "=== Training Intent Classifier Model ===\n";
    $descriptorspec = [
        0 => ["pipe", "r"], // stdin is a pipe that the child will read from
        1 => ["pipe", "w"], // stdout is a pipe that the child will write to
        2 => ["pipe", "w"]  // stderr is a pipe that the child will write to
    ];
    
    $pythonPath = __DIR__ . '/../nlp/venv/bin/python3';
    $scriptPath = __DIR__ . '/../nlp/train_intent.py';
    
    $process = proc_open("$pythonPath $scriptPath", $descriptorspec, $pipes);
    
    if (is_resource($process)) {
        // Write the training data to Python's stdin
        fwrite($pipes[0], $jsonData);
        fclose($pipes[0]);
        
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        
        $returnValue = proc_close($process);
        
        if ($returnValue === 0) {
            $result = json_decode($stdout, true);
            if (isset($result['success']) && $result['success']) {
                echo "Success: " . $result['message'] . "\n";
            } else {
                echo "Error: " . ($result['error'] ?? 'Unknown error') . "\n";
            }
        } else {
            echo "Process exited with code $returnValue\n";
            echo "Stderr: $stderr\n";
            echo "Stdout: $stdout\n";
        }
    } else {
        echo "Failed to open process for training.\n";
    }
} catch (\Throwable $e) {
    echo "Training failed: " . $e->getMessage() . "\n";
    exit(1);
}
