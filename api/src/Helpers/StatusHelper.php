<?php
namespace App\Helpers;

class StatusHelper
{
    /**
     * Resolve database boolean flags into a friendly string status.
     */
    public static function resolveStatus(array $row): string
    {
        $isPosted = $row['is_posted'] ?? false;
        $isReviewed = $row['is_reviewed'] ?? false;
        $isRevised = $row['is_revised'] ?? false;
        $isApproved = $row['is_approved'] ?? false;

        if (!$isPosted) {
            return 'draft';
        }
        
        if ($isReviewed) {
            if ($isApproved) {
                return 'approved';
            }
            if ($isRevised) {
                return 'revision';
            }
            return 'rejected';
        }

        return 'submitted';
    }

    /**
     * Map a friendly string status to database boolean flags.
     */
    public static function mapStatusToFlags(string $status): array
    {
        $isPosted = true;
        $isReviewed = true;
        $isRevised = false;
        $isApproved = false;

        switch ($status) {
            case 'draft':
                $isPosted = false;
                $isReviewed = false;
                break;
            case 'submitted':
                $isReviewed = false;
                break;
            case 'revision':
                $isRevised = true;
                break;
            case 'approved':
            case 'active':
                $isApproved = true;
                break;
            case 'rejected':
            case 'review':
                // keeps defaults ($isPosted = true, $isReviewed = true, $isRevised = false, $isApproved = false)
                break;
        }

        return [
            'is_posted' => $isPosted,
            'is_reviewed' => $isReviewed,
            'is_revised' => $isRevised,
            'is_approved' => $isApproved
        ];
    }
}
