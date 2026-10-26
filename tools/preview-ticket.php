<?php

declare(strict_types=1);

/**
 * Предпросмотр билета без отправки письма.
 *
 * Зачем: макет билета — это картинка, и проверять её глазами дешевле, чем
 * оформлять тестовый заказ. Скрипт берёт конфигурацию холста (по умолчанию —
 * стандартный макет из `TicketTemplateDefaults`, либо сохранённый шаблон по id)
 * и кладёт рядом HTML и PNG ровно того размера, который увидит покупатель.
 *
 * Запуск:
 *   php tools/preview-ticket.php                       # стандартный макет
 *   php tools/preview-ticket.php --template=12         # шаблон из БД
 *   php tools/preview-ticket.php --ticket=6            # данные настоящего билета
 *
 * Без `--ticket` подставляются демонстрационные значения — этого достаточно,
 * чтобы оценить композицию. QR строится локально, как и в письме.
 */
require dirname(__DIR__) . '/vendor/autoload.php';
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Nabilet\Modules\Tickets\Domain\TicketTemplateDefaults;
use Nabilet\Modules\Tickets\Models\Ticket;
use Nabilet\Modules\Tickets\Services\TicketGeneratorService;
use Nabilet\Modules\Tickets\Services\TicketPdfRenderer;

$options = [];
foreach ($argv as $arg) {
    if (preg_match('/^--([\w-]+)=(.*)$/', $arg, $m) === 1) {
        $options[$m[1]] = $m[2];
    }
}

$outDir = dirname(__DIR__) . '/outputs';
$renderer = $app->make(TicketPdfRenderer::class);

$config = null;
if (isset($options['template'])) {
    $template = \Nabilet\Modules\Tickets\Models\TicketTemplate::query()
        ->whereKey((int) $options['template'])
        ->firstOrFail();
    $config = is_array($template->template_json) ? $template->template_json : null;
    printf("макет: #%d «%s» %d×%d\n", $template->id, $template->name, $template->width, $template->height);
}

if (isset($options['ticket'])) {
    $ticket = Ticket::query()->whereKey((int) $options['ticket'])->firstOrFail();
    $data = $app->make(TicketGeneratorService::class)->generateTicketData($ticket);
    printf("данные: билет %s, заказ #%d\n", $data['ticket_number'], $data['order_id']);
} else {
    // Демо-данные: длинное название — чтобы увидеть перенос строк, и цена с
    // копейками — чтобы проверить формат.
    $data = [
        'order_id' => 1042,
        'ticket_number' => 'TCK-01M4ZSEY-001',
        'event_name' => 'Симфонический оркестр: Чайковский, Рахманинов',
        'event_date' => '13.11.2026 19:00',
        'venue_name' => 'Сургутская филармония, Большой зал',
        'seats' => 'Партер / Ряд 5 / Место 12',
        // Контракт ticketData['price'] — Money; копейки, чтобы проверить дробный формат.
        'price' => \Nabilet\Core\Support\Money::of(350050, 'RUB'),
        'currency' => 'RUB',
        'customer_name' => 'Иван Иванов',
        'customer_email' => 'ivan@example.com',
        'qr_payload' => 'nabilet:demo:'.str_repeat('a', 24),
        'unique_hash' => 'demo',
        'poster_url' => null,
        'ticket_url' => 'http://localhost/#/tickets',
        'qr_data_uri' => null,
        'template' => null,
    ];
}

$html = $config === null
    ? $renderer->renderHtmlFromConfig(TicketTemplateDefaults::config(), $data)
    : $renderer->renderHtmlFromConfig($config, $data);

$htmlPath = $outDir . '/ticket-preview.html';
file_put_contents($htmlPath, $html);
printf("HTML: %s\n", $htmlPath);

$width = (int) ($config['width'] ?? TicketTemplateDefaults::WIDTH);
$height = (int) ($config['height'] ?? TicketTemplateDefaults::HEIGHT);

$chrome = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
$pngPath = $outDir . '/ticket-preview.png';
$cmd = sprintf(
    '"%s" --headless=new --no-sandbox --disable-gpu --no-first-run --hide-scrollbars '
    . '--force-device-scale-factor=2 --window-size=%d,%d --screenshot="%s" "file:///%s"',
    $chrome,
    $width,
    $height,
    str_replace('\\', '/', $pngPath),
    str_replace('\\', '/', $htmlPath)
);

exec($cmd . ' 2>&1', $out, $exit);
printf("PNG: %s (exit %d)\n", $pngPath, $exit);
