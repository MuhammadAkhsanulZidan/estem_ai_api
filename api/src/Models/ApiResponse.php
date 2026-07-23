<?php
// app/Models/ApiResponse.php

namespace App\Models;

class ApiResponse
{
    public function __construct(
        public bool $success,
        public string $message,
        public mixed $data = null
    ) {}

    /**
     * Send the standardized JSON response and terminate execution.
     */
    public function send(int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');

        echo json_encode([
            'success' => $this->success,
            'message' => $this->message,
            'data'    => $this->data
        ]);

        exit;
    }
}
