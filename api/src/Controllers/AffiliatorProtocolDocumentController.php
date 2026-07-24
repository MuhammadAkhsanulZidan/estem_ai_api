<?php

namespace App\Controllers;

use App\Config\Database;
use App\Middleware\AuthMiddleware;
use App\Models\ApiResponse;
use App\Helpers\RequestHelper;
use PDO;

class AffiliatorProtocolDocumentController
{
    /**
     * Retrieve affiliator_protocol_documents (all or single by ID).
     */
    public function get()
    {
        try {
            $pdo = Database::getConnection();
            $id = $_GET['id'] ?? null;

            if ($id !== null) {
                $stmt = $pdo->prepare("SELECT * FROM affiliator_protocol_documents WHERE id = :id");
                $stmt->execute(['id' => $id]);
                $item = $stmt->fetch();

                if (!$item) {
                    (new ApiResponse(false, 'Record not found'))->send(404);
                }

                (new ApiResponse(true, 'Record retrieved successfully', $item))->send(200);
            } else {
                $stmt = $pdo->query("SELECT * FROM affiliator_protocol_documents ORDER BY id DESC");
                $items = $stmt->fetchAll();

                (new ApiResponse(true, 'Records retrieved successfully', $items))->send(200);
            }
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }

    /**
     * Create a new affiliator_protocol_documents record.
     */
    public function post()
    {
        // AuthMiddleware::authorize();

        try {
            $pdo = Database::getConnection();
            $data = RequestHelper::getBody();

            // Validate required fields
            if (!isset($data['protocol_id'])) {
                (new ApiResponse(false, 'Required fields: protocol_id'))->send(400);
            }

            $stmt = $pdo->prepare("
                INSERT INTO affiliator_protocol_documents (protocol_id, document_path, created_at, updated_at)
                VALUES (:protocol_id, :document_path, NOW(), NOW())
                RETURNING *
            ");

            $stmt->bindValue(':protocol_id', $data['protocol_id'], PDO::PARAM_INT);
            $stmt->bindValue(':document_path', $data['document_path'] ?? null, $data['document_path'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);

            $stmt->execute();
            $newItem = $stmt->fetch();

            (new ApiResponse(true, 'Record created successfully', $newItem))->send(201);
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }

    /**
     * Update an existing affiliator_protocol_documents record.
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
            $checkStmt = $pdo->prepare("SELECT id FROM affiliator_protocol_documents WHERE id = :id");
            $checkStmt->execute(['id' => $id]);
            if (!$checkStmt->fetch()) {
                (new ApiResponse(false, 'Record not found'))->send(404);
            }

            // Validate required fields
            if (!isset($data['protocol_id'])) {
                (new ApiResponse(false, 'Required fields: protocol_id'))->send(400);
            }

            $stmt = $pdo->prepare("
                UPDATE affiliator_protocol_documents
                SET protocol_id = :protocol_id,
                    document_path = :document_path,
                    updated_at = NOW()
                WHERE id = :id
                RETURNING *
            ");

            $stmt->bindValue(':protocol_id', $data['protocol_id'], PDO::PARAM_INT);
            $stmt->bindValue(':document_path', $data['document_path'] ?? null, $data['document_path'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);

            $stmt->execute();
            $updatedItem = $stmt->fetch();

            (new ApiResponse(true, 'Record updated successfully', $updatedItem))->send(200);
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }

    /**
     * Delete a affiliator_protocol_documents record.
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
            $checkStmt = $pdo->prepare("SELECT id FROM affiliator_protocol_documents WHERE id = :id");
            $checkStmt->execute(['id' => $id]);
            if (!$checkStmt->fetch()) {
                (new ApiResponse(false, 'Record not found'))->send(404);
            }

            $stmt = $pdo->prepare("DELETE FROM affiliator_protocol_documents WHERE id = :id");
            $stmt->execute(['id' => $id]);

            (new ApiResponse(true, 'Record deleted successfully'))->send(200);
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }
}
