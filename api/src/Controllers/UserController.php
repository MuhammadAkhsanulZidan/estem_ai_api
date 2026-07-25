<?php

namespace App\Controllers;

use App\Config\Database;
use App\Models\ApiResponse;
use App\Helpers\RequestHelper;
use App\Constants\Role_Id;
use App\Constants\Level_Id;

use PDO;

class UserController
{
    /**
     * Retrieve users (all or single by ID).
     */
    public function get()
    {
        try {
            $pdo = Database::getConnection();
            $id = $_GET['id'] ?? null;

            if ($id !== null) {
                $stmt = $pdo->prepare("
                    SELECT id, username, role_id, level_id, email, affiliator_id, reviewer_id, is_active, created_at, updated_at
                    FROM users
                    WHERE id = :id
                ");
                $stmt->execute(['id' => $id]);
                $user = $stmt->fetch();

                if (!$user) {
                    (new ApiResponse(false, 'User not found'))->send(404);
                }

                (new ApiResponse(true, 'User retrieved successfully', $user))->send(200);
            } else {
                $filterField = $_GET['filter_field'] ?? "";
                $filterValue = $_GET['filter_value'] ?? "";
                $pageNo = isset($_GET['page_no']) ? (int)$_GET['page_no'] : 1;
                $pageRow = isset($_GET['page_row']) ? (int)$_GET['page_row'] : 10;
                $isAffiliator = isset($_GET['is_affiliator']) ? (int)$_GET['is_affiliator'] : 0;

                $allowedFields = ['username'];

                $where = 'WHERE 1=1';
                $conditions = [];
                $params = [];

                if ($filterField !== "" && $filterValue !== "" && in_array($filterField, $allowedFields)) {
                    $where = "{$where} AND {$filterField} ILIKE :val";
                    $params['val'] = '%' . $filterValue . '%';
                } else if($filterValue !== ""){
                    $where = "{$where} AND username ILIKE :val";
                    $params['val'] = '%' . $filterValue . '%';
                }

                if($isAffiliator == 1){
                    $where = "{$where} AND affiliator_id IS NOT NULL";
                }

                // 1. Get total items count
                $countQuery = "SELECT COUNT(*) FROM users $where";
                $stmt = $pdo->prepare($countQuery);
                foreach ($params as $key => $val) {
                    $stmt->bindValue(':' . $key, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
                }
                $stmt->execute();
                $totalItems = (int)$stmt->fetchColumn();

                // 2. Get paginated results
                $query = "
                    SELECT u.id, u.username, u.email, u.is_active,
                    u.role_id, u.level_id,
                    u.affiliator_id, a.affiliator_name, u.reviewer_id,
                    u.created_at
                    FROM users u
                    LEFT JOIN affiliators a ON u.affiliator_id = a.id
                    $where ORDER BY id DESC
                ";

                $useLimit = $pageNo !== null && $pageRow !== null && $pageNo > 0 && $pageRow > 0;
                if ($useLimit) {
                    $offset = ($pageNo - 1) * $pageRow;
                    $query .= " LIMIT :limit OFFSET :offset";
                }

                $stmt = $pdo->prepare($query);
                foreach ($params as $key => $val) {
                    $stmt->bindValue(':' . $key, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
                }
                if ($useLimit) {
                    $stmt->bindValue(':limit', $pageRow, PDO::PARAM_INT);
                    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
                }

                $stmt->execute();
                $users = $stmt->fetchAll();

                $responseData = [
                    'items' => $users,
                    'total_items' => $totalItems,
                    'page_no' => $pageNo ?? 1,
                    'page_row' => $pageRow ?? $totalItems
                ];

                (new ApiResponse(true, 'Users retrieved successfully', $responseData))->send(200);
            }
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }

    /**
     * Create a new user.
     */
    public function post()
    {
        try {
            $pdo = Database::getConnection();
            $data = RequestHelper::getBody();

            $username = trim($data['username'] ?? '');
            $roleId = $data['role_id'] ?? null;
            $levelId = $data['level_id'] ?? null;
            $email = trim($data['email'] ?? '');
            $password = $data['password'] ?? '';

            if (empty($username) || $roleId === null || $levelId === null || empty($email) || empty($password)) {
                (new ApiResponse(false, 'Username, role_id, level_id, email, and password are required'))->send(400);
            }

            $passwordHash = password_hash($password, PASSWORD_BCRYPT);
            $affiliatorId = $data['affiliator_id'] ?? null;
            $reviewerId = $data['reviewer_id'] ?? null;
            $isActive = isset($data['is_active']) ? (bool)$data['is_active'] : true;

            // Check if email already exists
            $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = :email");
            $checkStmt->execute(['email' => $email]);
            if ($checkStmt->fetch()) {
                (new ApiResponse(false, 'Email already exists'))->send(400);
            }

            $stmt = $pdo->prepare("
                INSERT INTO users (username, role_id, level_id, email, password_hash, affiliator_id, reviewer_id, is_active, created_at, updated_at)
                VALUES (:username, :role_id, :level_id, :email, :password_hash, :affiliator_id, :reviewer_id, :is_active, NOW(), NOW())
                RETURNING id, username, role_id, level_id, email, affiliator_id, reviewer_id, is_active, created_at, updated_at
            ");

            $stmt->bindValue(':username', $username, PDO::PARAM_STR);
            $stmt->bindValue(':role_id', $roleId, PDO::PARAM_INT);
            $stmt->bindValue(':level_id', $levelId, PDO::PARAM_INT);
            $stmt->bindValue(':email', $email, PDO::PARAM_STR);
            $stmt->bindValue(':password_hash', $passwordHash, PDO::PARAM_STR);
            $stmt->bindValue(':affiliator_id', $affiliatorId, $affiliatorId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindValue(':reviewer_id', $reviewerId, $reviewerId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindValue(':is_active', $isActive, PDO::PARAM_BOOL);

            $stmt->execute();
            $newUser = $stmt->fetch();

            (new ApiResponse(true, 'User created successfully', $newUser))->send(201);
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }

    /**
     * Update an existing user.
     */
    public function put()
    {
        try {
            $pdo = Database::getConnection();
            $data = RequestHelper::getBody();

            $id = $_GET['id'] ?? $data['id'] ?? null;
            if ($id === null) {
                (new ApiResponse(false, 'User ID is required'))->send(400);
            }

            // Check if user exists
            $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = :id");
            $stmt->execute(['id' => $id]);
            $existingUser = $stmt->fetch();
            if (!$existingUser) {
                (new ApiResponse(false, 'User not found'))->send(404);
            }

            $username = trim($data['username'] ?? '');
            $roleId = $data['role_id'] ?? null;
            $levelId = $data['level_id'] ?? null;
            $email = trim($data['email'] ?? '');

            if (empty($username) || $roleId === null || $levelId === null || empty($email)) {
                (new ApiResponse(false, 'Username, role_id, level_id, and email are required'))->send(400);
            }

            // Check if email already exists for another user
            $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = :email AND id != :id");
            $checkStmt->execute(['email' => $email, 'id' => $id]);
            if ($checkStmt->fetch()) {
                (new ApiResponse(false, 'Email already in use by another user'))->send(400);
            }

            $password = $data['password'] ?? '';
            $passwordHash = !empty($password) ? password_hash($password, PASSWORD_BCRYPT) : $existingUser['password_hash'];

            $affiliatorId = $data['affiliator_id'] ?? null;
            $reviewerId = $data['reviewer_id'] ?? null;
            $isActive = isset($data['is_active']) ? (bool)$data['is_active'] : true;

            $stmt = $pdo->prepare("
                UPDATE users
                SET username = :username,
                    role_id = :role_id,
                    level_id = :level_id,
                    email = :email,
                    password_hash = :password_hash,
                    affiliator_id = :affiliator_id,
                    reviewer_id = :reviewer_id,
                    is_active = :is_active,
                    updated_at = NOW()
                WHERE id = :id
                RETURNING id, username, role_id, level_id, email, affiliator_id, reviewer_id, is_active, created_at, updated_at
            ");

            $stmt->bindValue(':username', $username, PDO::PARAM_STR);
            $stmt->bindValue(':role_id', $roleId, PDO::PARAM_INT);
            $stmt->bindValue(':level_id', $levelId, PDO::PARAM_INT);
            $stmt->bindValue(':email', $email, PDO::PARAM_STR);
            $stmt->bindValue(':password_hash', $passwordHash, PDO::PARAM_STR);
            $stmt->bindValue(':affiliator_id', $affiliatorId, $affiliatorId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindValue(':reviewer_id', $reviewerId, $reviewerId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindValue(':is_active', $isActive, PDO::PARAM_BOOL);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);

            $stmt->execute();
            $updatedUser = $stmt->fetch();

            (new ApiResponse(true, 'User updated successfully', $updatedUser))->send(200);
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }
    /**
     * Update an existing user.
     */
    public function approve_user()
    {
        try {
            $pdo = Database::getConnection();
            $data = RequestHelper::getBody();

            $id = $_GET['id'] ?? $data['id'] ?? null;
            $isActive = isset($data['is_active']) ? (bool)$data['is_active'] : false;

            if ($id === null) {
                (new ApiResponse(false, 'User ID is required'))->send(400);
            }

            // Check if user exists
            $stmt = $pdo->prepare("SELECT username FROM users WHERE id = :id");
            $stmt->execute(['id' => $id]);
            $existingUser = $stmt->fetch();
            if (!$existingUser) {
                (new ApiResponse(false, 'User not found'))->send(404);
            }
            $isActive = isset($data['is_active']) ? (bool)$data['is_active'] : true;
            $stmt = $pdo->prepare("
                UPDATE users
                SET is_active = :is_active,
                    updated_at = NOW()
                WHERE id = :id
                RETURNING id, username, role_id, level_id, email, affiliator_id, reviewer_id, is_active, created_at, updated_at
            ");

            $stmt->bindValue(':is_active', $isActive, PDO::PARAM_BOOL);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);

            $stmt->execute();
            $updatedUser = $stmt->fetch();

            (new ApiResponse(true, 'User updated successfully', $updatedUser))->send(200);
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }

    /**
     * Delete a user.
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
                (new ApiResponse(false, 'User ID is required'))->send(400);
            }

            // Check if user exists
            $stmt = $pdo->prepare("SELECT id FROM users WHERE id = :id");
            $stmt->execute(['id' => $id]);
            if (!$stmt->fetch()) {
                (new ApiResponse(false, 'User not found'))->send(404);
            }

            $stmt = $pdo->prepare("DELETE FROM users WHERE id = :id");
            $stmt->execute(['id' => $id]);

            (new ApiResponse(true, 'User deleted successfully'))->send(200);
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }

    /**
     * Register a new user under an affiliator (defaults to is_active = false).
     */
    public function register_affiliator()
    {
        try {
            $pdo = Database::getConnection();
            $data = RequestHelper::getBody();

            $username = trim($data['username'] ?? '');
            $email = trim($data['email'] ?? '');
            $password = $data['password'] ?? '';
            $affiliatorId = $data['affiliator_id'] ?? null;

            if (empty($username) || empty($email) || empty($password) || $affiliatorId === null) {
                (new ApiResponse(false, 'Username, email, password, and affiliator_id are required'))->send(400);
            }

            $passwordHash = password_hash($password, PASSWORD_BCRYPT);
            $isActive = false; // Must be approved by admin

            // Check if email already exists
            $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = :email");
            $checkStmt->execute(['email' => $email]);
            if ($checkStmt->fetch()) {
                (new ApiResponse(false, 'Email already exists'))->send(400);
            }

            $stmt = $pdo->prepare("
                INSERT INTO users (username, role_id, level_id, email, password_hash, affiliator_id, is_active, created_at, updated_at)
                VALUES (:username, :role_id, :level_id, :email, :password_hash, :affiliator_id, :is_active, NOW(), NOW())
                RETURNING id, username, role_id, level_id, email, affiliator_id, is_active, created_at, updated_at
            ");

            $stmt->bindValue(':username', $username, PDO::PARAM_STR);
            $stmt->bindValue(':role_id', ROLE_ID::AFFILIATOR, PDO::PARAM_INT);
            $stmt->bindValue(':level_id', LEVEL_ID::SYSUSER, PDO::PARAM_INT);
            $stmt->bindValue(':email', $email, PDO::PARAM_STR);
            $stmt->bindValue(':password_hash', $passwordHash, PDO::PARAM_STR);
            $stmt->bindValue(':affiliator_id', $affiliatorId, PDO::PARAM_INT);
            $stmt->bindValue(':is_active', $isActive, PDO::PARAM_BOOL);

            $stmt->execute();
            $newUser = $stmt->fetch();

            (new ApiResponse(true, 'Registration successful, awaiting administrator approval', $newUser))->send(201);
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }

    /**
     * Register a new user as reviewer (defaults to is_active = false).
     */
    public function register_reviewer()
    {
        try {
            $pdo = Database::getConnection();
            $data = RequestHelper::getBody();

            $username = trim($data['username'] ?? '');
            $email = trim($data['email'] ?? '');
            $password = $data['password'] ?? '';

            if (empty($username) || empty($email) || empty($password)) {
                (new ApiResponse(false, 'Username, email, and password are required'))->send(400);
            }

            $passwordHash = password_hash($password, PASSWORD_BCRYPT);
            $isActive = false; // Must be approved by admin

            // Check if email already exists
            $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = :email");
            $checkStmt->execute(['email' => $email]);
            if ($checkStmt->fetch()) {
                (new ApiResponse(false, 'Email already exists'))->send(400);
            }

            // Fetch role ID for reviewer dynamically
            $roleStmt = $pdo->prepare("SELECT id FROM roles WHERE name ILIKE 'reviewer'");
            $roleStmt->execute();
            $role = $roleStmt->fetch();
            $roleId = $role ? $role['id'] : 2;

            $stmt = $pdo->prepare("
                INSERT INTO users (username, role_id, level_id, email, password_hash, is_active, created_at, updated_at)
                VALUES (:username, :role_id, :level_id, :email, :password_hash, :is_active, NOW(), NOW())
                RETURNING id, username, role_id, level_id, email, is_active, created_at, updated_at
            ");

            $stmt->bindValue(':username', $username, PDO::PARAM_STR);
            $stmt->bindValue(':role_id', $roleId, PDO::PARAM_INT);
            $stmt->bindValue(':level_id', 1, PDO::PARAM_INT); // Level 1 for standard user
            $stmt->bindValue(':email', $email, PDO::PARAM_STR);
            $stmt->bindValue(':password_hash', $passwordHash, PDO::PARAM_STR);
            $stmt->bindValue(':is_active', $isActive, PDO::PARAM_BOOL);

            $stmt->execute();
            $newUser = $stmt->fetch();

            (new ApiResponse(true, 'Registration successful, awaiting administrator approval', $newUser))->send(201);
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }
}
