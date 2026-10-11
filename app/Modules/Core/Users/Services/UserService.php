<?php

declare(strict_types=1);

namespace Nabilet\Modules\Core\Users\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Nabilet\Core\Errors\ValidationError;
use Nabilet\Modules\Auth\Services\SessionIssuer;
use Nabilet\Modules\Core\Users\Domain\UserStatus;
use Nabilet\Modules\Core\Users\Models\User;
use Nabilet\Modules\Core\Users\Repositories\UserRepository;

class UserService
{
    public function __construct(
        protected UserRepository $repository,
        protected SessionIssuer $sessions,
    ) {}

    /**
     * Create a user, refusing a duplicate email as a 422 rather than a 500.
     *
     * This threw a bare `\RuntimeException`, which `ApiExceptionRenderer` cannot
     * map to anything but a 500 — so `POST /api/v1/users` answered "Something went
     * wrong" for an outcome the caller caused. `ValidationError` is the §66 shape
     * the `unique:users,email` rule already produces, so the two paths agree.
     *
     * The check is not redundant with that rule: two concurrent requests both pass
     * validation, and only this one — the last thing before the INSERT — sees the
     * winner's row.
     */
    public function createUser(array $data): User
    {
        if ($this->repository->findByEmail($data['email'])) {
            throw new ValidationError([
                'email' => ['This email address is already registered.'],
            ]);
        }

        return $this->repository->create($data);
    }

    public function findUser(int $userId): ?User
    {
        return $this->repository->find($userId);
    }

    public function findUserByPublicId(string $publicId): ?User
    {
        return $this->repository->findByPublicId($publicId);
    }

    /**
     * Обновить пользователя из админки.
     *
     * Отдельно от самого UPDATE решается одно правило: смена пароля и блокировка
     * ОБЯЗАНЫ обрывать уже выданные токены. Токен — самостоятельная запись в
     * `user_sessions`, он не перестаёт работать от того, что пароль изменился.
     * Без явного отзыва украденная сессия продолжает жить после «реагирования на
     * инцидент», и у оператора остаётся ложное чувство безопасности (аудит S-03).
     *
     * Обе записи — в одной транзакции, как в `AccountRecoveryService`: состояние,
     * где новый пароль уже действует, а старый токен ещё жив, хуже любого из двух.
     */
    public function updateUser(User $user, array $data): User
    {
        if (isset($data['email']) && $data['email'] !== $user->email) {
            if ($this->repository->findByEmail($data['email'])) {
                throw new ValidationError([
                    'email' => ['This email address is already registered.'],
                ]);
            }
        }

        // Пустой пароль = «не менять» — нормализуется ДО расчёта `$revokeSessions`.
        // Правило валидации (`PasswordRules::optional()`) уже убирает такой ключ,
        // но этот метод вызывается и мимо HTTP (сидеры, скрипты, тесты), а цена
        // ошибки здесь — обрыв всех сессий пользователя на пустом месте, а на
        // слое записи — стёртый пароль.
        if (array_key_exists('password', $data) && ($data['password'] === null || $data['password'] === '')) {
            unset($data['password']);
        }

        // `status` в `active` — это разблокировка: отзывать нечего, наоборот,
        // пользователю снова разрешён вход.
        $revokeSessions = array_key_exists('password', $data)
            || (array_key_exists('status', $data) && $data['status'] !== UserStatus::ACTIVE);

        return DB::transaction(function () use ($user, $data, $revokeSessions): User {
            $updated = $this->repository->update($user, $data);

            if ($revokeSessions) {
                $this->sessions->revokeAllFor((int) $user->getKey());
            }

            return $updated;
        });
    }

    public function deleteUser(User $user): bool
    {
        return $this->repository->delete($user);
    }

    /**
     * Проверить учётные данные и право на вход.
     *
     * Статус — часть аутентификации, а не отдельный флаг рядом с ней. До этой
     * правки метод сверял только хеш пароля, поэтому `status = 'banned'`
     * действовал до первого логина: админ отзывал сессии (см.
     * `UserSessionRevocationTest`), а забаненный получал новые тем же паролем.
     * Отзыв сессий и отказ во входе — половины одного правила.
     *
     * Возврат `null` вместо отдельной ошибки — сознательно: ответ на «неверный
     * пароль» и на «учётная запись заблокирована» обязан совпадать, иначе вход
     * превращается в оракул состояния чужого аккаунта (та же логика, что у
     * `forgotPassword`, который всегда отвечает 204).
     */
    public function authenticate(string $email, string $password): ?User
    {
        $user = $this->repository->findByEmail($email);

        if (!$user || !Hash::check($password, $user->password)) {
            return null;
        }

        if (!UserStatus::mayAuthenticate($user->status)) {
            return null;
        }

        return $user;
    }

    public function assignRoleToUser(User $user, int $roleId, int $organizationId): void
    {
        $this->repository->assignRole($user, $roleId, $organizationId);
    }

    public function removeRoleFromUser(User $user, int $roleId): void
    {
        $this->repository->removeRole($user, $roleId);
    }

    public function hasPermission(User $user, string $permission): bool
    {
        return $this->repository->hasPermission($user, $permission);
    }

    public function searchUsers(string $query, int $limit = 15)
    {
        return $this->repository->search($query, $limit);
    }
}
