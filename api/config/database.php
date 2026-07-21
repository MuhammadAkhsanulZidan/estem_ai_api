<?php
// config/database.php

require_once __DIR__ . '/../vendor/autoload.php';

// Load environment variables from the project root
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->safeLoad();

$host = $_ENV['DB_HOST'] ?? '127.0.0.1';
$port = $_ENV['DB_PORT'] ?? '5432';
$db   = $_ENV['DB_NAME'] ?? '';
$user = $_ENV['DB_USER'] ?? '';
$pass = $_ENV['DB_PASS'] ?? '';

$dsn = "pgsql:host=$host;port=$port;dbname=$db;options='--client_encoding=UTF8'";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Throw exceptions on errors
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Fetch arrays by default
    PDO::ATTR_EMULATE_PREPARES   => false,                  // Native prepared statements for security
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    // Log the actual error internally (never output raw DB errors to the user)
    error_log("Database Connection Error: " . $e->getMessage());
    
    http_response_code(500);
    exit("A database connection error occurred. Please try again later.");
}
