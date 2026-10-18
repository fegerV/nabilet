<?php

declare(strict_types=1);

namespace Nabilet\Modules\Venues\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Http;
use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Core\Errors\ValidationError;

/**
 * Импорт площадки со страницы события Яндекс.Афиши.
 *
 * POST /api/v1/venues/import-afisha  { url }
 * под `auth:api` + `admin`.
 *
 * КОНТРАКТ — ПОЛЕЗНОЕ, А НЕ ВСЁ. Эндпоинт только РАЗБИРАЕТ страницу и
 * возвращает предпросмотр; создание площадки остаётся за обычным
 * `POST /venues` (один write-путь, одна валидация). Из страницы события
 * извлекается embedded JSON-LD (schema.org):
 *
 *   - событие: название, описание, афиша, дата, мин/макс цены;
 *   - площадка: название, адрес, город, геокоординаты, телефоны.
 *
 * Схема зала в JSON-LD не входит — виджет посадки грузится закрытым API
 * с CSRF-токеном, серверный fetch её не отдаёт. Схему рисуют в конструкторе
 * (см. «Центр помощи» → «Схемы залов»).
 *
 * SSRF-ЗАЩИТА. Разбор принимает ссылку от пользователя и сам ходит в сеть:
 * хост жёстко ограничен `afisha.yandex.ru`, схема — только https. Без этого
 * эндпоинт стал бы бесплатным сканером внутренней сети (file://, 169.254.*,
 * localhost-админы) — классическая дыра «import by URL».
 *
 * TLS. Связка CA из php.ini есть не везде (Windows-разработка без
 * curl.cainfo даёт cURL error 60 вместо страницы). Бандл корневых
 * сертификатов кладётся в `resources/certs/cacert.pem` и подключается к
 * запросу явно — работает одинаково на любом хосте без правки php.ini.
 * Любой сбой сети (DNS, таймаут, TLS) — это ожидаемый отказ с конвертом
 * AFISHA_UNREACHABLE, а не 500: страница Яндекса — внешний мир, его
 * недоступность не баг приложения.
 */
class VenueImportController extends Controller
{
    private const ALLOWED_HOST = 'afisha.yandex.ru';

    public function parse(Request $request): JsonResponse
    {
        $data = $request->validate([
            'url' => ['required', 'string', 'max:2000', 'url:https'],
        ]);

        $host = (string) (parse_url($data['url'], PHP_URL_HOST) ?? '');

        if (strtolower($host) !== self::ALLOWED_HOST) {
            throw new ValidationError([
                'url' => ['Разрешены только ссылки на страницы событий ' . self::ALLOWED_HOST],
            ]);
        }

        // AF-страницы проверяют UA: без него — редирект на капчу/заглушку.
        $http = Http::timeout(15)
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; NabiletVenueImport/1.0)',
                'Accept' => 'text/html,application/xhtml+xml',
                'Accept-Language' => 'ru-RU,ru;q=0.9',
            ]);

        $caBundle = base_path('resources/certs/cacert.pem');

        if (is_file($caBundle)) {
            $http = $http->withOptions(['verify' => $caBundle]);
        }

        try {
            $response = $http->get($data['url']);
        } catch (\Throwable $e) {
            throw new DomainRuleViolation(
                'Не удалось загрузить страницу Яндекс.Афиши: сеть или TLS недоступны.',
                'AFISHA_UNREACHABLE',
                ['reason' => mb_substr($e->getMessage(), 0, 160)],
            );
        }

        if ($response->failed()) {
            throw new DomainRuleViolation(
                'Страница Яндекс.Афиши недоступна (код ' . $response->status() . ').',
                'AFISHA_UNREACHABLE',
                ['status' => $response->status()],
            );
        }

        $parsed = $this->extractEvent($response->body());

        if ($parsed === null) {
            throw new DomainRuleViolation(
                'На странице нет карточки события (JSON-LD schema.org). Проверьте, что ссылка ведёт на страницу события, а не на список или площадку.',
                'AFISHA_PARSE_FAILED',
                ['url' => $data['url']],
            );
        }

        $parsed['ticket'] = $this->extractTicket($response->body());

        return response()->json(['data' => $parsed]);
    }

    /**
     * Сеанс и цены из SSR-стейта страницы события.
     *
     * В HTML зашит Apollo-стейт, где у сеанса есть `ticket: {id…,
     * price: {min, max}}` (цены в КОПЕЙКАХ). Ticket id — это id сеанса
     * в системе Яндекс.Билетов: по нему в окне покупки (F12 → Network)
     * ищется карта мест, поэтому предпросмотр отдаёт его админу.
     */
    private function extractTicket(string $html): array
    {
        $ticket = [
            'id' => null,
            'price_min' => null,
            'price_max' => null,
            'price_currency' => null,
        ];

        if (! preg_match(
            // Сеансовый стейт может лежать и под `"ticket":{…}}`, и под
            // нормализованным ключом Apollo `"Ticket:…":{…}`.
            '#"(?:ticket|Ticket:[^"]*)":\{"id":"(\d+)","price":\{[^}]*?"currency":"([a-z]+)","min":(\d+),"max":(\d+)#i',
            $html,
            $m,
        )) {
            return $ticket;
        }

        $ticket['id'] = $m[1];
        $ticket['price_currency'] = mb_strtoupper($m[2]);

        // Копейки → рубли: на витрине Яндекса и в нашей системе рубли.
        $ticket['price_min'] = (int) (((int) $m[3]) / 100);
        $ticket['price_max'] = (int) (((int) $m[4]) / 100);

        return $ticket;
    }

    /**
     * Вытащить из HTML карточку события (JSON-LD @type *Event) и площадку.
     *
     * @return array<string, mixed>|null null — карточки события на странице нет
     */
    private function extractEvent(string $html): ?array
    {
        if (! preg_match_all(
            '#<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is',
            $html,
            $blocks,
        )) {
            return null;
        }

        foreach ($blocks[1] as $block) {
            $json = json_decode(trim($block), true);

            if (! is_array($json)) {
                continue;
            }

            $type = is_string($json['@type'] ?? null) ? strtolower((string) $json['@type']) : '';

            // Музыка/спектакль/стендап — любое событие: у всех есть location+offers.
            if (! str_contains($type, 'event') || ! isset($json['location'])) {
                continue;
            }

            return [
                'event' => [
                    'title' => is_string($json['name'] ?? null) ? (string) $json['name'] : null,
                    'description' => is_string($json['description'] ?? null) ? (string) $json['description'] : null,
                    'image' => is_string($json['image'] ?? null) ? (string) $json['image'] : null,
                    'starts_at' => is_string($json['startDate'] ?? null) ? (string) $json['startDate'] : null,
                    'price_min' => isset($json['offers']['lowPrice']) && is_numeric($json['offers']['lowPrice'])
                        ? (float) $json['offers']['lowPrice'] : null,
                    'price_max' => isset($json['offers']['highPrice']) && is_numeric($json['offers']['highPrice'])
                        ? (float) $json['offers']['highPrice'] : null,
                    'price_currency' => is_string($json['offers']['priceCurrency'] ?? null)
                        ? (string) $json['offers']['priceCurrency'] : null,
                    'url' => is_string($json['url'] ?? null) ? (string) $json['url'] : null,
                ],
                'venue' => $this->extractVenue($json['location']),
            ];
        }

        return null;
    }

    /**
     * Площадка из JSON-LD Place: название, адрес, город, гео, телефоны.
     *
     * @param mixed $place
     * @return array<string, mixed>
     */
    private function extractVenue(mixed $place): array
    {
        if (! is_array($place)) {
            return ['name' => null, 'city' => null, 'address' => null, 'latitude' => null, 'longitude' => null, 'phones' => []];
        }

        $phones = [];

        if (isset($place['telephone'])) {
            $phones = is_array($place['telephone'])
                ? array_values(array_filter($place['telephone'], 'is_string'))
                : (is_string($place['telephone']) ? [$place['telephone']] : []);
        }

        return [
            'name' => is_string($place['name'] ?? null) ? (string) $place['name'] : null,
            'city' => is_string($place['address']['addressLocality'] ?? null)
                ? (string) $place['address']['addressLocality'] : null,
            'address' => is_string($place['address']['streetAddress'] ?? null)
                ? (string) $place['address']['streetAddress'] : null,
            'latitude' => isset($place['geo']['latitude']) && is_numeric($place['geo']['latitude'])
                ? (float) $place['geo']['latitude'] : null,
            'longitude' => isset($place['geo']['longitude']) && is_numeric($place['geo']['longitude'])
                ? (float) $place['geo']['longitude'] : null,
            'phones' => $phones,
        ];
    }
}
