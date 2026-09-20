<?php
$migrationsPath = __DIR__ . '/../database/migrations';
$tables = [];

$files = glob($migrationsPath . '/*.php');
foreach ($files as $file) {
    $content = file_get_contents($file);
    
    // Match Schema::create calls with multi-line content
    if (preg_match_all('/Schema::create\([\'"]([^\'"]+)[\'"]/', $content, $tableMatches)) {
        foreach ($tableMatches[1] as $tableName) {
            $columns = [];
            // Extract column names from $table->type('name') patterns
            if (preg_match_all('/\$table->[a-z]+\([\'"]([^\'"]+)[\'"]/', $content, $colMatches)) {
                $columns = array_unique($colMatches[1]);
            }
            $tables[$tableName] = $columns;
        }
    }
}

echo json_encode($tables, JSON_PRETTY_PRINT);
