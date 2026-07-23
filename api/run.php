<?php

if ($argc < 2) {
    echo "Usage: php run.php <script_name> [arguments...]\n";
    echo "Example: php run.php generate_controller admin_protocols\n";
    exit(1);
}

$scriptArg = $argv[1];

// Resolve the script file, searching both root directory and scripts directory
$searchPaths = [
    __DIR__ . '/' . $scriptArg,
    __DIR__ . '/' . $scriptArg . '.php',
    __DIR__ . '/' . preg_replace('/\.php$/i', '', $scriptArg) . '_controller.php',
    __DIR__ . '/scripts/' . $scriptArg,
    __DIR__ . '/scripts/' . $scriptArg . '.php',
    __DIR__ . '/scripts/' . preg_replace('/\.php$/i', '', $scriptArg) . '_controller.php',
];

$scriptPath = null;
foreach ($searchPaths as $path) {
    if (file_exists($path) && !is_dir($path)) {
        $scriptPath = $path;
        break;
    }
}

if (!$scriptPath) {
    echo "Error: Script matching '{$scriptArg}' not found in root or scripts directory.\n";
    exit(1);
}

// Adjust command-line arguments to pass them downstream to the executed script
$childArgv = array_merge([$scriptPath], array_slice($argv, 2));
$childArgc = count($childArgv);

$argv = $childArgv;
$argc = $childArgc;

include $scriptPath;
