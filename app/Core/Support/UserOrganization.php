<?php

declare(strict_types=1);

namespace Nabilet\Core\Support;

/**
 * Единая точка ответа на вопрос «от лица какой организации действует пользователь».
 *
 * ЗАЧЕМ. Один и тот же вопрос задают шесть мест, и до этого класса у него было
 * ЧЕТЫРЕ разных ответа на случай «у пользователя нет членства»:
 *
 *   - `VenueController::store()`         — отказать (единственный верный);
 *   - `EventController::store()`         — взять первую организацию в таблице;
 *   - `StoreEventRequest`                — взять первую по `id`;
 *   - `UpdateEventRequest`               — вернуть `0`;
 *   - `WebhookSubscriptionController`    — отказать (403);
 *   - `StorefrontController`             — откатиться на контекст middleware.
 *
 * «Первая организация в таблице» — это не заглушка, а ЗАПИСЬ ЧУЖОМУ ТЕНАНТУ.
 * Мероприятие, созданное администратором без членства, молча появляется в чужом
 * кабинете: его видят и правят сотрудники той организации, оно уходит на их
 * витрину и в их карту сайта. Ошибка при этом не появляется нигде — `events.
 * organization_id` NOT NULL, значение валидное, ответ 201.
 *
 * Тот же вопрос двумя словами задаёт `ResolveOrganizationContext`, и его докблок
 * называет такой фолбэк недопустимым прямо: «guessing here would mean an
 * unauthenticated request lands in whichever tenant happens to be first».
 * Правило было написано и обосновано — просто не в том месте, откуда его берут.
 *
 * ПОЧЕМУ `null`, А НЕ ИСКЛЮЧЕНИЕ. Что делать с отсутствием членства, решает
 * вызывающий: форма обязана ответить 422 с указанием поля, вебхук — 403,
 * витрина — откатиться на контекст middleware. Исключение здесь выбрало бы один
 * из этих контрактов за всех.
 *
 * ПОЧЕМУ `orderBy`. У сотрудника может быть несколько членств (`user_organization`
 * — многие ко многим). `->first()` без сортировки возвращает ту, что MySQL
 * отдаст первой, то есть разную в разные запросы. `ResolveOrganizationContext`
 * сортирует по `organization_id`, и здесь то же самое — иначе middleware и
 * контроллер выберут разные организации одному пользователю в одном запросе.
 *
 * ГДЕ ЖИВЁТ ЧЛЕНСТВО. `users.organization_id` в схеме НЕТ. Членство сотрудника —
 * таблица `user_organization` (каноничная, см. `UserRepository`), роли — отдельно
 * в `user_roles` (см. `StaffRole`). Путать их нельзя: роль делает пользователя
 * сотрудником, членство говорит, ЧЬИМ сотрудником.
 *
 * Класс чистый, как `StaffRole`: принимает объект пользователя, работает через
 * его отношение и `method_exists`, никаких хелперов фреймворка и фасада `DB`.
 * Поэтому его можно звать и из `tests/Unit` без `vendor/`.
 */
final class UserOrganization
{
    /**
     * Организация пользователя или `null`, если членства нет.
     *
     * @param  object|null $user пользователь из `$request->user()`
     */
    public static function id(?object $user): ?int
    {
        if ($user === null || ! method_exists($user, 'organizations')) {
            return null;
        }

        $id = $user->organizations()
            ->orderBy('organizations.id')
            ->value('organizations.id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Все организации пользователя, детерминированно.
     *
     * Нужно там, где скоуп не «одна организация», а «все, к которым принадлежит
     * сотрудник»: иначе список из шести организаций показывал бы записи только
     * первой, причём какой — решила бы СУБД.
     *
     * @param  object|null $user пользователь из `$request->user()`
     * @return list<int>
     */
    public static function ids(?object $user): array
    {
        if ($user === null || ! method_exists($user, 'organizations')) {
            return [];
        }

        return $user->organizations()
            ->orderBy('organizations.id')
            ->pluck('organizations.id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }
}
