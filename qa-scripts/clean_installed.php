<?php
// Удаляет filament/livewire/danharrin из vendor/composer/installed.php
// (пакеты лежат под ключом 'versions', проиндексированы по имени пакета).
$data = require __DIR__ . '/../vendor/composer/installed.php';

$drop = static function (string $n): bool {
    $l = strtolower($n);
    return str_starts_with($n, 'filament/')
        || str_starts_with($n, 'livewire/')
        || str_contains($l, 'livewire');
};

$count = 0;
if (isset($data['versions']) && is_array($data['versions'])) {
    foreach (array_keys($data['versions']) as $name) {
        if ($drop($name)) {
            unset($data['versions'][$name]);
            $count++;
        }
    }
}

$export = var_export($data, true);
file_put_contents(
    __DIR__ . '/../vendor/composer/installed.php',
    "<?php\n\nreturn " . $export . ";\n"
);

echo "removed {$count} packages from installed.php\n";
