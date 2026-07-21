<?php
// app/Models/ApiResponse.php

namespace App\Functions;

use App\Models\ApiResponse;

function post_rq(){
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        (new ApiResponse(false, 'Method Not Allowed. Please use POST.'))->send(405);
    }
    // Get incoming JSON request payload
    $input = json_decode(file_get_contents('php://input'), true);

    return $input;
}
