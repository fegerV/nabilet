<?php

declare(strict_types=1);

/**
 * Одноразовый скрипт: убирает `loadRoutesFrom()` из провайдеров модулей.
 *
 * Маршруты модулей подключены централизованно из routes/api.php (внутри группы
 * /api/v1). Провайдер, грузящий их ещё раз, регистрирует вторую копию в корне.
 * Скрипт удалён после прогона — правка разовая, не часть сборки.
 */

$root = getcwd();

$files = [
    'app/Modules/AbTesting/Providers/AbTestingServiceProvider.php',
    'app/Modules/Admin/Providers/AdminServiceProvider.php',
    'app/Modules/Ai/Providers/AiServiceProvider.php',
    'app/Modules/Analytics/Providers/AnalyticsServiceProvider.php',
    'app/Modules/Backups/Providers/BackupsServiceProvider.php',
    'app/Modules/Cart/Providers/CartServiceProvider.php',
    'app/Modules/Checkin/Providers/CheckinServiceProvider.php',
    'app/Modules/Content/Providers/ContentServiceProvider.php',
    'app/Modules/Embed/Providers/EmbedServiceProvider.php',
    'app/Modules/HallSchemas/Providers/HallSchemasServiceProvider.php',
    'app/Modules/Heatmaps/Providers/HeatmapsServiceProvider.php',
    'app/Modules/Inventory/Providers/InventoryServiceProvider.php',
    'app/Modules/Localization/Providers/LocalizationServiceProvider.php',
    'app/Modules/Payments/Providers/PaymentServiceProvider.php',
    'app/Modules/Pricing/Providers/PricingServiceProvider.php',
    'app/Modules/Privacy/Providers/PrivacyServiceProvider.php',
    'app/Modules/Security/Providers/SecurityServiceProvider.php',
    'app/Modules/Sessions/Providers/SessionServiceProvider.php',
    'app/Modules/Telegram/Providers/TelegramServiceProvider.php',
    'app/Modules/Users/Providers/UsersServiceProvider.php',
    'app/Modules/Venues/Providers/VenueServiceProvider.php',
    'app/Modules/Webhooks/Providers/WebhooksServiceProvider.php',
];

$comment = [
    '// Маршруты модуля подключены централизованно в routes/api.php, внутри',
    '// группы /api/v1. Загрузка отсюда зарегистрировала бы их второй раз —',
    '// в корне, без префикса и вне API-группы middleware. См.',
    '// tools/verify-route-ownership.php.',
];

// Комментарии, ставшие неверными после удаления вызова.
$stale = [
    '// Load routes for check-in endpoints',
    '// Load routes for webhook endpoints',
];

$changed = 0;

foreach ($files as $relative) {
    $path = $root . '/' . $relative;

    if (! is_file($path)) {
        echo "MISSING $relative\n";

        continue;
    }

    $lines = explode("\n", (string) file_get_contents($path));
    $out = [];
    $touched = false;

    foreach ($lines as $line) {
        $trimmed = trim($line);

        if (in_array($trimmed, $stale, true)) {
            $touched = true;

            continue;
        }

        if (str_contains($line, '$this->loadRoutesFrom(')) {
            preg_match('#^(\s*)#', $line, $m);
            $indent = $m[1] ?? '        ';

            foreach ($comment as $commentLine) {
                $out[] = $indent . $commentLine;
            }

            $touched = true;

            continue;
        }

        $out[] = $line;
    }

    if ($touched) {
        file_put_contents($path, implode("\n", $out));
        $changed++;
        echo "patched $relative\n";
    } else {
        echo "UNTOUCHED $relative\n";
    }
}

echo "changed=$changed\n";
