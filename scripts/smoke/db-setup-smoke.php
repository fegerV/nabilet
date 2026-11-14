<?php

declare(strict_types=1);

// Одноразовая подготовка чистой БД и юзера для smoke-прогона инсталлятора.
// mysql.exe клиент недоступен (WSAEACCES), поэтому всё через PDO.

$pdo = new PDO('mysql:host=127.0.0.1;port=3307', 'root', 'rootpass', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$pdo->exec('DROP DATABASE IF EXISTS install_smoke');
$pdo->exec('CREATE DATABASE install_smoke CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

try {
    $pdo->exec("DROP USER 'installer'@'%'");
    echo "existing user dropped\n";
} catch (PDOException) {
    // юзера не было — норма
}

$pdo->exec("CREATE USER 'installer'@'%' IDENTIFIED BY 'installerpass'");
$pdo->exec("GRANT ALL PRIVILEGES ON install_smoke.* TO 'installer'@'%'");
$pdo->exec('FLUSH PRIVILEGES');

echo "OK: DB install_smoke + user installer@% ready\n";
