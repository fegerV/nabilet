<?php

declare(strict_types=1);

namespace Nabilet\Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Фикстуры для посадочных страниц города и площадки (аудит P-12).
 *
 * ЗАЧЕМ ОТДЕЛЬНЫЙ ТРЕЙТ, А НЕ ПРЯМОЙ ВЫЗОВ `seedSellableSeat()`. Тому трейту
 * нужна «продаваемая цепочка» (организация → событие → площадка → зал → схема →
 * сеанс → инвентарь), и он её честно строит, но площадке даёт случайный slug и
 * не заполняет город. Для страницы города и площадки это непригодно: адрес
 * страницы — это и есть проверяемое значение, а город в схеме вообще свободный
 * текст, который администратор вводит руками.
 *
 * Цепочка здесь НЕ дублируется: она по-прежнему создаётся `seedSellableSeat()`,
 * а поверх неё дописываются ровно те поля, которых не хватает, — slug, название
 * и город площадки, дата сеанса, статус события. Копия шестидесяти строк
 * создания схемы зала разошлась бы с оригиналом при первой правке.
 *
 * `SellableSeatFixtures` подключается ЗДЕСЬ, а не в каждом тесте отдельно.
 * Иначе класс, написавший `use CatalogFixtures;`, получал бы
 * «Call to undefined method seedSellableSeat()» — ошибку, которая не видна при
 * чтении теста и не ловится статическим анализом, потому что метод приходит из
 * второго трейта. Одна зависимость объявляется в одном месте.
 */
trait CatalogFixtures
{
    use SellableSeatFixtures;
    /**
     * Событие с площадкой, городом и сеансом.
     *
     * `published_at` проставляется, потому что в реальности публикация его
     * выставляет, а карта событий (`SitemapService`) без него событие не берёт.
     * Проверка обратного случая — отдельным тестом, который обнуляет поле
     * напрямую: посадочная страница не должна зависеть от даты публикации.
     *
     * @return array{org: int, event: int, session: int, inventory: int, venue: int, slug: string, publicId: string, venueSlug: string, venueName: string, city: ?string}
     */
    protected function seedCatalogEvent(
        string $title,
        string $venueSlug,
        ?string $city = null,
        int $priceAmount = 250_000,
        int $available = 10,
        ?string $startsAt = null,
        string $venueName = 'Большой театр',
        string $status = 'published',
        string $eventSlug = '',
        ?int $categoryId = null,
    ): array {
        [$orgId, $eventId, $sessionId, $inventoryId] = $this->seedSellableSeat($available, $priceAmount, $title);

        $venueId = (int) DB::table('sessions')->where('id', $sessionId)->value('venue_id');

        DB::table('venues')->where('id', $venueId)->update([
            'slug' => $venueSlug,
            'name' => $venueName,
            'city' => $city,
        ]);

        if ($startsAt !== null) {
            DB::table('sessions')->where('id', $sessionId)->update(['starts_at' => $startsAt]);
        }

        $eventValues = [
            'status' => $status,
            'published_at' => now()->subDay()->toDateTimeString(),
        ];

        if ($eventSlug !== '') {
            $eventValues['slug'] = $eventSlug;
        }

        if ($categoryId !== null) {
            $eventValues['category_id'] = $categoryId;
        }

        DB::table('events')->where('id', $eventId)->update($eventValues);

        $row = DB::table('events')->where('id', $eventId)->first();

        return [
            'org' => $orgId,
            'event' => $eventId,
            'session' => $sessionId,
            'inventory' => $inventoryId,
            'venue' => $venueId,
            'slug' => (string) $row->slug,
            'publicId' => (string) $row->public_id,
            'venueSlug' => $venueSlug,
            'venueName' => $venueName,
            'city' => $city,
        ];
    }

    /** Ожидаемый адрес страницы события — та же функция, что и в приложении. */
    protected function eventUrl(array $event): string
    {
        return route('events.show', [
            'slug' => $event['slug'],
            'publicId' => $event['publicId'],
        ]);
    }

    /**
     * Категория каталога.
     *
     * ОТДЕЛЬНЫМ МЕТОДОМ, А НЕ ПАРАМЕТРОМ ПО УМОЛЧАНИЮ У `seedCatalogEvent()`.
     * Категория — ОБЩАЯ сущность: два события одной категории обязаны ссылаться
     * на ОДНУ строку `event_categories`, а не создавать каждое свою. Иначе фасет
     * «город × категория» нечего проверять — в каждой категории оказалось бы
     * ровно одно событие, и связь «в категории несколько событий» осталась бы
     * непроверенной.
     *
     * `public_id` — ULID в base32, как у остальных сущностей: колонка NOT NULL и
     * UNIQUE (`uq_event_categories_public_id`), поэтому пустой её оставить нельзя.
     * Временных меток у таблицы нет (`$timestamps = false`), поэтому они и не
     * передаются.
     */
    protected function seedCategory(string $name, string $slug, string $status = 'active'): int
    {
        return (int) DB::table('event_categories')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => $name,
            'slug' => $slug,
            'status' => $status,
        ]);
    }

    /**
     * Дописать организатору адрес, название и профиль.
     *
     * ПОЧЕМУ ПРАВКОЙ, А НЕ СОЗДАНИЕМ. Организацию создаёт `seedSellableSeat()` —
     * она первое звено цепочки внешних ключей, и без неё не создать ни события,
     * ни площадки. Но slug там случайный (`test-org-a1b2c3`), а адрес страницы
     * организатора — это и есть проверяемое значение, поэтому его нужно задать
     * явно. Второй `INSERT` в `organizations` дал бы организации-сироту без
     * событий: `organizations()` её вернёт с нулём, и тест на перелинковку
     * проверял бы пустышку.
     *
     * `description`, `logo`, `email`, `phone` — nullable, и передаются только
     * затем, чтобы можно было проверить ОБЕ ветки разметки: с полями и без них.
     */
    protected function seedOrganization(
        int $organizationId,
        string $slug,
        string $name = 'Сургут-Концерт',
        string $status = 'active',
        ?string $description = null,
        ?string $logo = null,
        ?string $email = null,
        ?string $phone = null,
    ): int {
        DB::table('organizations')->where('id', $organizationId)->update([
            'slug' => $slug,
            'name' => $name,
            'status' => $status,
            'description' => $description,
            'logo' => $logo,
            'email' => $email,
            'phone' => $phone,
        ]);

        return $organizationId;
    }

    /**
     * Перевести событие в другую организацию — вместе с его площадкой.
     *
     * ЗАЧЕМ. `seedSellableSeat()` создаёт СВОЮ организацию на каждое событие, а
     * «у организатора несколько мероприятий» — основной сценарий страницы
     * организатора, и без этого его не проверить. Двух событий одной организации
     * иначе не получить вовсе.
     *
     * Площадка переезжает вместе с событием, потому что `venues.organization_id`
     * — реальная связь, и оставить её указывающей на прежнюю организацию значило
     * бы построить состояние, которого в приложении не бывает: страница площадки
     * и страница организатора показывали бы разные организации для одного зала.
     * Slug'и площадок в фикстурах случайны (`venue-XXXXXX`), поэтому составной
     * `uq_venues_org_slug` при переезде не нарушается.
     */
    protected function moveEventToOrganization(int $eventId, int $organizationId): void
    {
        DB::table('events')->where('id', $eventId)->update(['organization_id' => $organizationId]);

        $venueIds = DB::table('sessions')->where('event_id', $eventId)->pluck('venue_id')->all();

        if ($venueIds !== []) {
            DB::table('venues')->whereIn('id', $venueIds)->update(['organization_id' => $organizationId]);
        }
    }
}
