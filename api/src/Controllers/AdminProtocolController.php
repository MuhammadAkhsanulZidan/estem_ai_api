<?php

namespace App\Controllers;

use App\Config\Database;
use App\Middleware\AuthMiddleware;
use App\Models\ApiResponse;
use App\Helpers\RequestHelper;

use PDO;

class AdminProtocolController
{
    /**
     * Retrieve admin protocols (all or single by ID).
     */
    public function get()
    {
        try {
            $pdo = Database::getConnection();
            $id = $_GET['id'] ?? null;

            if ($id !== null) {
                $stmt = $pdo->prepare("SELECT * FROM admin_protocols WHERE id = :id");
                $stmt->execute(['id' => $id]);
                $protocol = $stmt->fetch();

                if (!$protocol) {
                    (new ApiResponse(false, 'Protocol not found'))->send(404);
                }

                (new ApiResponse(true, 'Protocol retrieved successfully', $protocol))->send(200);
            } else {
                $stmt = $pdo->query("SELECT * FROM admin_protocols ORDER BY id DESC");
                $protocols = $stmt->fetchAll();

                (new ApiResponse(true, 'Protocols retrieved successfully', $protocols))->send(200);
            }
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }

    /**
     * Create a new admin protocol.
     */
    public function post()
    {
        $user = AuthMiddleware::authorize(['admin']);

        try {
            $pdo = Database::getConnection();
            $data = RequestHelper::getBody();

            $protocolName = trim($data['protocol_name'] ?? '');
            if (empty($protocolName)) {
                (new ApiResponse(false, 'Protocol name is required'))->send(400);
            }

            $indication = $data['indication'] ?? null;
            $protocolVersion = $data['protocol_version'] ?? null;
            $isActive = isset($data['is_active']) ? (bool)$data['is_active'] : false;
            $createdBy = $user['data']['id'];

            $stmt = $pdo->prepare("
                INSERT INTO admin_protocols (protocol_name, indication, protocol_version, is_active, create_by, created_at, updated_at)
                VALUES (:protocol_name, :indication, :protocol_version, :is_active, :create_by, NOW(), NOW())
                RETURNING *
            ");

            $stmt->bindValue(':protocol_name', $protocolName, PDO::PARAM_STR);
            $stmt->bindValue(':indication', $indication, $indication === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':protocol_version', $protocolVersion, $protocolVersion === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':is_active', $isActive, PDO::PARAM_BOOL);
            $stmt->bindValue(':create_by', $createdBy, $createdBy === null ? PDO::PARAM_NULL : PDO::PARAM_INT);

            $stmt->execute();
            $newProtocol = $stmt->fetch();

            (new ApiResponse(true, 'Protocol created successfully', $newProtocol))->send(201);
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }

    /**
     * Update an existing admin protocol.
     */
    public function put()
    {
        try {
            $pdo = Database::getConnection();
            $data = RequestHelper::getBody();
            $user = AuthMiddleware::authorize(['admin']);

            $id = $_GET['id'] ?? $data['id'] ?? null;
            if ($id === null) {
                (new ApiResponse(false, 'Protocol ID is required'))->send(400);
            }

            // Check if protocol exists
            $stmt = $pdo->prepare("SELECT id FROM admin_protocols WHERE id = :id");
            $stmt->execute(['id' => $id]);
            if (!$stmt->fetch()) {
                (new ApiResponse(false, 'Protocol not found'))->send(404);
            }

            $protocolName = trim($data['protocol_name'] ?? '');
            if (empty($protocolName)) {
                (new ApiResponse(false, 'Protocol name is required'))->send(400);
            }

            $indication = $data['indication'] ?? null;
            $protocolVersion = $data['protocol_version'] ?? null;
            $isActive = isset($data['is_active']) ? (bool)$data['is_active'] : true;
            $updatedBy = $user['data']['id']
            $stmt = $pdo->prepare("
                UPDATE admin_protocols
                SET protocol_name = :protocol_name,
                    indication = :indication,
                    protocol_version = :protocol_version,
                    is_active = :is_active,
                    updated_by = :updated_by,
                    updated_at = NOW()
                WHERE id = :id
                RETURNING *
            ");

            $stmt->bindValue(':protocol_name', $protocolName, PDO::PARAM_STR);
            $stmt->bindValue(':indication', $indication, $indication === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':protocol_version', $protocolVersion, $protocolVersion === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':is_active', $isActive, PDO::PARAM_BOOL);
            $stmt->bindValue(':updated_by', $updatedBy, $updatedBy === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);

            $stmt->execute();
            $updatedProtocol = $stmt->fetch();

            (new ApiResponse(true, 'Protocol updated successfully', $updatedProtocol))->send(200);
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }

    /**
     * Delete an admin protocol.
     */
    public function delete()
    {
        try {
            $user = AuthMiddleware::authorize(['admin']);
            $pdo = Database::getConnection();
            $id = $_GET['id'] ?? null;

            if ($id === null) {
                $data = RequestHelper::getBody();
                $id = $data['id'] ?? null;
            }

            if ($id === null) {
                (new ApiResponse(false, 'Protocol ID is required'))->send(400);
            }

            // Check if protocol exists
            $stmt = $pdo->prepare("SELECT id FROM admin_protocols WHERE id = :id");
            $stmt->execute(['id' => $id]);
            if (!$stmt->fetch()) {
                (new ApiResponse(false, 'Protocol not found'))->send(404);
            }

            $stmt = $pdo->prepare("DELETE FROM admin_protocols WHERE id = :id");
            $stmt->execute(['id' => $id]);

            (new ApiResponse(true, 'Protocol deleted successfully'))->send(200);
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }
}
