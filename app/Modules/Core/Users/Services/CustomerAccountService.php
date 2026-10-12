<?php

declare(strict_types=1);

namespace Nabilet\Modules\Core\Users\Services;

use Illuminate\Database\QueryException;
use Nabilet\Modules\Core\Users\Domain\UserStatus;
use Nabilet\Modules\Core\Users\Models\User;

/**
 * Аккаунт покупателя, выведенный из заказа.
 *
 * ЗАЧЕМ ЭТО СУЩЕСТВУЕТ
 *
 *   Витрина гостевая: покупатель не логинится, и до этого класса у заказа
 *   всегда оставался `user_id = NULL`. Из этого следовали две поломки, обе
 *   проверяемые кодом, а не предположением:
 *
 *   1. ПРОМОКОДЫ НЕ РАБОТАЛИ ПО ПОКУПАТЕЛЮ. `PromoCodeService::buildContext()`
 *      берёт личность из `carts.user_id`, а `userRedemptions()` при `null`
 *      возвращает 0 — то есть гость для системы «использовал код 0 раз»
 *      ВСЕГДА, и `per_user_limit` не действовал. По той же причине условие
 *      `first_purchase` (ТЗ §86) проходило у гостя бесконечно: прошлых заказов
 *      у него «нет» никогда.
 *
 *   2. ЛОЯЛЬНОСТЬ НЕВОЗМОЖНА. Баллы начислять не на кого: у заказа нет
 *      владельца, а `orders.customer_email` — атрибут заказа, а не субъект.
 *
 * ПОЧЕМУ АККАУНТ СОЗДАЁТСЯ, А НЕ СПРАШИВАЕТСЯ
 *
 *   Имя и адрес покупатель уже ввёл на оформлении — они и есть минимальные
 *   данные аккаунта. Лишний шаг «зарегистрируйтесь» перед оплатой стоит
 *   конверсии, поэтому аккаунт заводится по факту покупки.
 *
 * ПОЧЕМУ ПАРОЛЯ НЕТ И `email_verified_at` ПУСТ
 *
 *   `password = NULL` — вход по такому аккаунту невозможен
 *   (`UserService::authenticate()` → `Hash::check()` с пустым хешем всегда
 *   false), пока владелец не задаст пароль через штатный
 *   `POST /auth/password/forgot`. Это и есть подтверждение владения адресом:
 *   `email_verified_at` проставляется только тогда, когда письмо дошло до
 *   настоящего ящика. Пометить адрес подтверждённым здесь было бы ложью —
 *   адрес введён покупателем и никем не проверен.
 *
 *   Поэтому же НЕ назначается роль: «покупатель» — это отсутствие ролей
 *   сотрудника (`StaffRole::isStaff()` ищет admin/manager), а `user_roles`
 *   требует `organization_id` и пишет вторую строку в `user_organization`, то
 *   есть завёл бы покупателя в членство организации. Это сломало бы список
 *   участников и раздуло RBAC, ничего не дав авторизации.
 */
final class CustomerAccountService
{
    /**
     * Найти аккаунт покупателя по адресу заказа, при отсутствии — создать.
     *
     * Возвращает `null`, если адреса нет или он синтаксически неверен: тогда
     * заказ остаётся гостевым, как и раньше. Молча создавать аккаунт на пустой
     * адрес нельзя — получилась бы учётная запись, в которую невозможно войти.
     */
    public function resolveForCheckout(string $email, ?string $name = null, ?string $phone = null): ?int
    {
        $email = trim($email);

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        // Сравнение идёт по колонке с коллацией utf8mb4_unicode_ci, поэтому
        // регистр адреса значения не имеет: `Ivan@Example.com` и
        // `ivan@example.com` — один и тот же аккаунт.
        $existing = User::query()->where('email', $email)->first();

        if ($existing !== null) {
            return (int) $existing->getKey();
        }

        [$firstName, $lastName] = $this->splitName($name);

        try {
            $user = User::create([
                'email' => $email,
                'password' => null,
                'email_verified_at' => null,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'phone' => $this->cleanPhone($phone),
                'status' => UserStatus::ACTIVE,
            ]);
        } catch (QueryException $e) {
            // Гонка: два параллельных оформления с одним новым адресом. У
            // `users.email` есть UNIQUE, поэтому проигравший получает нарушение
            // целостности — это не ошибка, а «аккаунт только что создан
            // конкурентом», и правильный ответ тот же: взять существующий.
            $user = User::query()->where('email', $email)->first();

            if ($user === null) {
                throw $e;
            }
        }

        return (int) $user->getKey();
    }

    /**
     * Разбить введённое имя на `first_name` / `last_name`.
     *
     * В форме оформления одно поле («Иван Петров»), а в схеме — две колонки.
     * Режем по первому пробелу: остаток целиком уходит в фамилию, поэтому
     * «Анна Мария Иванова» не теряет третье слово. Если имя не введено —
     * оба поля `null`, и `User::getNameAttribute()` вернёт «User».
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function splitName(?string $name): array
    {
        $name = trim((string) $name);

        if ($name === '') {
            return [null, null];
        }

        $parts = preg_split('/\s+/', $name, 2) ?: [];

        return [$parts[0] ?? null, $parts[1] ?? null];
    }

    private function cleanPhone(?string $phone): ?string
    {
        $phone = trim((string) $phone);

        return $phone === '' ? null : $phone;
    }
}
