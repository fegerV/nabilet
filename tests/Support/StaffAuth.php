<?php

declare(strict_types=1);

namespace Nabilet\Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Nabilet\Modules\Core\Users\Models\User;

/**
 * Аутентификация администратора организации в feature-тестах.
 *
 * ЗАЧЕМ ТРЕЙТ. Тело этого метода — вставка пользователя, выдача роли `admin` в
 * `user_roles` и `actingAs(..., 'api')` — было приватным методом одного теста
 * (`CheckerHttpLayerTest`). Второй потребитель появился вместе с админскими
 * эндпоинтами Embed, и копия стала бы ровно тем дефектом, который эта кампания
 * устраняет: двумя описаниями «как выглядит админ», которые разойдутся при
 * первом изменении модели ролей — и разойдутся молча, потому что оба теста
 * останутся зелёными, просто один перестанет проверять то, что думает.
 *
 * ПОЧЕМУ `user_roles`, А НЕ ПРОВЕРКА ЧЛЕНСТВА. Роль выдаётся в паре с
 * `organization_id`: middleware `admin` смотрит роль в организации, а не
 * глобальную запись пользователя. Это та же ловушка «сотрудник vs организация»,
 * что описана в `memory/FRONTEND.md`.
 */
trait StaffAuth
{
    /**
     * Войти в API администратором указанной организации.
     *
     * Возвращает id роли: часть тестов проверяет права по нему, остальным он не
     * нужен.
     */
    protected function actingAsStaff(int $organizationId): string
    {
        $now = now()->toDateTimeString();

        $userId = (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'email' => 'operator-' . Str::random(6) . '@example.test',
            'password' => Hash::make('Kv7#mRta2!Qz'),
            'first_name' => 'Оператор',
            'last_name' => 'Входа',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $roleId = (int) (DB::table('roles')->where('slug', 'admin')->value('id')
            ?? DB::table('roles')->insertGetId(['name' => 'Администратор', 'slug' => 'admin']));

        DB::table('user_roles')->insert([
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'role_id' => $roleId,
            'created_at' => $now,
        ]);

        $this->actingAs(User::query()->findOrFail($userId), 'api');

        return (string) $roleId;
    }
}
