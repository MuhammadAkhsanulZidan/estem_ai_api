<?php

namespace App\Controllers;

use App\Config\Database;
use App\Middleware\AuthMiddleware;
use App\Models\ApiResponse;
use App\Helpers\RequestHelper;
use PDO;

class EcrfSectionController
{
    /**
     * Retrieve ecrf_sections (all or single by ID).
     */
    public function get()
    {
        try {
            $pdo = Database::getConnection();
            $id = $_GET['id'] ?? null;

            if ($id !== null) {
                $stmt = $pdo->prepare("SELECT * FROM ecrf_sections WHERE id = :id");
                $stmt->execute(['id' => $id]);
                $item = $stmt->fetch();

                if (!$item) {
                    (new ApiResponse(false, 'Record not found'))->send(404);
                }

                (new ApiResponse(true, 'Record retrieved successfully', $item))->send(200);
            } else {
                $stmt = $pdo->query("SELECT * FROM ecrf_sections ORDER BY id DESC");
                $items = $stmt->fetchAll();

                (new ApiResponse(true, 'Records retrieved successfully', $items))->send(200);
            }
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }

    /**
     * Create a new ecrf_sections record.
     */
    public function post()
    {
        // AuthMiddleware::authorize();

        try {
            $pdo = Database::getConnection();
            $data = RequestHelper::getBody();

            // Validate required fields
            if (empty(trim($data['section_name'] ?? ''))) {
                (new ApiResponse(false, 'Required fields: section_name'))->send(400);
            }

            $stmt = $pdo->prepare("
                INSERT INTO ecrf_sections (section_name, created_at, updated_at)
                VALUES (:section_name, NOW(), NOW())
                RETURNING *
            ");

            $stmt->bindValue(':section_name', $data['section_name'], PDO::PARAM_STR);

            $stmt->execute();
            $newItem = $stmt->fetch();

            (new ApiResponse(true, 'Record created successfully', $newItem))->send(201);
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }

    /**
     * Update an existing ecrf_sections record.
     */
    public function put()
    {
        try {
            $pdo = Database::getConnection();
            $data = RequestHelper::getBody();

            $id = $_GET['id'] ?? $data['id'] ?? null;
            if ($id === null) {
                (new ApiResponse(false, 'ID is required'))->send(400);
            }

            // Check if record exists
            $checkStmt = $pdo->prepare("SELECT id FROM ecrf_sections WHERE id = :id");
            $checkStmt->execute(['id' => $id]);
            if (!$checkStmt->fetch()) {
                (new ApiResponse(false, 'Record not found'))->send(404);
            }

            // Validate required fields
            if (empty(trim($data['section_name'] ?? ''))) {
                (new ApiResponse(false, 'Required fields: section_name'))->send(400);
            }

            $stmt = $pdo->prepare("
                UPDATE ecrf_sections
                SET section_name = :section_name,
                    updated_at = NOW()
                WHERE id = :id
                RETURNING *
            ");

            $stmt->bindValue(':section_name', $data['section_name'], PDO::PARAM_STR);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);

            $stmt->execute();
            $updatedItem = $stmt->fetch();

            (new ApiResponse(true, 'Record updated successfully', $updatedItem))->send(200);
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }

    /**
     * Delete a ecrf_sections record.
     */
    public function delete()
    {
        try {
            $pdo = Database::getConnection();
            $id = $_GET['id'] ?? null;

            if ($id === null) {
                $data = RequestHelper::getBody();
                $id = $data['id'] ?? null;
            }

            if ($id === null) {
                (new ApiResponse(false, 'ID is required'))->send(400);
            }

            // Check if record exists
            $checkStmt = $pdo->prepare("SELECT id FROM ecrf_sections WHERE id = :id");
            $checkStmt->execute(['id' => $id]);
            if (!$checkStmt->fetch()) {
                (new ApiResponse(false, 'Record not found'))->send(404);
            }

            $stmt = $pdo->prepare("DELETE FROM ecrf_sections WHERE id = :id");
            $stmt->execute(['id' => $id]);

            (new ApiResponse(true, 'Record deleted successfully'))->send(200);
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }
}
