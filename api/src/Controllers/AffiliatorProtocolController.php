<?php

namespace App\Controllers;

use App\Config\Database;
use App\Middleware\AuthMiddleware;
use App\Models\ApiResponse;
use App\Helpers\RequestHelper;
use PDO;

class AffiliatorProtocolController
{
    /**
     * Retrieve affiliator protocols (all or single by ID).
     */
    public function get()
    {
        try {
            $pdo = Database::getConnection();
            $id = $_GET['id'] ?? null;

            if ($id !== null) {
                $stmt = $pdo->prepare("SELECT * FROM affiliator_protocols WHERE id = :id");
                $stmt->execute(['id' => $id]);
                $protocol = $stmt->fetch();

                if (!$protocol) {
                    (new ApiResponse(false, 'Protocol not found'))->send(404);
                }

                (new ApiResponse(true, 'Protocol retrieved successfully', $protocol))->send(200);
            } else {
                $stmt = $pdo->query("SELECT * FROM affiliator_protocols ORDER BY id DESC");
                $protocols = $stmt->fetchAll();

                (new ApiResponse(true, 'Protocols retrieved successfully', $protocols))->send(200);
            }
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }

    /**
     * Create a new affiliator protocol.
     */
    public function post()
    {
        $user = AuthMiddleware::authorize(['affiliator', 'admin']);

        try {
            $pdo = Database::getConnection();
            $data = RequestHelper::getBody();

            $affiliatorId = $data['affiliator_id'] ?? null;
            $protocolName = trim($data['protocol_name'] ?? '');
            $statusId = trim($data['status_id'] ?? '');

            if ($affiliatorId === null || empty($protocolName) || empty($statusId)) {
                (new ApiResponse(false, 'affiliator_id, protocol_name, and status_id are required'))->send(400);
            }

            $indication = $data['indication'] ?? null;
            $protocolVersion = $data['protocol_version'] ?? null;
            $creatorNote = $data['creator_note'] ?? null;
            $reviewerNote = $data['reviewer_note'] ?? null;
            $createdBy = $user['data']['id'] ?? null;

            $stmt = $pdo->prepare("
                INSERT INTO affiliator_protocols (affiliator_id, protocol_name, indication, protocol_version, status_id, creator_note, reviewer_note, create_by, created_at, updated_at)
                VALUES (:affiliator_id, :protocol_name, :indication, :protocol_version, :status_id, :creator_note, :reviewer_note, :create_by, NOW(), NOW())
                RETURNING *
            ");

            $stmt->bindValue(':affiliator_id', $affiliatorId, PDO::PARAM_INT);
            $stmt->bindValue(':protocol_name', $protocolName, PDO::PARAM_STR);
            $stmt->bindValue(':indication', $indication, $indication === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':protocol_version', $protocolVersion, $protocolVersion === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':status_id', $statusId, PDO::PARAM_STR);
            $stmt->bindValue(':creator_note', $creatorNote, $creatorNote === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':reviewer_note', $reviewerNote, $reviewerNote === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':create_by', $createdBy, $createdBy === null ? PDO::PARAM_NULL : PDO::PARAM_INT);

            $stmt->execute();
            $newProtocol = $stmt->fetch();

            (new ApiResponse(true, 'Protocol created successfully', $newProtocol))->send(201);
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }

    /**
     * Update an existing affiliator protocol.
     */
    public function put()
    {
        $user = AuthMiddleware::authorize(['affiliator', 'admin']);

        try {
            $pdo = Database::getConnection();
            $data = RequestHelper::getBody();

            $id = $_GET['id'] ?? $data['id'] ?? null;
            if ($id === null) {
                (new ApiResponse(false, 'Protocol ID is required'))->send(400);
            }

            // Check if protocol exists
            $stmt = $pdo->prepare("SELECT id FROM affiliator_protocols WHERE id = :id");
            $stmt->execute(['id' => $id]);
            if (!$stmt->fetch()) {
                (new ApiResponse(false, 'Protocol not found'))->send(404);
            }

            $affiliatorId = $data['affiliator_id'] ?? null;
            $protocolName = trim($data['protocol_name'] ?? '');
            $statusId = trim($data['status_id'] ?? '');

            if ($affiliatorId === null || empty($protocolName) || empty($statusId)) {
                (new ApiResponse(false, 'affiliator_id, protocol_name, and status_id are required'))->send(400);
            }

            $indication = $data['indication'] ?? null;
            $protocolVersion = $data['protocol_version'] ?? null;
            $creatorNote = $data['creator_note'] ?? null;
            $reviewerNote = $data['reviewer_note'] ?? null;
            $updatedBy = $user['data']['id'] ?? null;

            $stmt = $pdo->prepare("
                UPDATE affiliator_protocols
                SET affiliator_id = :affiliator_id,
                    protocol_name = :protocol_name,
                    indication = :indication,
                    protocol_version = :protocol_version,
                    status_id = :status_id,
                    creator_note = :creator_note,
                    reviewer_note = :reviewer_note,
                    updated_by = :updated_by,
                    updated_at = NOW()
                WHERE id = :id
                RETURNING *
            ");

            $stmt->bindValue(':affiliator_id', $affiliatorId, PDO::PARAM_INT);
            $stmt->bindValue(':protocol_name', $protocolName, PDO::PARAM_STR);
            $stmt->bindValue(':indication', $indication, $indication === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':protocol_version', $protocolVersion, $protocolVersion === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':status_id', $statusId, PDO::PARAM_STR);
            $stmt->bindValue(':creator_note', $creatorNote, $creatorNote === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':reviewer_note', $reviewerNote, $reviewerNote === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
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
     * Delete an affiliator protocol.
     */
    public function delete()
    {
        AuthMiddleware::authorize(['affiliator', 'admin']);

        try {
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
            $stmt = $pdo->prepare("SELECT id FROM affiliator_protocols WHERE id = :id");
            $stmt->execute(['id' => $id]);
            if (!$stmt->fetch()) {
                (new ApiResponse(false, 'Protocol not found'))->send(404);
            }

            $stmt = $pdo->prepare("DELETE FROM affiliator_protocols WHERE id = :id");
            $stmt->execute(['id' => $id]);

            (new ApiResponse(true, 'Protocol deleted successfully'))->send(200);
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }
}
