<?php

namespace App\Controllers;

use App\Config\Database;
use App\Middleware\AuthMiddleware;
use App\Models\ApiResponse;
use App\Helpers\RequestHelper;
use PDO;

class AffiliatorSummaryController
{
    public function statusPengajuan(){
        try {
            $user = AuthMiddleware::authorize(['affiliator']);
            $pdo = Database::getConnection();

            $params = [];
            $statusConditions = [];

            $affiliatorId   = $_GET['affiliator_id'];
            $isPosted   = $_GET['is_posted'] ?? "";
            $isRevised  = $_GET['is_revised'] ?? "";
            $isReviewed = $_GET['is_reviewed'] ?? "";
            $isApproved = $_GET['is_approved'] ?? "";

            // Status Filters
            $statusConditions[] = "affiliator_id = :affiliator_id";
            $params['affiliator_id'] = $affiliatorId;

            if ($isPosted !== ""){
                $statusConditions[] = "is_posted = :is_posted";
                $params['is_posted'] = ($isPosted === "1" || $isPosted === "true") ? 'true' : 'false';
            }
            if ($isReviewed !== ""){
                $statusConditions[] = "is_reviewed = :is_reviewed";
                $params['is_reviewed'] = ($isReviewed === "1" || $isReviewed === "true") ? 'true' : 'false';
            }
            if ($isRevised !== ""){
                $statusConditions[] = "is_revised = :is_revised";
                $params['is_revised'] = ($isRevised === "1" || $isRevised === "true") ? 'true' : 'false';
            }
            if ($isApproved !== ""){
                $statusConditions[] = "is_approved = :is_approved";
                $params['is_approved'] = ($isApproved === "1" || $isApproved === "true") ? 'true' : 'false';
            }

            $statusWhere = "";
            if (!empty($statusConditions)) {
                $statusWhere = "WHERE " . implode(" AND ", $statusConditions);
            }

            // Base query with status filtering inside the inner query
            $query = "
                SELECT 'Protokol' as type,
                protocol_name as title,
                create_date as date,
                is_posted,
                is_reviewed,
                is_revised,
                is_approved
                FROM affiliator_protocols
                UNION
                SELECT 'eCRF Pasien' as type,
                protocol_id as title,
                create_date as date,
                is_posted,
                is_reviewed,
                is_revised,
                is_approved
                FROM patient_ecrfs
                UNION
                SELECT 'Pengampuan' as type,
                '' as title,
                create_date as date,
                is_posted,
                is_reviewed,
                is_revised,
                is_approved
                FROM affiliator_supervision
                {$statusWhere}
            ";

            // Dynamic table expression for pagination counting
            $tableName = "";

            $responseData = RequestHelper::paginate(
                pdo: $pdo,
                query: $query,
                tableName: $tableName,
                params: $params,
                filterFields: ['protocol_name', 'affiliator_name'],
                mutateItems: function ($items) {
                    foreach ($items as &$p) {
                        $p['documents'] = json_decode($p['documents'] ?? '[]', true);
                        $p['status_id'] = StatusHelper::resolveStatus($p);
                    }
                    return $items;
                }
            );

            (new ApiResponse(true, 'Protocols retrieved successfully', $responseData))->send(200);

        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }
}
