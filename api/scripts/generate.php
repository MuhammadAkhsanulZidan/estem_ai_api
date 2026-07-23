<?php

/**
 * SQL Schema to REST API Controller Generator
 * Usage: php generate.php <table_name_or_sql_path>
 */

if ($argc < 2) {
    echo "Usage: php generate.php <table_name_or_sql_path>\n";
    exit(1);
}

$sqlArg = $argv[1];
// Strip .sql extension if present to standardize
$sqlName = preg_replace('/\.sql$/i', '', $sqlArg);

// Search paths for the SQL file
$searchPaths = [
    __DIR__ . '/../../sql/tables/' . $sqlName . '.sql',
    __DIR__ . '/../../sql/' . $sqlName . '.sql',
    __DIR__ . '/../sql/tables/' . $sqlName . '.sql',
    __DIR__ . '/../sql/' . $sqlName . '.sql',
    $sqlArg // fallback to exact path passed
];

$sqlFile = null;
foreach ($searchPaths as $path) {
    if (file_exists($path)) {
        $sqlFile = $path;
        break;
    }
}

if (!$sqlFile) {
    echo "Error: SQL file for '{$sqlArg}' not found.\n";
    echo "Searched in:\n";
    foreach ($searchPaths as $path) {
        echo "  - {$path}\n";
    }
    exit(1);
}

$sqlContent = file_get_contents($sqlFile);

// Parse table name
if (!preg_match('/CREATE\s+TABLE\s+([a-zA-Z0-9_]+)/i', $sqlContent, $tableMatch)) {
    echo "Error: Could not parse CREATE TABLE name from '{$sqlFile}'.\n";
    exit(1);
}

$tableName = $tableMatch[1];
$className = str_replace(' ', '', ucwords(str_replace('_', ' ', rtrim($tableName, 's')))); // singular class name
$controllerName = $className . 'Controller';

// Parse column definitions
preg_match('/CREATE\s+TABLE\s+[a-zA-Z0-9_]+\s*\((.*)\)/is', $sqlContent, $bodyMatch);
if (empty($bodyMatch[1])) {
    echo "Error: Could not parse table body.\n";
    exit(1);
}

$columnsBody = $bodyMatch[1];
$lines = explode(',', $columnsBody);
$columns = [];

foreach ($lines as $line) {
    $line = trim($line);
    if (empty($line) || preg_match('/^(PRIMARY KEY|CONSTRAINT|UNIQUE|FOREIGN KEY|CHECK|DROP)/i', $line)) {
        continue;
    }
    
    // Extract column name and type
    $parts = preg_split('/\s+/', $line);
    if (count($parts) < 2) {
        continue;
    }
    
    $colName = trim($parts[0], '"` ');
    $colType = strtoupper($parts[1]);
    
    $isRequired = (str_contains(strtoupper($line), 'NOT NULL') && !str_contains(strtoupper($line), 'DEFAULT'));
    
    $columns[$colName] = [
        'type' => $colType,
        'required' => $isRequired,
        'line' => $line
    ];
}

// Generate controller code
$code = "<?php\n\n";
$code .= "namespace App\Controllers;\n\n";
$code .= "use App\Config\Database;\n";
$code .= "use App\Middleware\AuthMiddleware;\n";
$code .= "use App\Models\ApiResponse;\n";
$code .= "use App\Helpers\RequestHelper;\n";
$code .= "use PDO;\n\n";
$code .= "class {$controllerName}\n{\n";

// GET METHOD
$code .= "    /**\n";
$code .= "     * Retrieve {$tableName} (all or single by ID).\n";
$code .= "     */\n";
$code .= "    public function get()\n";
$code .= "    {\n";
$code .= "        try {\n";
$code .= "            \$pdo = Database::getConnection();\n";
$code .= "            \$id = \$_GET['id'] ?? null;\n\n";
$code .= "            if (\$id !== null) {\n";
$code .= "                \$stmt = \$pdo->prepare(\"SELECT * FROM {$tableName} WHERE id = :id\");\n";
$code .= "                \$stmt->execute(['id' => \$id]);\n";
$code .= "                \$item = \$stmt->fetch();\n\n";
$code .= "                if (!\$item) {\n";
$code .= "                    (new ApiResponse(false, 'Record not found'))->send(404);\n";
$code .= "                }\n\n";
$code .= "                (new ApiResponse(true, 'Record retrieved successfully', \$item))->send(200);\n";
$code .= "            } else {\n";
$code .= "                \$stmt = \$pdo->query(\"SELECT * FROM {$tableName} ORDER BY id DESC\");\n";
$code .= "                \$items = \$stmt->fetchAll();\n\n";
$code .= "                (new ApiResponse(true, 'Records retrieved successfully', \$items))->send(200);\n";
$code .= "            }\n";
$code .= "        } catch (\\Throwable \$e) {\n";
$code .= "            (new ApiResponse(false, 'Error: ' . \$e->getMessage()))->send(500);\n";
$code .= "        }\n";
$code .= "    }\n\n";

// POST METHOD
$code .= "    /**\n";
$code .= "     * Create a new {$tableName} record.\n";
$code .= "     */\n";
$code .= "    public function post()\n";
$code .= "    {\n";
$code .= "        // AuthMiddleware::authorize();\n\n";
$code .= "        try {\n";
$code .= "            \$pdo = Database::getConnection();\n";
$code .= "            \$data = RequestHelper::getBody();\n\n";

$requiredFields = [];
foreach ($columns as $name => $meta) {
    if ($name === 'id' || $name === 'created_at' || $name === 'updated_at') {
        continue;
    }
    if ($meta['required']) {
        $requiredFields[] = "'$name'";
    }
}

if (!empty($requiredFields)) {
    $code .= "            // Validate required fields\n";
    $code .= "            if (";
    $checks = [];
    foreach ($columns as $name => $meta) {
        if ($name === 'id' || $name === 'created_at' || $name === 'updated_at') {
            continue;
        }
        if ($meta['required']) {
            if (str_contains($meta['type'], 'INT') || str_contains($meta['type'], 'NUMERIC') || str_contains($meta['type'], 'BOOLEAN')) {
                $checks[] = "!isset(\$data['$name'])";
            } else {
                $checks[] = "empty(trim(\$data['$name'] ?? ''))";
            }
        }
    }
    $code .= implode(" || ", $checks);
    $code .= ") {\n";
    $code .= "                (new ApiResponse(false, 'Required fields: " . implode(', ', array_map(fn($f) => trim($f, "'"), $requiredFields)) . "'))->send(400);\n";
    $code .= "            }\n\n";
}

$insertFields = [];
$insertPlaceholders = [];
foreach ($columns as $name => $meta) {
    if ($name === 'id' || $name === 'created_at' || $name === 'updated_at') {
        continue;
    }
    $insertFields[] = $name;
    $insertPlaceholders[] = ":$name";
}

$code .= "            \$stmt = \$pdo->prepare(\"\n";
$code .= "                INSERT INTO {$tableName} (" . implode(', ', $insertFields) . ", created_at, updated_at)\n";
$code .= "                VALUES (" . implode(', ', $insertPlaceholders) . ", NOW(), NOW())\n";
$code .= "                RETURNING *\n";
$code .= "            \");\n\n";

foreach ($columns as $name => $meta) {
    if ($name === 'id' || $name === 'created_at' || $name === 'updated_at') {
        continue;
    }
    
    $pdoType = "PDO::PARAM_STR";
    if (str_contains($meta['type'], 'INT') || str_contains($meta['type'], 'BIGINT')) {
        $pdoType = "PDO::PARAM_INT";
    } elseif (str_contains($meta['type'], 'BOOLEAN') || str_contains($meta['type'], 'BOOL')) {
        $pdoType = "PDO::PARAM_BOOL";
    }
    
    if ($meta['required']) {
        $code .= "            \$stmt->bindValue(':$name', \$data['$name'], $pdoType);\n";
    } else {
        $code .= "            \$stmt->bindValue(':$name', \$data['$name'] ?? null, \$data['$name'] === null ? PDO::PARAM_NULL : \$pdoType);\n";
    }
}

$code .= "\n            \$stmt->execute();\n";
$code .= "            \$newItem = \$stmt->fetch();\n\n";
$code .= "            (new ApiResponse(true, 'Record created successfully', \$newItem))->send(201);\n";
$code .= "        } catch (\\Throwable \$e) {\n";
$code .= "            (new ApiResponse(false, 'Error: ' . \$e->getMessage()))->send(500);\n";
$code .= "        }\n";
$code .= "    }\n\n";

// PUT METHOD
$code .= "    /**\n";
$code .= "     * Update an existing {$tableName} record.\n";
$code .= "     */\n";
$code .= "    public function put()\n";
$code .= "    {\n";
$code .= "        try {\n";
$code .= "            \$pdo = Database::getConnection();\n";
$code .= "            \$data = RequestHelper::getBody();\n\n";
$code .= "            \$id = \$_GET['id'] ?? \$data['id'] ?? null;\n";
$code .= "            if (\$id === null) {\n";
$code .= "                (new ApiResponse(false, 'ID is required'))->send(400);\n";
$code .= "            }\n\n";
$code .= "            // Check if record exists\n";
$code .= "            \$checkStmt = \$pdo->prepare(\"SELECT id FROM {$tableName} WHERE id = :id\");\n";
$code .= "            \$checkStmt->execute(['id' => \$id]);\n";
$code .= "            if (!\$checkStmt->fetch()) {\n";
$code .= "                (new ApiResponse(false, 'Record not found'))->send(404);\n";
$code .= "            }\n\n";

if (!empty($requiredFields)) {
    $code .= "            // Validate required fields\n";
    $code .= "            if (";
    $checks = [];
    foreach ($columns as $name => $meta) {
        if ($name === 'id' || $name === 'created_at' || $name === 'updated_at') {
            continue;
        }
        if ($meta['required']) {
            if (str_contains($meta['type'], 'INT') || str_contains($meta['type'], 'NUMERIC') || str_contains($meta['type'], 'BOOLEAN')) {
                $checks[] = "!isset(\$data['$name'])";
            } else {
                $checks[] = "empty(trim(\$data['$name'] ?? ''))";
            }
        }
    }
    $code .= implode(" || ", $checks);
    $code .= ") {\n";
    $code .= "                (new ApiResponse(false, 'Required fields: " . implode(', ', array_map(fn($f) => trim($f, "'"), $requiredFields)) . "'))->send(400);\n";
    $code .= "            }\n\n";
}

$updateSets = [];
foreach ($columns as $name => $meta) {
    if ($name === 'id' || $name === 'created_at' || $name === 'updated_at') {
        continue;
    }
    $updateSets[] = "$name = :$name";
}

$code .= "            \$stmt = \$pdo->prepare(\"\n";
$code .= "                UPDATE {$tableName}\n";
$code .= "                SET " . implode(",\n                    ", $updateSets) . ",\n";
$code .= "                    updated_at = NOW()\n";
$code .= "                WHERE id = :id\n";
$code .= "                RETURNING *\n";
$code .= "            \");\n\n";

foreach ($columns as $name => $meta) {
    if ($name === 'id' || $name === 'created_at' || $name === 'updated_at') {
        continue;
    }
    
    $pdoType = "PDO::PARAM_STR";
    if (str_contains($meta['type'], 'INT') || str_contains($meta['type'], 'BIGINT')) {
        $pdoType = "PDO::PARAM_INT";
    } elseif (str_contains($meta['type'], 'BOOLEAN') || str_contains($meta['type'], 'BOOL')) {
        $pdoType = "PDO::PARAM_BOOL";
    }
    
    if ($meta['required']) {
        $code .= "            \$stmt->bindValue(':$name', \$data['$name'], $pdoType);\n";
    } else {
        $code .= "            \$stmt->bindValue(':$name', \$data['$name'] ?? null, \$data['$name'] === null ? PDO::PARAM_NULL : \$pdoType);\n";
    }
}
$code .= "            \$stmt->bindValue(':id', \$id, PDO::PARAM_INT);\n";

$code .= "\n            \$stmt->execute();\n";
$code .= "            \$updatedItem = \$stmt->fetch();\n\n";
$code .= "            (new ApiResponse(true, 'Record updated successfully', \$updatedItem))->send(200);\n";
$code .= "        } catch (\\Throwable \$e) {\n";
$code .= "            (new ApiResponse(false, 'Error: ' . \$e->getMessage()))->send(500);\n";
$code .= "        }\n";
$code .= "    }\n\n";

// DELETE METHOD
$code .= "    /**\n";
$code .= "     * Delete a {$tableName} record.\n";
$code .= "     */\n";
$code .= "    public function delete()\n";
$code .= "    {\n";
$code .= "        try {\n";
$code .= "            \$pdo = Database::getConnection();\n";
$code .= "            \$id = \$_GET['id'] ?? null;\n\n";
$code .= "            if (\$id === null) {\n";
$code .= "                \$data = RequestHelper::getBody();\n";
$code .= "                \$id = \$data['id'] ?? null;\n";
$code .= "            }\n\n";
$code .= "            if (\$id === null) {\n";
$code .= "                (new ApiResponse(false, 'ID is required'))->send(400);\n";
$code .= "            }\n\n";
$code .= "            // Check if record exists\n";
$code .= "            \$checkStmt = \$pdo->prepare(\"SELECT id FROM {$tableName} WHERE id = :id\");\n";
$code .= "            \$checkStmt->execute(['id' => \$id]);\n";
$code .= "            if (!\$checkStmt->fetch()) {\n";
$code .= "                (new ApiResponse(false, 'Record not found'))->send(404);\n";
$code .= "            }\n\n";
$code .= "            \$stmt = \$pdo->prepare(\"DELETE FROM {$tableName} WHERE id = :id\");\n";
$code .= "            \$stmt->execute(['id' => \$id]);\n\n";
$code .= "            (new ApiResponse(true, 'Record deleted successfully'))->send(200);\n";
$code .= "        } catch (\\Throwable \$e) {\n";
$code .= "            (new ApiResponse(false, 'Error: ' . \$e->getMessage()))->send(500);\n";
$code .= "        }\n";
$code .= "    }\n";

$code .= "}\n";

// Target path (relative to api/scripts/ -> api/src/Controllers/)
$targetPath = __DIR__ . "/../src/Controllers/{$controllerName}.php";
$dir = dirname($targetPath);
if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
}

if (file_put_contents($targetPath, $code) !== false) {
    echo "Successfully generated controller '{$controllerName}' at '{$targetPath}'\n";
    
    // Automatically register the routes in public/index.php
    $indexPath = __DIR__ . '/../public/index.php';
    if (file_exists($indexPath)) {
        $indexContent = file_get_contents($indexPath);
        
        // 1. Add Use Import if not present
        $useStatement = "use App\Controllers\\{$controllerName};";
        if (!str_contains($indexContent, $useStatement)) {
            // Find the last use App\Controllers statement to append after
            if (preg_match_all('/use App\\\Controllers\\\[a-zA-Z0-9_]+;/i', $indexContent, $useMatches, PREG_OFFSET_CAPTURE)) {
                $lastMatch = end($useMatches[0]);
                $insertPos = $lastMatch[1] + strlen($lastMatch[0]);
                $indexContent = substr_replace($indexContent, "\n" . $useStatement, $insertPos, 0);
            }
        }
        
        // 2. Add Router Definitions if not present
        $routeCheck = "[{$controllerName}::class,";
        if (!str_contains($indexContent, $routeCheck)) {
            // Find the route dispatch section to append before
            $dispatchPattern = '/\/\/ Parse path to remove query parameters/i';
            if (preg_match($dispatchPattern, $indexContent, $dispatchMatches, PREG_OFFSET_CAPTURE)) {
                $insertPos = $dispatchMatches[0][1];
                
                $routeCode = "    \$router->get('/v1/{$tableName}', [{$controllerName}::class, 'get']);\n";
                $routeCode .= "    \$router->post('/v1/{$tableName}', [{$controllerName}::class, 'post']);\n";
                $routeCode .= "    \$router->put('/v1/{$tableName}', [{$controllerName}::class, 'put']);\n";
                $routeCode .= "    \$router->delete('/v1/{$tableName}', [{$controllerName}::class, 'delete']);\n\n";
                
                $indexContent = substr_replace($indexContent, $routeCode, $insertPos, 0);
            }
        }
        
        file_put_contents($indexPath, $indexContent);
        echo "Automatically registered routes in public/index.php\n";
    } else {
        echo "Warning: public/index.php not found. Did not automatically register routes.\n";
    }
} else {
    echo "Error: Failed to write file to '{$targetPath}'\n";
}
