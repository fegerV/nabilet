<?php
// Разовый пробник: выдать токен существующему покупателю, чтобы посмотреть
// кабинет «Клуб зрителей» с реальными данными (заказы, бонусы, реферал).
require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$email = $argv[1] ?? 'seat-buyer@example.test';

$user = Nabilet\Modules\Core\Users\Models\User::query()->where('email', $email)->first();

if ($user === null) {
    fwrite(STDERR, "нет пользователя {$email}\n");
    exit(1);
}

$token = app(Nabilet\Modules\Auth\Services\SessionIssuer::class)->issue((int) $user->id);

echo $token, "\n";
