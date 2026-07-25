<?php

namespace App\Controllers;

use App\Config\Database;
use App\Models\ApiResponse;
use App\Helpers\RequestHelper;
use PDO;

class AffiliatorController
{
    /**
     * Retrieve affiliators (all or single by ID).
     */
    public function get()
    {
        try {
            $pdo = Database::getConnection();
            $id = $_GET['id'] ?? null;

            if ($id !== null) {
                $stmt = $pdo->prepare("
                    SELECT * FROM affiliators WHERE id = :id
                ");
                $stmt->execute(['id' => $id]);
                $affiliator = $stmt->fetch();

                if (!$affiliator) {
                    (new ApiResponse(false, 'Affiliator not found'))->send(404);
                }

                (new ApiResponse(true, 'Affiliator retrieved successfully', $affiliator))->send(200);
            } else {
                $pageNo = isset($_GET['page_no']) ? (int)$_GET['page_no'] : null;
                $filterField = $_GET['filter_field'] ?? null;
                $filterValue = $_GET['filter_value'] ?? '';

                $sql = "SELECT * FROM affiliators";
                $conditions = [];
                $params = [];

                if ($filterField === 'name' && !empty($filterValue)) {
                    $conditions[] = "affiliator_name ILIKE :filterValue";
                    $params['filterValue'] = '%' . $filterValue . '%';
                }

                if (count($conditions) > 0) {
                    $sql .= " WHERE " . implode(" AND ", $conditions);
                }

                $sql .= " ORDER BY id DESC";

                if ($pageNo !== null) {
                    $limit = 10;
                    $offset = ($pageNo - 1) * $limit;
                    if ($offset < 0) $offset = 0;
                    
                    // Postgres limit offset
                    $sql .= " LIMIT " . (int)$limit . " OFFSET " . (int)$offset;
                }

                $stmt = $pdo->prepare($sql);
                foreach ($params as $key => $val) {
                    $stmt->bindValue(':' . $key, $val, PDO::PARAM_STR);
                }

                $stmt->execute();
                $affiliators = $stmt->fetchAll();

                (new ApiResponse(true, 'Affiliators retrieved successfully', $affiliators))->send(200);
            }
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }

    /**
     * Create a new affiliator.
     */
    public function post()
    {
        try {
            $pdo = Database::getConnection();
            $data = RequestHelper::getBody();

            $name = trim($data['affiliator_name'] ?? '');
            $type = trim($data['affiliator_type'] ?? '');
            $address = trim($data['address'] ?? '');
            $phone = trim($data['contact_phone'] ?? '');
            $email = trim($data['contact_email'] ?? '');

            if (empty($name) || empty($type) || empty($address) || empty($phone) || empty($email)) {
                (new ApiResponse(false, 'Name, type, address, phone, and email are required'))->send(400);
            }

            $directorName = trim($data['director_name'] ?? '');
            $operationalNumber = trim($data['operational_number'] ?? '');
            $bedNumber = isset($data['bed_number']) ? (int)$data['bed_number'] : null;

            $stmt = $pdo->prepare("
                INSERT INTO affiliators (affiliator_name, affiliator_type, address, contact_phone, contact_email, director_name, operational_number, bed_number, created_at, updated_at)
                VALUES (:name, :type, :address, :phone, :email, :director_name, :operational_number, :bed_number, NOW(), NOW())
                RETURNING *
            ");

            $stmt->bindValue(':name', $name, PDO::PARAM_STR);
            $stmt->bindValue(':type', $type, PDO::PARAM_STR);
            $stmt->bindValue(':address', $address, PDO::PARAM_STR);
            $stmt->bindValue(':phone', $phone, PDO::PARAM_STR);
            $stmt->bindValue(':email', $email, PDO::PARAM_STR);
            $stmt->bindValue(':director_name', $directorName, PDO::PARAM_STR);
            $stmt->bindValue(':operational_number', $operationalNumber, PDO::PARAM_STR);
            $stmt->bindValue(':bed_number', $bedNumber, $bedNumber === null ? PDO::PARAM_NULL : PDO::PARAM_INT);

            $stmt->execute();
            $newAffiliator = $stmt->fetch();

            (new ApiResponse(true, 'Affiliator created successfully', $newAffiliator))->send(201);
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }

    /**
     * Update an existing affiliator.
     */
    public function put()
    {
        try {
            $pdo = Database::getConnection();
            $data = RequestHelper::getBody();

            $id = $_GET['id'] ?? $data['id'] ?? null;
            if ($id === null) {
                (new ApiResponse(false, 'Affiliator ID is required'))->send(400);
            }

            // Check if exists
            $stmt = $pdo->prepare("SELECT id FROM affiliators WHERE id = :id");
            $stmt->execute(['id' => $id]);
            if (!$stmt->fetch()) {
                (new ApiResponse(false, 'Affiliator not found'))->send(404);
            }

            $name = trim($data['affiliator_name'] ?? '');
            $type = trim($data['affiliator_type'] ?? '');
            $address = trim($data['address'] ?? '');
            $phone = trim($data['contact_phone'] ?? '');
            $email = trim($data['contact_email'] ?? '');

            if (empty($name) || empty($type) || empty($address) || empty($phone) || empty($email)) {
                (new ApiResponse(false, 'Name, type, address, phone, and email are required'))->send(400);
            }

            $directorName = trim($data['director_name'] ?? '');
            $operationalNumber = trim($data['operational_number'] ?? '');
            $bedNumber = isset($data['bed_number']) ? (int)$data['bed_number'] : null;

            $stmt = $pdo->prepare("
                UPDATE affiliators
                SET affiliator_name = :name,
                    affiliator_type = :type,
                    address = :address,
                    contact_phone = :phone,
                    contact_email = :email,
                    director_name = :director_name,
                    operational_number = :operational_number,
                    bed_number = :bed_number,
                    updated_at = NOW()
                WHERE id = :id
                RETURNING *
            ");

            $stmt->bindValue(':name', $name, PDO::PARAM_STR);
            $stmt->bindValue(':type', $type, PDO::PARAM_STR);
            $stmt->bindValue(':address', $address, PDO::PARAM_STR);
            $stmt->bindValue(':phone', $phone, PDO::PARAM_STR);
            $stmt->bindValue(':email', $email, PDO::PARAM_STR);
            $stmt->bindValue(':director_name', $directorName, PDO::PARAM_STR);
            $stmt->bindValue(':operational_number', $operationalNumber, PDO::PARAM_STR);
            $stmt->bindValue(':bed_number', $bedNumber, $bedNumber === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);

            $stmt->execute();
            $updatedAffiliator = $stmt->fetch();

            (new ApiResponse(true, 'Affiliator updated successfully', $updatedAffiliator))->send(200);
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }

    /**
     * Delete an affiliator.
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
                (new ApiResponse(false, 'Affiliator ID is required'))->send(400);
            }

            // Check if exists
            $stmt = $pdo->prepare("SELECT id FROM affiliators WHERE id = :id");
            $stmt->execute(['id' => $id]);
            if (!$stmt->fetch()) {
                (new ApiResponse(false, 'Affiliator not found'))->send(404);
            }

            $stmt = $pdo->prepare("DELETE FROM affiliators WHERE id = :id");
            $stmt->execute(['id' => $id]);

            (new ApiResponse(true, 'Affiliator deleted successfully'))->send(200);
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }
}
