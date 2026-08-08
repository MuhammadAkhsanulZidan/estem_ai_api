<?php

namespace App\Controllers;

use App\Middleware\AuthMiddleware;
use App\Models\ApiResponse;

class GlobalProtocolTemplateController
{
    private $uploadDir;

    public function __construct()
    {
        $this->uploadDir = __DIR__ . '/../../public/uploads/templates/';
    }

    /**
     * Retrieve the current global protocol template if it exists.
     */
    public function get()
    {
        try {
            if (!is_dir($this->uploadDir)) {
                (new ApiResponse(true, 'No template uploaded yet', ['exists' => false]))->send(200);
            }

            $files = array_diff(scandir($this->uploadDir), ['.', '..']);
            if (empty($files)) {
                (new ApiResponse(true, 'No template uploaded yet', ['exists' => false]))->send(200);
            }

            // Get the first file (since there is only 1 template)
            $filename = reset($files);
            $url = 'public/uploads/templates/' . $filename;

            (new ApiResponse(true, 'Global template retrieved successfully', [
                'exists' => true,
                'filename' => $filename,
                'url' => $url
            ]))->send(200);
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }

    /**
     * Upload or replace the single global protocol template.
     */
    public function post()
    {
        AuthMiddleware::authorize(['admin']);

        try {
            if (!is_dir($this->uploadDir)) {
                if (!mkdir($this->uploadDir, 0777, true)) {
                    throw new \Exception("Failed to create template directory: " . $this->uploadDir);
                }
            }

            // Check if file is uploaded
            $file = $_FILES['template'] ?? $_FILES['file'] ?? null;
            if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
                (new ApiResponse(false, 'No valid file uploaded. Make sure parameter is template or file.'))->send(400);
            }

            // Remove existing template files to enforce "just 1 document"
            $existingFiles = array_diff(scandir($this->uploadDir), ['.', '..']);
            foreach ($existingFiles as $f) {
                @unlink($this->uploadDir . $f);
            }

            $originalName = basename($file['name']);
            $extension = pathinfo($originalName, PATHINFO_EXTENSION);
            $filenameOnly = pathinfo($originalName, PATHINFO_FILENAME);
            
            // Sanitize filename but preserve original format
            $sanitizedName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $filenameOnly) . '.' . $extension;
            $targetPath = $this->uploadDir . $sanitizedName;

            if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
                throw new \Exception("Failed to save uploaded template to " . $targetPath);
            }

            (new ApiResponse(true, 'Global protocol template uploaded successfully', [
                'exists' => true,
                'filename' => $sanitizedName,
                'url' => 'public/uploads/templates/' . $sanitizedName
            ]))->send(200);
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }

    /**
     * Delete the global protocol template.
     */
    public function delete()
    {
        AuthMiddleware::authorize(['admin']);

        try {
            if (is_dir($this->uploadDir)) {
                $existingFiles = array_diff(scandir($this->uploadDir), ['.', '..']);
                foreach ($existingFiles as $f) {
                    @unlink($this->uploadDir . $f);
                }
            }
            (new ApiResponse(true, 'Global protocol template deleted successfully'))->send(200);
        } catch (\Throwable $e) {
            (new ApiResponse(false, 'Error: ' . $e->getMessage()))->send(500);
        }
    }
}
