<?php

declare(strict_types=1);

/**
 * Перевести сеанс на другую опубликованную версию схемы зала и пересобрать
 * инвентарь.
 *
 * Штатный PATCH /sessions не пересобирает инвентарь при смене
 * `schema_version_id`, а SessionSeatingPolicy::canRebind вообще запрещает
 * смену, пока в сеансе есть инвентарь: строки инвентаря ссылаются на места
 * СТАРОЙ версии, и после смены они оказались бы вне карты зала. Поэтому
 * пересборка делается здесь явно и только когда продаж нет.
 *
 * Запуск: php .shots/rebind-session.php <sessionId> <schemaVersionId> [--apply]
 * Без --apply — только проверки и план.
 */
$argv = $argv ?? [];
if (count($argv) < 3) {
    fwrite(STDERR, "usage: php rebind-session.php <sessionId> <schemaVersionId> [--apply]\n");
    exit(1);
}
$sessionId = (int) $argv[1];
$versionId = (int) $argv[2];
$apply = in_array('--apply', $argv, true);

require dirname(__DIR__) . '/vendor/autoload.php';
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Nabilet\Modules\Inventory\Services\InventoryService;
use Nabilet\Modules\Sessions\Models\Session;
use Nabilet\Modules\Venues\Models\HallSchemaVersion;

$session = Session::query()->find($sessionId);
if ($session === null) {
    fwrite(STDERR, "сеанс {$sessionId} не найден\n");
    exit(1);
}
$version = HallSchemaVersion::query()->find($versionId);
if ($version === null) {
    fwrite(STDERR, "версия схемы {$versionId} не найдена\n");
    exit(1);
}

$orders = (int) DB::table('order_items')
    ->join('inventory_items', 'inventory_items.id', '=', 'order_items.inventory_item_id')
    ->where('inventory_items.session_id', $sessionId)
    ->count();
$holds = (int) DB::table('seat_holds')->where('session_id', $sessionId)->count();
$inventory = (int) DB::table('inventory_items')->where('session_id', $sessionId)->count();

echo "сеанс {$sessionId}: зал {$session->hall_id}, схема сейчас {$session->schema_version_id}, инвентарь {$inventory}\n";
echo "цель: версия {$versionId} (v{$version->version}, {$version->status}), зал {$version->hall_id}\n";
echo "заказов {$orders}, холдов {$holds}\n";

if ((int) $version->hall_id !== (int) $session->hall_id) {
    fwrite(STDERR, "версия принадлежит другому залу — отказ\n");
    exit(1);
}
if ($version->status === 'draft') {
    fwrite(STDERR, "версия в статусе draft — продавать с неё нельзя\n");
    exit(1);
}
if ($orders > 0) {
    fwrite(STDERR, "в сеансе есть продажи ({$orders} позиций) — пересборка зала уничтожила бы билеты, отказ\n");
    exit(1);
}
if ($holds > 0) {
    fwrite(STDERR, "в сеансе есть активные холды ({$holds}) — отказ\n");
    exit(1);
}

if (!$apply) {
    echo "проверки пройдены; запустите с --apply, чтобы пересобрать\n";
    exit(0);
}

DB::transaction(static function () use ($session, $version, $sessionId): void {
    $session->update(['schema_version_id' => $version->id]);
    $count = app(InventoryService::class)->generateFromSchema($version, $session->fresh());
    echo "пересобрано мест: {$count}\n";
});

$after = (int) DB::table('inventory_items')->where('session_id', $sessionId)->count();
echo "инвентарь после пересборки: {$after}\n";
