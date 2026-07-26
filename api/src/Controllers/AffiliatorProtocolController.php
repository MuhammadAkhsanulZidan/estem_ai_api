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

                $docStmt = $pdo->prepare("SELECT id, document_path FROM affiliator_protocol_documents WHERE protocol_id = :protocol_id");
                $docStmt->execute(['protocol_id' => $protocol['id']]);
                $protocol['documents'] = $docStmt->fetchAll() ?: [];

                (new ApiResponse(true, 'Protocol retrieved successfully', $protocol))->send(200);
            } else {
                $stmt = $pdo->query("SELECT * FROM affiliator_protocols ORDER BY id DESC");
                $protocols = $stmt->fetchAll();

                foreach ($protocols as &$p) {
                    $docStmt = $pdo->prepare("SELECT id, document_path FROM affiliator_protocol_documents WHERE protocol_id = :protocol_id");
                    $docStmt->execute(['protocol_id' => $p['id']]);
                    $p['documents'] = $docStmt->fetchAll() ?: [];
                }

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
            
            // Support both JSON body and multipart/form-data POST fields
            $data = $_POST;
            if (empty($data)) {
                $data = RequestHelper::getBody();
            }

            $affiliatorId = $data['affiliator_id'] ?? null;
            $protocolName = trim($data['protocol_name'] ?? '');
            $statusId = trim($data['status_id'] ?? '');

            if ($affiliatorId === null || empty($protocolName) || empty($statusId)) {
                (new ApiResponse(false, 'affiliator_id, protocol_name, and status_id are required'))->send(400);
            }

            $indication = $data['indication'] ?? null;
            $protocolVersion = $data['protocol_version'] ?? null;
            $protocolReferenceId = isset($data['protocol_reference_id']) && $data['protocol_reference_id'] !== "" ? (int)$data['protocol_reference_id'] : null;
            $creatorNote = $data['creator_note'] ?? null;
            $reviewerNote = $data['reviewer_note'] ?? null;
            $createdBy = $user['data']['id'] ?? null;

            $stmt = $pdo->prepare("
                INSERT INTO affiliator_protocols (affiliator_id, protocol_reference_id, protocol_name, indication, protocol_version, status_id, creator_note, reviewer_note, create_by, created_at, updated_at)
                VALUES (:affiliator_id, :protocol_reference_id, :protocol_name, :indication, :protocol_version, :status_id, :creator_note, :reviewer_note, :create_by, NOW(), NOW())
                RETURNING *
            ");

            $stmt->bindValue(':affiliator_id', $affiliatorId, PDO::PARAM_INT);
            $stmt->bindValue(':protocol_reference_id', $protocolReferenceId, $protocolReferenceId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindValue(':protocol_name', $protocolName, PDO::PARAM_STR);
            $stmt->bindValue(':indication', $indication, $indication === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':protocol_version', $protocolVersion, $protocolVersion === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':status_id', $statusId, PDO::PARAM_STR);
            $stmt->bindValue(':creator_note', $creatorNote, $creatorNote === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':reviewer_note', $reviewerNote, $reviewerNote === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':create_by', $createdBy, $createdBy === null ? PDO::PARAM_NULL : PDO::PARAM_INT);

            $stmt->execute();
            $newProtocol = $stmt->fetch();

            // Process File Uploads dynamically
            if (!empty($_FILES)) {
                $uploadDir = __DIR__ . '/../../public/bck/affiliator/protocols/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }

                foreach ($_FILES as $key => $file) {
                    if ($file['error'] === UPLOAD_ERR_OK) {
                        $originalName = basename($file['name']);
                        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
                        $filename = pathinfo($originalName, PATHINFO_FILENAME);
                        $randomId = bin2hex(random_bytes(2));
                        $sanitizedName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $filename) . '_' . time() . '_' . $randomId . '.' . $extension;
                        $targetPath = $uploadDir . $sanitizedName;

                        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                            $dbPath = 'public/bck/affiliator/protocols/' . $sanitizedName;

                            $insStmt = $pdo->prepare("
                                INSERT INTO affiliator_protocol_documents (protocol_id, document_path)
                                VALUES (:protocol_id, :document_path)
                            ");
                            $insStmt->execute([
                                'protocol_id' => $newProtocol['id'],
                                'document_path' => $dbPath
                            ]);
                        }
                    }
                }
            }

            // Retrieve newly created protocol with its documents
            $docStmt = $pdo->prepare("SELECT id, document_path FROM affiliator_protocol_documents WHERE protocol_id = :protocol_id");
            $docStmt->execute(['protocol_id' => $newProtocol['id']]);
            $newProtocol['documents'] = $docStmt->fetchAll() ?: [];

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

            // Check if protocol exists and get existing row
            $stmt = $pdo->prepare("SELECT * FROM affiliator_protocols WHERE id = :id");
            $stmt->execute(['id' => $id]);
            $existing = $stmt->fetch();
            if (!$existing) {
                (new ApiResponse(false, 'Protocol not found'))->send(404);
            }

            $affiliatorId = $data['affiliator_id'] ?? $existing['affiliator_id'];
            $protocolName = isset($data['protocol_name']) ? trim($data['protocol_name']) : $existing['protocol_name'];
            $statusId = isset($data['status_id']) ? trim($data['status_id']) : $existing['status_id'];

            if ($affiliatorId === null || empty($protocolName) || empty($statusId)) {
                (new ApiResponse(false, 'affiliator_id, protocol_name, and status_id are required'))->send(400);
            }

            $indication = isset($data['indication']) ? $data['indication'] : $existing['indication'];
            $protocolVersion = isset($data['protocol_version']) ? $data['protocol_version'] : $existing['protocol_version'];
            $protocolReferenceId = isset($data['protocol_reference_id']) && $data['protocol_reference_id'] !== "" ? (int)$data['protocol_reference_id'] : $existing['protocol_reference_id'];
            $creatorNote = isset($data['creator_note']) ? $data['creator_note'] : $existing['creator_note'];
            $reviewerNote = isset($data['reviewer_note']) ? $data['reviewer_note'] : $existing['reviewer_note'];
            $updatedBy = $user['data']['id'] ?? null;

            $stmt = $pdo->prepare("
                UPDATE affiliator_protocols
                SET affiliator_id = :affiliator_id,
                    protocol_reference_id = :protocol_reference_id,
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
            $stmt->bindValue(':protocol_reference_id', $protocolReferenceId, $protocolReferenceId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
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
        $user = AuthMiddleware::authorize(['affiliator', 'admin']);

        try {
            $pdo = Database::getConnection();
            $id = $_GET['id'] ?? null;

            if ($id === null) {
                (new ApiResponse(false, 'Protocol ID is required'))->send(400);
            }

            // Delete associated documents
            $docStmt = $pdo->prepare("SELECT document_path FROM affiliator_protocol_documents WHERE protocol_id = :protocol_id");
            $docStmt->execute(['protocol_id' => $id]);
            $docs = $docStmt->fetchAll();

            $publicDir = __DIR__ . '/../../public/';
            foreach ($docs as $doc) {
                $absPath = realpath($publicDir . $doc['document_path']);
                if ($absPath && file_exists($absPath) && is_file($absPath)) {
                    unlink($absPath);
                }
            }

            $delDocs = $pdo->prepare("DELETE FROM affiliator_protocol_documents WHERE protocol_id = :protocol_id");
            $delDocs->execute(['protocol_id' => $id]);

            $stmt = $pdo->prepare("DELETE FROM affiliator_protocols WHERE id = :id");
            $stmt->execute(['id' => $id]);

            (new ApiResponse(true, 'Protocol and associated documents deleted successfully'))->send(200);
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }

    public function getReviewList(): void
    {
        AuthMiddleware::authorize(['reviewer', 'admin']);
        try {
            $pdo = Database::getConnection();

            $filterValue = $_GET['filter_value'] ?? '';
            $status = $_GET['status'] ?? '';
            $pageNo = isset($_GET['page_no']) ? (int)$_GET['page_no'] : 1;
            $pageRow = isset($_GET['page_row']) ? (int)$_GET['page_row'] : 8;
            $offset = ($pageNo - 1) * $pageRow;

            $whereParts = ["1=1"];
            $params = [];

            if (!empty($filterValue)) {
                $whereParts[] = "(ap.protocol_name ILIKE :filter OR ap.indication ILIKE :filter OR a.name ILIKE :filter)";
                $params['filter'] = "%{$filterValue}%";
            }

            if (!empty($status)) {
                if (in_array($status, ['submitted', 'review'])) {
                    $whereParts[] = "ap.status_id IN ('submitted', 'review')";
                } elseif ($status === 'revision') {
                    $whereParts[] = "ap.status_id = 'revision'";
                } elseif ($status === 'approved') {
                    $whereParts[] = "ap.status_id IN ('approved', 'active')";
                } elseif ($status === 'rejected') {
                    $whereParts[] = "ap.status_id = 'rejected'";
                }
            }

            $whereSql = implode(' AND ', $whereParts);

            $countSql = "
                SELECT COUNT(*) 
                FROM affiliator_protocols ap
                JOIN affiliators a ON ap.affiliator_id = a.id
                WHERE $whereSql
            ";
            $stmtCount = $pdo->prepare($countSql);
            $stmtCount->execute($params);
            $totalItems = $stmtCount->fetchColumn();

            $sql = "
                SELECT 
                    ap.*,
                    a.name as hospital_name
                FROM affiliator_protocols ap
                JOIN affiliators a ON ap.affiliator_id = a.id
                WHERE $whereSql
                ORDER BY ap.created_at DESC
                LIMIT :limit OFFSET :offset
            ";
            
            $stmt = $pdo->prepare($sql);
            foreach ($params as $k => $v) {
                $stmt->bindValue(":$k", $v);
            }
            $stmt->bindValue(':limit', $pageRow, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Fetch documents
            if (!empty($items)) {
                $ids = array_column($items, 'id');
                $inQuery = implode(',', array_fill(0, count($ids), '?'));
                $docStmt = $pdo->prepare("SELECT id, protocol_id, document_path FROM affiliator_protocol_documents WHERE protocol_id IN ($inQuery)");
                $docStmt->execute($ids);
                $docs = $docStmt->fetchAll(PDO::FETCH_ASSOC);
                
                $docsByProtocol = [];
                foreach ($docs as $doc) {
                    $docsByProtocol[$doc['protocol_id']][] = [
                        'id' => $doc['id'],
                        'document_path' => $doc['document_path']
                    ];
                }
                
                foreach ($items as &$item) {
                    $item['documents'] = $docsByProtocol[$item['id']] ?? [];
                }
            }

            (new ApiResponse(true, 'Data fetched successfully', [
                'items' => $items,
                'total_items' => $totalItems,
                'page_no' => $pageNo,
                'page_row' => $pageRow
            ]))->send(200);

        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }

    public function reviewProtocol(): void
    {
        AuthMiddleware::authorize(['reviewer', 'admin']);
        try {
            $data = RequestHelper::getJsonBody();
            
            if (empty($data['id']) || empty($data['decision'])) {
                (new ApiResponse(false, 'Missing required fields: id, decision'))->send(400);
                return;
            }

            $id = $data['id'];
            $decision = $data['decision'];
            $reviewerNote = $data['reviewer_note'] ?? null;

            $statusId = '';
            if ($decision === 'approve') {
                $statusId = 'approved';
            } elseif ($decision === 'revision') {
                $statusId = 'revision';
            } elseif ($decision === 'reject') {
                $statusId = 'rejected';
            } else {
                (new ApiResponse(false, 'Invalid decision'))->send(400);
                return;
            }

            $pdo = Database::getConnection();

            // Check if exists
            $stmt = $pdo->prepare("SELECT id FROM affiliator_protocols WHERE id = :id");
            $stmt->execute(['id' => $id]);
            if (!$stmt->fetch()) {
                (new ApiResponse(false, 'Protocol not found'))->send(404);
                return;
            }

            // Update
            $updateSql = "
                UPDATE affiliator_protocols 
                SET status_id = :status_id, reviewer_note = :reviewer_note 
                WHERE id = :id 
                RETURNING *
            ";
            $updateStmt = $pdo->prepare($updateSql);
            $updateStmt->execute([
                'status_id' => $statusId,
                'reviewer_note' => $reviewerNote,
                'id' => $id
            ]);

            $updatedRow = $updateStmt->fetch(PDO::FETCH_ASSOC);

            (new ApiResponse(true, 'Review decision saved', $updatedRow))->send(200);

        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }
}
