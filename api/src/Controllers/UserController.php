<?php

namespace App\Controllers;

use App\Config\Database;
use App\Models\ApiResponse;
use App\Helpers\RequestHelper;
use App\Constants\Role_Id;
use App\Constants\Level_Id;
use App\Helpers\StatusHelper;

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
                    SELECT u.id, u.username, u.role_id, u.level_id, u.email, u.affiliator_id, u.is_approved, u.is_reviewed, u.created_at, u.updated_at, a.affiliator_name
                    FROM users u
                    LEFT JOIN affiliators a ON u.affiliator_id = a.id
                    WHERE u.id = :id
                ");
                $stmt->execute(['id' => $id]);
                $user = $stmt->fetch();

                if (!$user) {
                    (new ApiResponse(false, 'User not found'))->send(404);
                    return;
                }

                $user['is_posted'] = true;
                $user['is_revised'] = false;
                $user['status_id'] = StatusHelper::resolveStatus($user);

                (new ApiResponse(true, 'User retrieved successfully', $user))->send(200);
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
                $statusConditions[] = "u.is_reviewed = :is_reviewed";
                $params['is_reviewed'] = ($isReviewed === "1" || $isReviewed === "true") ? 'true' : 'false';
            }
            if ($isApproved !== ""){
                $statusConditions[] = "u.is_approved = :is_approved";
                $params['is_approved'] = ($isApproved === "1" || $isApproved === "true") ? 'true' : 'false';
            }

            $isAffiliator = isset($_GET['is_affiliator']) ? (int)$_GET['is_affiliator'] : 0;
            $isReviewer = isset($_GET['is_reviewer']) ? (int)$_GET['is_reviewer'] : 0;

            if ($isAffiliator == 1) {
                $statusConditions[] = "u.affiliator_id IS NOT NULL AND u.role_id = " . ROLE_ID::AFFILIATOR;
            }
            if ($isReviewer == 1){
                $statusConditions[] = "u.role_id = " . ROLE_ID::REVIEWER;
            }

            $statusWhere = "";
            if (!empty($statusConditions)) {
                $statusWhere = "WHERE " . implode(" AND ", $statusConditions);
            }

            // Base query with status filtering inside the inner query
            $query = "
                SELECT * FROM (
                    SELECT
                        u.id, u.username, u.email, u.is_approved, u.is_reviewed,
                        u.role_id, u.level_id,
                        u.affiliator_id, a.affiliator_name,
                        u.created_at
                    FROM users u
                    LEFT JOIN affiliators a ON u.affiliator_id = a.id
                    {$statusWhere}
                    ORDER BY u.id DESC
                ) A
            ";

            // Dynamic table expression for pagination counting
            $tableName = "(SELECT u.id, u.username, u.email, u.is_approved, u.is_reviewed, u.role_id, u.level_id, u.affiliator_id, u.created_at FROM users u {$statusWhere}) A";

            $responseData = RequestHelper::paginate(
                pdo: $pdo,
                query: $query,
                tableName: $tableName,
                params: $params,
                filterFields: ['username', 'email'],
                mutateItems: function ($items) {
                    foreach ($items as &$u) {
                        $u['is_posted'] = true;
                        $u['is_revised'] = false;
                        $u['status_id'] = StatusHelper::resolveStatus($u);
                    }
                    return $items;
                }
            );

            (new ApiResponse(true, 'Users retrieved successfully', $responseData))->send(200);
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
            $isApproved = isset($data['is_approved']) ? (bool)$data['is_approved'] : true;
            $isReviewed = isset($data['is_reviewed']) ? (bool)$data['is_reviewed'] : true;

            // Check if email already exists
            $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = :email");
            $checkStmt->execute(['email' => $email]);
            if ($checkStmt->fetch()) {
                (new ApiResponse(false, 'Email already exists'))->send(400);
            }

            $stmt = $pdo->prepare("
                INSERT INTO users (username, role_id, level_id, email, password_hash, affiliator_id, is_approved, is_reviewed, created_at, updated_at)
                VALUES (:username, :role_id, :level_id, :email, :password_hash, :affiliator_id, :is_approved, :is_reviewed, NOW(), NOW())
                RETURNING id, username, role_id, level_id, email, affiliator_id, is_approved, is_reviewed, created_at, updated_at
            ");

            $stmt->bindValue(':username', $username, PDO::PARAM_STR);
            $stmt->bindValue(':role_id', $roleId, PDO::PARAM_INT);
            $stmt->bindValue(':level_id', $levelId, PDO::PARAM_INT);
            $stmt->bindValue(':email', $email, PDO::PARAM_STR);
            $stmt->bindValue(':password_hash', $passwordHash, PDO::PARAM_STR);
            $stmt->bindValue(':affiliator_id', $affiliatorId, $affiliatorId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindValue(':is_approved', $isApproved, PDO::PARAM_BOOL);
            $stmt->bindValue(':is_reviewed', $isReviewed, PDO::PARAM_BOOL);

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
            $isApproved = isset($data['is_approved']) ? (bool)$data['is_approved'] : true;
            $isReviewed = isset($data['is_reviewed']) ? (bool)$data['is_reviewed'] : true;

            $stmt = $pdo->prepare("
                UPDATE users
                SET username = :username,
                    role_id = :role_id,
                    level_id = :level_id,
                    email = :email,
                    password_hash = :password_hash,
                    affiliator_id = :affiliator_id,
                    is_approved = :is_approved,
                    is_reviewed = :is_reviewed,
                    updated_at = NOW()
                WHERE id = :id
                RETURNING id, username, role_id, level_id, email, affiliator_id, is_approved, is_reviewed, created_at, updated_at
            ");

            $stmt->bindValue(':username', $username, PDO::PARAM_STR);
            $stmt->bindValue(':role_id', $roleId, PDO::PARAM_INT);
            $stmt->bindValue(':level_id', $levelId, PDO::PARAM_INT);
            $stmt->bindValue(':email', $email, PDO::PARAM_STR);
            $stmt->bindValue(':password_hash', $passwordHash, PDO::PARAM_STR);
            $stmt->bindValue(':affiliator_id', $affiliatorId, $affiliatorId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindValue(':is_approved', $isApproved, PDO::PARAM_BOOL);
            $stmt->bindValue(':is_reviewed', $isReviewed, PDO::PARAM_BOOL);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);

            $stmt->execute();
            $updatedUser = $stmt->fetch();

            (new ApiResponse(true, 'User updated successfully', $updatedUser))->send(200);
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }

    /**
     * Dedicated function for admin to approve or reject a user.
     */
    public function review_user(): void
    {
        try {
            $pdo = Database::getConnection();
            $data = RequestHelper::getBody();

            $id = $_GET['id'] ?? $data['id'] ?? null;
            $decision = $data['decision'] ?? null;

            if ($id === null || $decision === null) {
                (new ApiResponse(false, 'User ID and decision are required'))->send(400);
                return;
            }

            // Check if user exists
            $stmt = $pdo->prepare("SELECT username FROM users WHERE id = :id");
            $stmt->execute(['id' => $id]);
            $existingUser = $stmt->fetch();
            if (!$existingUser) {
                (new ApiResponse(false, 'User not found'))->send(404);
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
                UPDATE users
                SET is_approved = :is_approved,
                    is_reviewed = :is_reviewed,
                    updated_at = NOW()
                WHERE id = :id
                RETURNING id, username, role_id, level_id, email, affiliator_id, is_approved, is_reviewed, created_at, updated_at
            ");

            $stmt->bindValue(':is_approved', $isApproved, PDO::PARAM_BOOL);
            $stmt->bindValue(':is_reviewed', $isReviewed, PDO::PARAM_BOOL);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);

            $stmt->execute();
            $updatedUser = $stmt->fetch(PDO::FETCH_ASSOC);

            // Resolve friendly status for returning
            $updatedUser['is_posted'] = true;
            $updatedUser['is_revised'] = false;
            $updatedUser['status_id'] = StatusHelper::resolveStatus($updatedUser);

            (new ApiResponse(true, 'User decision saved successfully', $updatedUser))->send(200);
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
     * Register a new user under an affiliator (defaults to is_approved = false, is_reviewed = false).
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
            $isApproved = false; // Must be approved by admin
            $isReviewed = false;

            // Check if email already exists
            $checkStmt = $pdo->prepare("SELECT id FROM users WHERE username = :username AND affiliator_id = :affiliator_id");
            $checkStmt->bindValue(':username', $username, PDO::PARAM_STR);
            $checkStmt->bindValue(':affiliator_id', $affiliatorId, PDO::PARAM_INT);
            $checkStmt->execute();
            if ($checkStmt->fetch()) {
                (new ApiResponse(false, 'Email already exists'))->send(400);
            }

            $stmt = $pdo->prepare("
                INSERT INTO users (username, role_id, level_id, email, password_hash, affiliator_id, is_approved, is_reviewed, created_at, updated_at)
                VALUES (:username, :role_id, :level_id, :email, :password_hash, :affiliator_id, :is_approved, :is_reviewed, NOW(), NOW())
                RETURNING id, username, role_id, level_id, email, affiliator_id, is_approved, is_reviewed, created_at, updated_at
            ");

            $stmt->bindValue(':username', $username, PDO::PARAM_STR);
            $stmt->bindValue(':role_id', ROLE_ID::AFFILIATOR, PDO::PARAM_INT);
            $stmt->bindValue(':level_id', LEVEL_ID::SYSUSER, PDO::PARAM_INT);
            $stmt->bindValue(':email', $email, PDO::PARAM_STR);
            $stmt->bindValue(':password_hash', $passwordHash, PDO::PARAM_STR);
            $stmt->bindValue(':affiliator_id', $affiliatorId, PDO::PARAM_INT);
            $stmt->bindValue(':is_approved', $isApproved, PDO::PARAM_BOOL);
            $stmt->bindValue(':is_reviewed', $isReviewed, PDO::PARAM_BOOL);

            $stmt->execute();
            $newUser = $stmt->fetch();

            (new ApiResponse(true, 'Registration successful, awaiting administrator approval', $newUser))->send(201);
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }

    /**
     * Register a new user as reviewer (defaults to is_approved = false, is_reviewed = false).
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
            $isApproved = false; // Must be approved by admin
            $isReviewed = false;

            // Check if username already exists globally
            $checkStmt = $pdo->prepare("SELECT id FROM users WHERE username = :username");
            $checkStmt->bindValue(':username', $username, PDO::PARAM_STR);
            $checkStmt->execute();
            if ($checkStmt->fetch()) {
                (new ApiResponse(false, 'Username already exists'))->send(400);
            }

            $stmt = $pdo->prepare("
                INSERT INTO users (username, role_id, level_id, email, password_hash, is_approved, is_reviewed, created_at, updated_at)
                VALUES (:username, :role_id, :level_id, :email, :password_hash, :is_approved, :is_reviewed, NOW(), NOW())
                RETURNING id, username, role_id, level_id, email, is_approved, is_reviewed, created_at, updated_at
            ");

            $stmt->bindValue(':username', $username, PDO::PARAM_STR);
            $stmt->bindValue(':role_id', ROLE_ID::REVIEWER, PDO::PARAM_INT);
            $stmt->bindValue(':level_id', LEVEL_ID::SYSUSER, PDO::PARAM_INT); // Level 1 for standard user
            $stmt->bindValue(':email', $email, PDO::PARAM_STR);
            $stmt->bindValue(':password_hash', $passwordHash, PDO::PARAM_STR);
            $stmt->bindValue(':is_approved', $isApproved, PDO::PARAM_BOOL);
            $stmt->bindValue(':is_reviewed', $isReviewed, PDO::PARAM_BOOL);

            $stmt->execute();
            $newUser = $stmt->fetch();

            (new ApiResponse(true, 'Registration successful, awaiting administrator approval', $newUser))->send(201);
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }
}
