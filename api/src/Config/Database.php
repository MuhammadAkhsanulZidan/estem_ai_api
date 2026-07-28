<?php

namespace App\Config;

use PDO;
use PDOException;
use Dotenv\Dotenv;

class Database
{
    private static ?PDO $instance = null;

    /**
     * Private constructor to prevent direct instantiation.
     */
    private function __construct() {}

    /**
     * Prevent cloning of the instance.
     */
    private function __clone() {}

    /**
     * Returns the single PDO database connection instance.
     */
    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            // Load environment variables if not already loaded
            if (file_exists(__DIR__ . '/../../.env')) {
                $dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
                $dotenv->safeLoad();
            }

            $host = $_ENV['DB_HOST'] ?? '127.0.0.1';
            $port = $_ENV['DB_PORT'] ?? '5432';
            $db   = $_ENV['DB_NAME'] ?? '';
            $user = $_ENV['DB_USER'] ?? '';
            $pass = $_ENV['DB_PASS'] ?? '';

            $dsn = "pgsql:host={$host};port={$port};dbname={$db};options='--client_encoding=UTF8'";

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            try {
                self::$instance = new PDO($dsn, $user, $pass, $options);
            } catch (PDOException $e) {
                error_log("Database Connection Error: " . $e->getMessage());

                http_response_code(500);
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'message' => 'Database connection failed: ' . $e->getMessage()
                ]);
                exit;
            }
        }

        return self::$instance;
    }

    /**
    * Executes a query and returns a single row.
    */
    public static function fetch(string $sql, array $params = []): array|false
    {
        $stmt = self::execute($sql, $params);
        return $stmt->fetch();
    }

    /**
        * Executes a query and returns all matching rows.
        */
    public static function fetchAll(string $sql, array $params = []): array
    {
        $stmt = self::execute($sql, $params);
        return $stmt->fetchAll();
    }

    /**
        * Executes a query and returns a single column value (e.g. SELECT COUNT(*) or SELECT JSON function).
        */
    public static function fetchColumn(string $sql, array $params = [], int $column = 0): mixed
    {
        $stmt = self::execute($sql, $params);
        return $stmt->fetchColumn($column);
    }

    /**
    * Base method that prepares and executes any SQL query.
    */
    public static function execute(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::getConnection()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
}
