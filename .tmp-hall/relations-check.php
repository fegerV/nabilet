<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$checks = [
    [Nabilet\Modules\Privacy\Models\Consent::class, 'user'],
    [Nabilet\Modules\Privacy\Models\PrivacyRequest::class, 'user'],
    [Nabilet\Modules\Notifications\Models\Notification::class, 'user'],
    [Nabilet\Modules\Webhooks\Models\WebhookDelivery::class, 'webhook'],
    [Nabilet\Modules\Core\Users\Models\User::class, 'consents'],
    [Nabilet\Modules\Core\Users\Models\User::class, 'privacyRequests'],
    [Nabilet\Modules\Core\Users\Models\User::class, 'notifications'],
];

foreach ($checks as [$class, $method]) {
    $model = new $class();
    $relation = $model->{$method}();
    $related = $relation->getRelated();
    printf("%-52s %-16s -> %s\n", substr($class, 0, 52), $method, get_class($related));
}
