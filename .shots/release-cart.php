<?php
require dirname(__DIR__) . '/vendor/autoload.php';
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$cartId = (int) ($argv[1] ?? 0);
$cart = Nabilet\Modules\Cart\Models\Cart::query()->find($cartId);
if ($cart === null) { fwrite(STDERR, "корзина {$cartId} не найдена\n"); exit(1); }
app(Nabilet\Modules\Cart\Services\CartItemService::class)->releaseCartInventory($cart);
echo "корзина {$cartId}: холды сняты\n";
