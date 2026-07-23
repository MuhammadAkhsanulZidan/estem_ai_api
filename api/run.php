<?php

if ($argc < 2) {
    echo "Usage: php run.php <script_name> [arguments...]\n";
    echo "Example: php run.php generate admin_protocols\n";
    exit(1);
}

$scriptArg = $argv[1];

// Resolve the script file inside the scripts directory
$scriptPath = __DIR__ . '/scripts/' . $scriptArg;
if (!str_ends_with($scriptPath, '.php')) {
    $scriptPath .= '.php';
}

if (!file_exists($scriptPath)) {
    // Try appending _controller.php if not found directly
    $scriptPath = __DIR__ . '/scripts/' . preg_replace('/\.php$/i', '', $scriptArg) . '_controller.php';
    if (!file_exists($scriptPath)) {
        echo "Error: Script not found matching '{$scriptArg}' in scripts/.\n";
        exit(1);
    }
}

// Adjust command-line arguments to pass them downstream to the executed script
$childArgv = array_merge([$scriptPath], array_slice($argv, 2));
$childArgc = count($childArgv);

$argv = $childArgv;
$argc = $childArgc;

include $scriptPath;
