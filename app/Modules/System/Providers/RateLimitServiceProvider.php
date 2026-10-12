<?php

declare(strict_types=1);

namespace Nabilet\Modules\System\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Nabilet\Modules\Checkin\Http\Middleware\AuthenticateChecker;

/**
 * Лимиты частоты для публичных эндпоинтов, которые остались без `throttle`
 * (аудит S-05, S-06).
 *
 * ПОЧЕМУ ОТДЕЛЬНЫЙ ПРОВАЙДЕР, А НЕ `throttle:N,1` В МАРШРУТАХ
 *   Инлайновый лимит умеет ровно одну корзину, и её ключ выбирает фреймворк:
 *   для аутентифицированного запроса — id пользователя, для анонимного — IP.
 *   Для промокодов этого мало: анонимный покупатель за общим NAT и скрипт-
 *   переборщик с того же адреса неразличимы, поэтому нужны ДВЕ корзины —
 *   по адресу и по корзине покупателя. Для чекина, наоборот, ключ по
 *   пользователю лучше, чем по адресу: одно устройство на входе может ходить
 *   через меняющийся мобильный IP.
 *
 * ПОЧЕМУ ПРОВАЙДЕР РЕГИСТРИРУЕТСЯ ЯВНО
 *   `RateLimiter::for()` пишет в статический реестр, который читает middleware
 *   `throttle`, — значит, заполнить его нужно на boot. Из модульных провайдеров
 *   boot выполняется только у зарегистрированных в `bootstrap/providers.php`
 *   (см. предупреждение в его шапке), поэтому провайдер добавлен туда. Своих
 *   маршрутов он НЕ грузит — префикс /api/v1 никуда не теряется.
 *
 * Форма ошибки не меняется: `ThrottleRequestsException` уже отображается
 * `ApiExceptionRenderer` в конверт §66 с кодом `TOO_MANY_REQUESTS`, поэтому
 * 429 приходит в том же виде, что и для `throttle:auth`.
 */
class RateLimitServiceProvider extends ServiceProvider
{
    /** Промокод: перебор словаря с одного адреса. Человек вводит код 1–2 раза. */
    private const PROMO_PER_IP = 10;

    /** Тот же перебор, но привязанный к корзине (см. `registerPromoValidate()`). */
    private const PROMO_PER_CART = 20;

    /** Чекин: выше человеческого темпа, но ниже brute-force по ticketPublicId. */
    private const CHECKIN_PER_MINUTE = 120;

    /**
     * Повторная отправка билета: эндпоинт шлёт письмо на адрес, который указал
     * вызывающий. Без лимита он превращается в релей для спама от нашего домена.
     */
    private const RESEND_PER_IP = 5;

    /** Тот же потолок, привязанный к корзине: человек жмёт «отправить» 1–2 раза. */
    private const RESEND_PER_CART = 3;

    public function boot(): void
    {
        $this->registerPromoValidate();
        $this->registerCheckinScan();
        $this->registerTicketResend();
    }

    /**
     * `POST /promo-codes/validate` — публичный read-only эндпоинт: покупатель
     * проверяет код до входа, когда у него есть только анонимная корзина.
     *
     * Без лимита он превращается в бесплатный оракул: скрипт перебирает
     * словарь (`BLACKFRIDAY`, `ORG2026…`) и получает список действующих скидок
     * с их параметрами — то есть коммерческие условия кампаний (аудит S-05).
     */
    private function registerPromoValidate(): void
    {
        RateLimiter::for('promo-validate', function (Request $request): array {
            $limits = [
                Limit::perMinute(self::PROMO_PER_IP)->by('promo-ip:' . $request->ip()),
            ];

            // Вторая корзина — по анонимному идентификатору корзины. Добавляем её
            // ТОЛЬКО когда токен реально пришёл: иначе все анонимные запросы без
            // токена попали бы в одну общую корзину `promo-cart:anon`, и один
            // скрипт исчерпал бы лимит для всех покупателей сразу — то есть
            // собственный лимит стал бы вектором отказа в обслуживании.
            $cartToken = (string) $request->header('X-Cart-Token', '');

            if ($cartToken !== '') {
                $limits[] = Limit::perMinute(self::PROMO_PER_CART)->by('promo-cart:' . $cartToken);
            }

            return $limits;
        });
    }

    /**
     * `POST /tickets/checkin/scan` и `/checkin/verify` (сессия сотрудника), а
     * также `/api/v1/checkin/validate|use|sync` (токен устройства).
     *
     * Маршруты и так аутентифицированы — прямой IDOR закрыт, — но
     * скомпрометированная учётка оператора могла перебирать `ticketPublicId`
     * (verify отдаёт данные билета) и заваливать БД транзакционными записями
     * сканов в день мероприятия (аудит S-06).
     *
     * Ключ — устройство, затем пользователь, и только потом адрес: сканер на
     * входе ходит через меняющийся мобильный IP, и привязка к адресу давала бы
     * свежую корзину после каждой смены сети, то есть лимит не работал бы вовсе.
     */
    private function registerCheckinScan(): void
    {
        RateLimiter::for('checkin-scan', function (Request $request): Limit {
            $device = $request->attributes->get(AuthenticateChecker::ATTRIBUTE);
            $identity = $device?->id
                ?? $request->user()?->getAuthIdentifier()
                ?? $request->ip();

            return Limit::perMinute(self::CHECKIN_PER_MINUTE)->by('checkin:' . $identity);
        });
    }

    /**
     * `POST /my-tickets/resend` — публичный эндпоинт самопомощи покупателя.
     *
     * Он отправляет письмо на адрес, который пришёл в теле запроса, поэтому без
     * лимита становится релеем: один вызов = одно письмо от нашего домена любому
     * получателю. Доступ к заказу закрыт capability-токеном корзины, но и с ним
     * потолок нужен — покупателю достаточно одной повторной отправки.
     *
     * Две корзины, как у `promo-validate`: по адресу (сеть за NAT) и по токену
     * корзины. Вторая добавляется только при непустом токене, иначе все
     * анонимные запросы делили бы одну корзину и один скрипт исчерпал бы лимит
     * для всех покупателей сразу.
     */
    private function registerTicketResend(): void
    {
        RateLimiter::for('ticket-resend', function (Request $request): array {
            $limits = [
                Limit::perMinute(self::RESEND_PER_IP)->by('resend-ip:' . $request->ip()),
            ];

            $cartToken = (string) $request->header('X-Cart-Token', '');

            if ($cartToken !== '') {
                $limits[] = Limit::perMinute(self::RESEND_PER_CART)->by('resend-cart:' . $cartToken);
            }

            return $limits;
        });
    }
}
