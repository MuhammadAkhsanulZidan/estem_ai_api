<?php

namespace App\Controllers;

use App\Config\Database;
use App\Models\ApiResponse;
use App\Helpers\RequestHelper;
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
                $stmt = $pdo->query("
                    SELECT id, username, role_id, level_id, email, affiliator_id, reviewer_id, is_active, created_at, updated_at 
                    FROM users 
                    ORDER BY id DESC
                ");
                $users = $stmt->fetchAll();

                (new ApiResponse(true, 'Users retrieved successfully', $users))->send(200);
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
}
