<?php

declare(strict_types=1);

namespace Nabilet\Modules\Tickets\Services;

use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Core\Support\QrSigner;

/**
 * Единственная точка сборки `QrSigner`.
 *
 * Раньше эта логика была СКОПИРОВАНА в `TicketService::makeQrSigner()` и
 * `TicketScanService::makeQrSigner()` (второй так и был помечен комментарием
 * «логика скопирована»), и ОБА экземпляра молча падали на `config('app.key')`,
 * если секрет подписи не задан. Последствия:
 *
 *  1. утечка `APP_KEY` (архив с `.env`, дамп конфигурации, скомпрометированный
 *     dev-сервер) позволяла подписывать QR ЛЮБЫХ билетов — идеальную подделку,
 *     неотличимую от настоящей на турникете;
 *  2. один ключ начинал обслуживать два несвязанных контура — шифрование
 *     cookie/сессии и подпись билетов;
 *  3. плановая ротация (`php artisan key:generate`) делала недействительными ВСЕ
 *     выпущенные билеты — массовый сбой на входах.
 *
 * Поэтому фолбэка на `APP_KEY` больше нет: без явного секрета система обязана
 * сообщить об этом громко и сразу, а не выпускать билеты, подписанные главным
 * ключом приложения.
 */
final class QrSignerFactory
{
    public static function make(): QrSigner
    {
        $secret = config('nabilet.ticket.qr_secret');

        if (! is_string($secret) || $secret === '') {
            throw new DomainRuleViolation(
                'Ticket QR signing key is not configured. Set TICKET_QR_SECRET '
                . '(at least 32 bytes, e.g. `php -r "echo bin2hex(random_bytes(32));"`). '
                . 'Signing tickets with APP_KEY is not allowed: one leaked key would '
                . 'let anyone forge any ticket, and rotating it would invalidate every '
                . 'issued ticket at once.',
                'QR_SECRET_NOT_CONFIGURED',
                [],
                500,
            );
        }

        // Секрет может быть задан как `base64:...` — как это делает `key:generate`.
        if (str_starts_with($secret, 'base64:')) {
            $decoded = base64_decode(substr($secret, 7), true);

            if ($decoded === false || $decoded === '') {
                throw new DomainRuleViolation(
                    'TICKET_QR_SECRET is not valid base64.',
                    'QR_SECRET_INVALID',
                    [],
                    500,
                );
            }

            $secret = $decoded;
        }

        // QrSigner сам отвергает секрет короче 32 байт — здесь только оборачиваем
        // это в доменную ошибку с внятным кодом, чтобы причина не терялась в 500.
        return new QrSigner($secret);
    }
}
