<?php

declare(strict_types=1);

$sql = file_get_contents(getcwd() . '/migrations.sql');

$tables = [
    'events', 'sessions', 'orders', 'carts', 'cart_items',
    'inventory_items', 'payments', 'hall_schema_versions',
    'api_keys', 'embed_domains', 'halls', 'venues',
];

foreach ($tables as $table) {
    $pattern = '#CREATE TABLE IF NOT EXISTS ' . preg_quote($table, '#') . ' \((.*?)\n\) ENGINE#s';

    if (! preg_match($pattern, (string) $sql, $m)) {
        printf("%-22s TABLE NOT FOUND\n", $table);

        continue;
    }

    $body = $m[1];
    $hasOrg = str_contains($body, 'organization_id');
    $cols = [];

    foreach (explode("\n", $body) as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, 'PRIMARY') || str_starts_with($line, 'UNIQUE') || str_starts_with($line, 'KEY') || str_starts_with($line, 'CONSTRAINT') || str_starts_with($line, 'INDEX')) {
            continue;
        }

        if (preg_match('#^([a-z_]+) #', $line, $c)) {
            $cols[] = $c[1];
        }
    }

    printf("%-22s org=%-3s cols: %s\n", $table, $hasOrg ? 'YES' : 'NO', implode(',', $cols));
}
