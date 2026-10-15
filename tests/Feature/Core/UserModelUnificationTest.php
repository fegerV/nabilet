<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Models\User as LegacyUser;
use Nabilet\Modules\Core\Users\Models\User as CanonicalUser;
use Tests\TestCase;

/**
 * Одна модель на таблицу `users` — страж от повторного раздвоения.
 *
 * ДЕФЕКТ БЫЛ НЕ КОСМЕТИЧЕСКИЙ. Два класса на одну таблицу: `App\Models\User`
 * со своим `$fillable`, аксессором `name`, `canAccessAdmin()`, `roles()` и
 * `hasRole()` — и без остальных 19 связей; каноничный модульный — со всеми
 * связями, но без этих четырёх методов. `config/auth.php` отправлял web-guard
 * в первый класс, api-guard — во второй, поэтому одна и та же строка возвращалась
 * разными классами в зависимости от точки входа (§3.25).
 *
 * ЦЕНА ДУБЛИРОВАНИЯ — РЕАЛЬНЫЙ БАГ. `Order::user()` резолвится в каноничную
 * модель, поэтому `$order->user?->name` был всегда null, а
 * `OrderNotificationData::customerName()` молча подставлял «Покупатель».
 *
 * ЗДЕСЬ ПРОВЕРЯЕТСЯ РЕШЕНИЕ, А НЕ НАМЕРЕНИЕ:
 *   1. оба провайдера указывают на один класс;
 *   2. легаси-имя — подкласс каноничного (значит, `instanceof` работает и
 *      поведение не может разойтись: унаследованный метод не спорит с исходным);
 *   3. класс не выдуман: таблица, fillable, связи и аксессор `name` на месте;
 *   4. методы, переехавшие из легаси-модели, доступны и через неё.
 */
class UserModelUnificationTest extends TestCase
{
    public function test_both_auth_providers_point_at_one_model(): void
    {
        $web = config('auth.providers.users.model');
        $api = config('auth.providers.api_users.model');

        $this->assertSame(CanonicalUser::class, $web);
        $this->assertSame(CanonicalUser::class, $api);
    }

    public function test_the_legacy_name_is_a_subclass_of_the_canonical_model(): void
    {
        $this->assertTrue(is_subclass_of(LegacyUser::class, CanonicalUser::class));
    }

    public function test_the_legacy_model_resolves_to_the_same_table(): void
    {
        $this->assertSame((new CanonicalUser())->getTable(), (new LegacyUser())->getTable());
        $this->assertSame('users', (new LegacyUser())->getTable());
    }

    public function test_the_canonical_model_declares_the_relations_the_legacy_one_had(): void
    {
        $user = new CanonicalUser();

        // Методы, переехавшие из `App\Models\User`: без них уведомление о
        // заказе теряло имя покупателя, а проверка роли молча давала false.
        $this->assertTrue(method_exists($user, 'getNameAttribute'));
        $this->assertTrue(method_exists($user, 'canAccessAdmin'));
        $this->assertTrue(method_exists($user, 'roles'));
        $this->assertTrue(method_exists($user, 'hasRole'));

        // Связи, которых у легаси-модели не было вовсе.
        $this->assertTrue(method_exists($user, 'userSessions'));
        $this->assertTrue(method_exists($user, 'loginLogs'));
        $this->assertTrue(method_exists($user, 'orders'));
    }

    public function test_the_legacy_model_exposes_the_relocated_methods(): void
    {
        $this->assertTrue(method_exists(LegacyUser::class, 'canAccessAdmin'));
        $this->assertTrue(method_exists(LegacyUser::class, 'getNameAttribute'));
        $this->assertTrue(method_exists(LegacyUser::class, 'hasRole'));
    }
}
