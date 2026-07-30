<?php

namespace App\Controllers;

use App\Config\Database;
use App\Models\ApiResponse;
use App\Helpers\RequestHelper;
use App\Helpers\StatusHelper;
use App\Middleware\AuthMiddleware;
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
                    return;
                }

                $affiliator['is_posted'] = true;
                $affiliator['is_revised'] = false;
                $affiliator['status_id'] = StatusHelper::resolveStatus($affiliator);

                (new ApiResponse(true, 'Affiliator retrieved successfully', $affiliator))->send(200);
                return;
            }

            $params = [];
            $statusConditions = [];

            $isPosted   = $_GET['is_posted'] ?? "";
            $isRevised  = $_GET['is_revised'] ?? "";
            $isReviewed = $_GET['is_reviewed'] ?? "";
            $isApproved = $_GET['is_approved'] ?? "";

            // Status Filters
            if ($isReviewed !== ""){
                $statusConditions[] = "is_reviewed = :is_reviewed";
                $params['is_reviewed'] = ($isReviewed === "1" || $isReviewed === "true") ? 'true' : 'false';
            }
            if ($isApproved !== ""){
                $statusConditions[] = "is_approved = :is_approved";
                $params['is_approved'] = ($isApproved === "1" || $isApproved === "true") ? 'true' : 'false';
            }

            $statusWhere = "";
            if (!empty($statusConditions)) {
                $statusWhere = "WHERE " . implode(" AND ", $statusConditions);
            }

            // Base query
            $query = "
                SELECT * FROM (
                    SELECT *
                    FROM affiliators
                    {$statusWhere}
                    ORDER BY id DESC
                ) A
            ";

            $tableName = "(SELECT * FROM affiliators {$statusWhere}) A";

            $responseData = RequestHelper::paginate(
                pdo: $pdo,
                query: $query,
                tableName: $tableName,
                params: $params,
                filterFields: ['affiliator_name'],
                mutateItems: function ($items) {
                    foreach ($items as &$aff) {
                        $aff['is_posted'] = true;
                        $aff['is_revised'] = false;
                        $aff['status_id'] = StatusHelper::resolveStatus($aff);
                    }
                    return $items;
                }
            );

            (new ApiResponse(true, 'Affiliators retrieved successfully', $responseData))->send(200);
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

            // Check if creator is admin to auto-approve
            $isAdmin = false;
            $headers = getallheaders();
            $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';
            if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
                try {
                    $secret = $_ENV['JWT_SECRET'] ?? '';
                    $decoded = \Firebase\JWT\JWT::decode($matches[1], new \Firebase\JWT\Key($secret, 'HS256'));
                    $decodedArray = json_decode(json_encode($decoded), true);
                    if (($decodedArray['data']['role_name'] ?? '') === 'admin') {
                        $isAdmin = true;
                    }
                } catch (\Throwable $e) {
                    // Ignore decoding error, keep isAdmin as false
                }
            }

            $isApproved = $isAdmin;
            $isReviewed = $isAdmin;

            // Generate affiliator_code: first initial + last initial of name, e.g. "Hasan Sadikin" -> "HS"
            $words = array_filter(explode(' ', $name));
            if (count($words) >= 2) {
                $firstWord = reset($words);
                $lastWord = end($words);
                $initial = strtoupper(substr($firstWord, 0, 1) . substr($lastWord, 0, 1));
            } else {
                $singleWord = reset($words);
                $initial = strtoupper(substr($singleWord, 0, 2));
            }

            // Clean initial to only alphanumeric
            $initial = preg_replace('/[^A-Z0-9]/', '', $initial);
            if (empty($initial)) {
                $initial = 'AF';
            }

            // Check duplicate in DB and append counter if duplicate
            $affiliatorCode = $initial;
            $counter = 1;
            while (true) {
                $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM affiliators WHERE affiliator_code = :code");
                $checkStmt->execute(['code' => $affiliatorCode]);
                if ((int)$checkStmt->fetchColumn() === 0) {
                    break;
                }
                $affiliatorCode = $initial . "-" . $counter;
                $counter++;
            }

            $stmt = $pdo->prepare("
                INSERT INTO affiliators (affiliator_name, affiliator_type, address, contact_phone, contact_email, director_name, operational_number, bed_number, is_approved, is_reviewed, affiliator_code, created_at, updated_at)
                VALUES (:name, :type, :address, :phone, :email, :director_name, :operational_number, :bed_number, :is_approved, :is_reviewed, :affiliator_code, NOW(), NOW())
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
            $stmt->bindValue(':is_approved', $isApproved, PDO::PARAM_BOOL);
            $stmt->bindValue(':is_reviewed', $isReviewed, PDO::PARAM_BOOL);
            $stmt->bindValue(':affiliator_code', $affiliatorCode, PDO::PARAM_STR);

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

    /**
     * Dedicated function for admin to approve or reject an affiliator.
     */
    public function review_affiliator(): void
    {
        try {
            $pdo = Database::getConnection();
            $data = RequestHelper::getBody();

            $id = $_GET['id'] ?? $data['id'] ?? null;
            $decision = $data['decision'] ?? null;

            if ($id === null || $decision === null) {
                (new ApiResponse(false, 'Affiliator ID and decision are required'))->send(400);
                return;
            }

            // Check if exists
            $stmt = $pdo->prepare("SELECT affiliator_name FROM affiliators WHERE id = :id");
            $stmt->execute(['id' => $id]);
            $existing = $stmt->fetch();
            if (!$existing) {
                (new ApiResponse(false, 'Affiliator not found'))->send(404);
                return;
            }

            $isApproved = false;
            $isReviewed = true;

            if ($decision === 'approve') {
                $isApproved = true;
            } elseif ($decision === 'reject') {
                $isApproved = false;
            } else {
                (new ApiResponse(false, 'Invalid decision'))->send(400);
                return;
            }

            $stmt = $pdo->prepare("
                UPDATE affiliators
                SET is_approved = :is_approved,
                    is_reviewed = :is_reviewed,
                    updated_at = NOW()
                WHERE id = :id
                RETURNING *
            ");

            $stmt->bindValue(':is_approved', $isApproved, PDO::PARAM_BOOL);
            $stmt->bindValue(':is_reviewed', $isReviewed, PDO::PARAM_BOOL);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);

            $stmt->execute();
            $updated = $stmt->fetch(PDO::FETCH_ASSOC);

            // Resolve friendly status for returning
            $updated['is_posted'] = true;
            $updated['is_revised'] = false;
            $updated['status_id'] = StatusHelper::resolveStatus($updated);

            (new ApiResponse(true, 'Affiliator decision saved successfully', $updated))->send(200);
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }
}
