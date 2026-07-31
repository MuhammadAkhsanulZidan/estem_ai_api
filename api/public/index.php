<?php

// 1. Dynamic Origin handling
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';

header("Access-Control-Allow-Origin: {$origin}");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Max-Age: 86400"); // Cache preflight for 24h

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit();
}

header('Content-Type: application/json');

// 2. Fatal Error Handler (catches missing files, syntax errors, etc.)
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        echo json_encode([
            'status' => 'fatal_error',
            'message' => $error['message'],
            'file' => $error['file'],
            'line' => $error['line']
        ]);
    }
});

// 3. Autoload & Routing
$autoloadPath = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    http_response_code(500);
    echo json_encode(['error' => "Autoload file not found at: {$autoloadPath}"]);
    exit();
}

require_once $autoloadPath;

use App\Core\Router;
use App\Controllers\AuthController;
use App\Controllers\AdminProtocolController;
use App\Controllers\UserController;
use App\Controllers\AffiliatorController;
use App\Controllers\AffiliatorProtocolController;
use App\Controllers\EcrfSectionController;
use App\Controllers\AdminProtocolDocumentController;
use App\Controllers\AffiliatorSupervisionController;
use App\Controllers\PatientEcrfController;
use App\Controllers\PatientController;
use App\Controllers\AffiliatorProfileController;
use App\Controllers\AdverseEventController;
use App\Controllers\ChatbotController;
use App\Controllers\AffiliatorSummaryController;

try {
    $router = new Router();
    $router->post('/v1/login', [AuthController::class, 'login']);
    $router->post('/v1/register-affiliator', [UserController::class, 'register_affiliator']);
    $router->post('/v1/register-reviewer', [UserController::class, 'register_reviewer']);
    $router->get('/v1/admin-protocols', [AdminProtocolController::class, 'get']);
    $router->post('/v1/admin-protocols', [AdminProtocolController::class, 'post']);
    $router->post('/v1/admin-protocols/update', [AdminProtocolController::class, 'put']);
    $router->put('/v1/admin-protocols', [AdminProtocolController::class, 'put']);
    $router->delete('/v1/admin-protocols', [AdminProtocolController::class, 'delete']);

    $router->get('/v1/admin-protocols/ecrf', [AdminProtocolController::class, 'getEcrf']);
    $router->post('/v1/admin-protocols/ecrf', [AdminProtocolController::class, 'postEcrf']);

    $router->get('/v1/users', [UserController::class, 'get']);
    $router->post('/v1/users', [UserController::class, 'post']);
    $router->put('/v1/users', [UserController::class, 'put']);
    $router->post('/v1/users/review', [UserController::class, 'review_user']);
    $router->delete('/v1/users', [UserController::class, 'delete']);

    $router->get('/v1/affiliators', [AffiliatorController::class, 'get']);
    $router->post('/v1/affiliators', [AffiliatorController::class, 'post']);
    $router->put('/v1/affiliators', [AffiliatorController::class, 'put']);
    $router->post('/v1/affiliators/review', [AffiliatorController::class, 'review_affiliator']);
    $router->delete('/v1/affiliators', [AffiliatorController::class, 'delete']);

    $router->get('/v1/affiliator/profile', [AffiliatorProfileController::class, 'get']);
    $router->put('/v1/affiliator/profile', [AffiliatorProfileController::class, 'put']);
    $router->get('/v1/affiliator/profile/documents', [AffiliatorProfileController::class, 'getDocuments']);
    $router->post('/v1/affiliator/profile/documents', [AffiliatorProfileController::class, 'postDocument']);
    $router->delete('/v1/affiliator/profile/documents', [AffiliatorProfileController::class, 'deleteDocument']);

    $router->get('/v1/affiliator-protocols', [AffiliatorProtocolController::class, 'get']);
    $router->post('/v1/affiliator-protocols', [AffiliatorProtocolController::class, 'post']);
    $router->post('/v1/affiliator-protocols/update', [AffiliatorProtocolController::class, 'put']);
    $router->put('/v1/affiliator-protocols', [AffiliatorProtocolController::class, 'put']);
    $router->delete('/v1/affiliator-protocols', [AffiliatorProtocolController::class, 'delete']);
    $router->get('/v1/reviewer/affiliator-protocols', [AffiliatorProtocolController::class, 'getReviewList']);
    $router->post('/v1/reviewer/affiliator-protocols/review', [AffiliatorProtocolController::class, 'reviewProtocol']);
    $router->get('/v1/affiliator-supervisions', [AffiliatorSupervisionController::class, 'get']);
    $router->post('/v1/affiliator-supervisions', [AffiliatorSupervisionController::class, 'post']);
    $router->delete('/v1/affiliator-supervisions/documents', [AffiliatorSupervisionController::class, 'deleteDocument']);
    $router->post('/v1/affiliator-supervisions/review', [AffiliatorSupervisionController::class, 'review']);
    $router->get('/v1/patient-ecrf-responses', [PatientEcrfController::class, 'get']);
    $router->post('/v1/patient-ecrf-responses', [PatientEcrfController::class, 'post']);
    $router->get('/v1/reviewer/patient-ecrfs', [PatientEcrfController::class, 'getReviewList']);
    $router->post('/v1/reviewer/patient-ecrfs/review', [PatientEcrfController::class, 'postReview']);
    $router->get('/v1/patients', [PatientController::class, 'get']);
    $router->post('/v1/patients', [PatientController::class, 'post']);
    $router->put('/v1/patients', [PatientController::class, 'put']);
    $router->get('/v1/patients/next-registration-number', [PatientController::class, 'getNextRegistrationNumber']);

    $router->get('/v1/adverse-events/stats', [AdverseEventController::class, 'stats']);
    $router->get('/v1/adverse-events', [AdverseEventController::class, 'get']);
    $router->post('/v1/adverse-events', [AdverseEventController::class, 'post']);
    $router->put('/v1/adverse-events', [AdverseEventController::class, 'put']);
    $router->delete('/v1/adverse-events', [AdverseEventController::class, 'delete']);
    $router->get('/v1/affiliator-summary', [AffiliatorSummaryController::class, 'statusPengajuan']);
    $router->post('/v1/adverse-events/review', [AdverseEventController::class, 'review']);

        $router->get('/v1/ecrf_sections', [EcrfSectionController::class, 'get']);
    $router->post('/v1/ecrf_sections', [EcrfSectionController::class, 'post']);
    $router->put('/v1/ecrf_sections', [EcrfSectionController::class, 'put']);
    $router->delete('/v1/ecrf_sections', [EcrfSectionController::class, 'delete']);

    $router->get('/v1/admin_protocol_documents', [AdminProtocolDocumentController::class, 'get']);
    $router->post('/v1/admin_protocol_documents', [AdminProtocolDocumentController::class, 'post']);
    $router->put('/v1/admin_protocol_documents', [AdminProtocolDocumentController::class, 'put']);
    $router->delete('/v1/admin_protocol_documents', [AdminProtocolDocumentController::class, 'delete']);

    $router->post('/v1/chat', [ChatbotController::class, 'chat']);

// Parse path to remove query parameters
    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $router->dispatch($uri, $_SERVER['REQUEST_METHOD']);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'exception',
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}
