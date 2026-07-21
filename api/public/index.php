<?php
// public/index.php

// Include the secure database configuration
require_once __DIR__ . '/../config/database.php';

// Test the connection with a simple PostgreSQL function
$stmt = $pdo->query("SELECT NOW() as current_time");
$result = $stmt->fetch();

echo "Successfully connected to PostgreSQL! Server time: " . $result['current_time'];
