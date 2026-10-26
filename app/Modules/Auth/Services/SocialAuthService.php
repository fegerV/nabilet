<?php

declare(strict_types=1);

namespace Nabilet\Modules\Auth\Services;

use Illuminate\Support\Facades\DB;
use Nabilet\Modules\Auth\Domain\SocialIdentity;
use Nabilet\Modules\Auth\Models\SocialAccount;
use Nabilet\Modules\Core\Users\Domain\UserStatus;
use Nabilet\Modules\Core\Users\Models\User;

/**
 * К какому пользователю привязать проверенную внешнюю идентичность.
 *
 * Правило разрешения — одно и в одном месте:
 *   1. Уже есть связь (`provider`, `provider_user_id`) → это её пользователь.
 *   2. Связи нет, но провайдер подтвердил e-mail, и такой пользователь есть →
 *      привязываем к нему (вход через соцсеть в существующий аккаунт).
 *   3. Иначе — новый пользователь. `users.email` и `users.password` NULLABLE
 *      именно для этого: Telegram не отдаёт почту, а пароля у соц-входа нет.
 *
 * ГОНКА. Пара (`provider`, `provider_user_id`) уникальна; два одновременных
 * входа создают связь через `firstOrCreate()`, поэтому второй не падает на
 * дубликате (ср. `UniqueRaceCopyTest`). Пользователь по e-mail тоже создаётся
 * `firstOrCreate()` — уникальный индекс `users.email` закрывает гонку.
 *
 * E-MAIL СЧИТАЕТСЯ ПОДТВЕРЖДЁННЫМ. Провайдер отдаёт адрес только после
 * подтверждения владения им, поэтому `email_verified_at` ставится сразу:
 * иначе вход через соцсеть создавал бы аккаунт, которому нельзя писать.
 */
final class SocialAuthService
{
    public function resolve(SocialIdentity $identity): User
    {
        $existing = SocialAccount::query()
            ->where('provider', $identity->provider)
            ->where('provider_user_id', $identity->providerUserId)
            ->first();

        if ($existing !== null) {
            return $existing->user;
        }

        $email = $this->normalizeEmail($identity->email);

        $user = DB::transaction(function () use ($identity, $email): User {
            $user = $this->findOrCreateUser($identity, $email);

            SocialAccount::query()->firstOrCreate(
                [
                    'provider' => $identity->provider,
                    'provider_user_id' => $identity->providerUserId,
                ],
                [
                    'user_id' => $user->getKey(),
                    'email' => $email,
                    'name' => $identity->fullName(),
                    'avatar_url' => $identity->avatarUrl,
                ],
            );

            return $user;
        });

        return $user;
    }

    private function findOrCreateUser(SocialIdentity $identity, ?string $email): User
    {
        // `locale` и `timezone` здесь НЕ выставляются: у обеих колонок есть
        // DEFAULT в схеме (`'ru'` / `'UTC'`), и повторять его в коде — значит
        // держать вторую копию правила, которая разойдётся при первом же
        // изменении DDL. `status` — наоборот, явно: это не формат по
        // умолчанию, а решение «аккаунт активен», и у него есть владелец.
        $defaults = [
            'password' => null,
            'email_verified_at' => $email !== null ? now() : null,
            'first_name' => $identity->firstName,
            'last_name' => $identity->lastName,
            'status' => UserStatus::ACTIVE,
        ];

        // Почта есть — ищем существующего и создаём по ней (уникальный индекс
        // закрывает гонку). Почты нет (Telegram) — `firstOrCreate(['email'=>null])`
        // вернул бы ПЕРВОГО пользователя без почты, поэтому создаём напрямую.
        if ($email !== null) {
            return User::query()->firstOrCreate(['email' => $email], $defaults);
        }

        $user = new User();
        $user->fill($defaults);
        $user->email = null;
        $user->save();

        return $user;
    }

    private function normalizeEmail(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $normalized = strtolower(trim($email));

        return $normalized === '' ? null : $normalized;
    }
}
