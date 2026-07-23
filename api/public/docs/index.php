<?php

// Serve JSON specification if requested
if (isset($_GET['format']) && $_GET['format'] === 'json') {
    header('Content-Type: application/json');
    echo json_encode(generateOpenApiSpec(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// Otherwise, serve the Swagger UI HTML
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>eStem AI API Documentation</title>
    <link rel="stylesheet" type="text/css" href="https://unpkg.com/swagger-ui-dist@5.11.0/swagger-ui.css" />
    <link rel="icon" type="image/png" href="https://unpkg.com/swagger-ui-dist@5.11.0/favicon-32x32.png" sizes="32x32" />
    <style>
        html {
            box-sizing: border-box;
            overflow: -margin-y;
        }
        *, *:before, *:after {
            box-sizing: inherit;
        }
        body {
            margin: 0;
            background: #fafafa;
        }
        .swagger-ui .topbar {
            background-color: #0d1117;
        }
    </style>
</head>
<body>
    <div id="swagger-ui"></div>
    <script src="https://unpkg.com/swagger-ui-dist@5.11.0/swagger-ui-bundle.js" charset="UTF-8"></script>
    <script src="https://unpkg.com/swagger-ui-dist@5.11.0/swagger-ui-standalone-preset.js" charset="UTF-8"></script>
    <script>
        window.onload = function() {
            window.ui = SwaggerUIBundle({
                url: "?format=json",
                dom_id: '#swagger-ui',
                deepLinking: true,
                presets: [
                    SwaggerUIBundle.presets.apis,
                    SwaggerUIStandalonePreset
                ],
                plugins: [
                    SwaggerUIBundle.plugins.DownloadUrl
                ],
                layout: "BaseLayout"
            });
        };
    </script>
</body>
</html>
<?php

/**
 * Generate OpenAPI Specification by scanning index.php and controllers.
 */
function generateOpenApiSpec(): array
{
    $spec = [
        'openapi' => '3.0.0',
        'info' => [
            'title' => 'eStem AI API Documentation',
            'version' => '1.0.0',
            'description' => 'Dynamically generated API documentation from routes and controllers.'
        ],
        'servers' => [
            ['url' => '..', 'description' => 'API Base URL']
        ],
        'paths' => []
    ];

    $indexPath = __DIR__ . '/../index.php';
    if (!file_exists($indexPath)) {
        return $spec;
    }

    $indexContent = file_get_contents($indexPath);

    // Find route definitions: $router->method('uri', [Controller::class, 'action'])
    $routePattern = '/\$router->(get|post|put|delete|patch)\(\s*\'([^\'\s]+)\'\s*,\s*\[\s*([a-zA-Z0-9_]+)::class\s*,\s*\'([a-zA-Z0-9_]+)\'\s*\]\s*\)/i';
    preg_match_all($routePattern, $indexContent, $matches, PREG_SET_ORDER);

    foreach ($matches as $match) {
        $httpMethod = strtolower($match[1]);
        $routePath = $match[2];
        $controllerName = $match[3];
        $methodName = $match[4];

        // Read controller file to extract PHPDoc and parameters
        $controllerPath = __DIR__ . "/../../src/Controllers/{$controllerName}.php";
        $description = 'No description provided.';
        $parameters = [];
        $requestBody = [];

        if (file_exists($controllerPath)) {
            $controllerContent = file_get_contents($controllerPath);
            
            // Extract the specific method and its preceding DocComment
            $methodPattern = '/\/\*\*(.*?)\*\/\s*(?:public\s+)?function\s+' . $methodName . '\s*\(/s';
            if (preg_match($methodPattern, $controllerContent, $methodMatches)) {
                $docComment = $methodMatches[1];
                
                // Extract description from DocComment
                $lines = explode("\n", $docComment);
                $cleanLines = [];
                foreach ($lines as $line) {
                    $line = trim($line, " \t\r\n*");
                    if ($line !== '' && strpos($line, '@') !== 0) {
                        $cleanLines[] = $line;
                    }
                }
                if (!empty($cleanLines)) {
                    $description = implode(' ', $cleanLines);
                }
            }

            // Extract method body to look for inputs
            $bodyPattern = '/function\s+' . $methodName . '\s*\([^)]*\)\s*\{(.*)/s';
            if (preg_match($bodyPattern, $controllerContent, $bodyMatches)) {
                $bodyRest = $bodyMatches[1];
                
                // Track opening/closing brackets to extract complete method body
                $braces = 1;
                $methodBody = '';
                for ($i = 0; $i < strlen($bodyRest); $i++) {
                    $char = $bodyRest[$i];
                    if ($char === '{') $braces++;
                    if ($char === '}') $braces--;
                    if ($braces === 0) {
                        $methodBody = substr($bodyRest, 0, $i);
                        break;
                    }
                }

                // Detect $_GET parameters
                preg_match_all('/\$_GET\\[\s*\'([a-zA-Z0-9_]+)\'\s*\\]/i', $methodBody, $getMatches);
                if (!empty($getMatches[1])) {
                    foreach (array_unique($getMatches[1]) as $paramName) {
                        $parameters[] = [
                            'name' => $paramName,
                            'in' => 'query',
                            'required' => ($paramName === 'id'), // Typically 'id' is required if queried
                            'schema' => ['type' => ($paramName === 'id') ? 'integer' : 'string']
                        ];
                    }
                }

                // Detect $data['something'] body properties
                preg_match_all('/\s*[$]data\\[\s*\'([a-zA-Z0-9_]+)\'\s*\\]/i', $methodBody, $bodyPropMatches);
                if (!empty($bodyPropMatches[1]) && in_array($httpMethod, ['post', 'put', 'patch'])) {
                    $properties = [];
                    foreach (array_unique($bodyPropMatches[1]) as $propName) {
                        $properties[$propName] = [
                            'type' => (strpos(strtolower($propName), 'id') !== false) ? 'integer' : 'string'
                        ];
                    }
                    $requestBody = [
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => $properties
                                ]
                            ]
                        ]
                    ];
                }
            }
        }

        // Initialize path structure if not set
        if (!isset($spec['paths'][$routePath])) {
            $spec['paths'][$routePath] = [];
        }

        $endpointSpec = [
            'summary' => "{$controllerName} -> {$methodName}",
            'description' => $description,
            'tags' => [$controllerName],
            'responses' => [
                '200' => [
                    'description' => 'Successful operation'
                ]
            ]
        ];

        if (!empty($parameters)) {
            $endpointSpec['parameters'] = $parameters;
        }

        if (!empty($requestBody)) {
            $endpointSpec['requestBody'] = $requestBody;
        }

        $spec['paths'][$routePath][$httpMethod] = $endpointSpec;
    }

    return $spec;
}
