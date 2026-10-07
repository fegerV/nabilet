<?php

declare(strict_types=1);

namespace Nabilet\Modules\Auth\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Nabilet\Modules\Auth\Domain\AccountTokenPurpose;
use Nabilet\Modules\Core\Users\Repositories\UserRepository;
use Nabilet\Modules\Notifications\Jobs\SendAccountNotificationJob;
use Nabilet\Modules\Notifications\Services\AccountMailService;
use Nabilet\Modules\Notifications\Support\AccountNotificationCodes;

/**
 * Восстановление пароля и подтверждение адреса.
 *
 * ЧТО ЗДЕСЬ РЕАЛЬНО РЕШАЕТСЯ
 *   Два правила, ради которых этот класс существует отдельно от контроллера:
 *
 *   1. Ответ на `forgotPassword()` НЕ ЗАВИСИТ от того, есть ли такой аккаунт.
 *      Если отвечать 404 на неизвестный адрес, эндпоинт становится оракулом
 *      регистрации: по нему перебирают базу адресов. Поэтому метод возвращает
 *      `void` — у него нет способа сообщить вызывающему, найдена ли учётная
 *      запись, и контроллер физически не может утечь этим различием.
 *
 *   2. Успешная смена пароля ОТЗЫВАЕТ ВСЕ СЕССИИ пользователя. Пароль меняют в
 *      том числе тогда, когда подозревают, что кто-то уже вошёл: оставить чужую
 *      сессию живой — значит закрыть дверь, не выгнав того, кто внутри. Заодно
 *      это единый механизм «выйти везде», который ТЗ §5 иначе не описывает.
 *
 * ПОЧЕМУ ЗАПИСЬ ИДЁТ В ТРАНЗАКЦИИ
 *   Смена пароля и удаление сессий — одно событие. Если пароль записан, а
 *   сессии не удалены (или наоборот), состояние получается хуже любого из двух:
 *   старый пароль не работает, а украденный токен продолжает работать. Обе
 *   записи внутри `DB::transaction()`, поэтому откат возвращает и то и другое.
 *
 * ПОЧЕМУ ПИСЬМО НЕ ВНУТРИ ТРАНЗАКЦИИ
 *   Джоба ставится `afterCommit()`. Отправлять письмо до коммита нельзя: письмо
 *   уйдёт, транзакция откатится, и пользователь получит ссылку на смену пароля,
 *   которая уже недействительна.
 */
final class AccountRecoveryService
{
    /** Срок жизни ссылки из письма, в минутах — для текста шаблона. */
    public const LINK_TTL_MINUTES = 30;

    public function __construct(
        private readonly UserRepository $users,
        private readonly AccountTokenService $tokens,
        private readonly SessionIssuer $sessions,
        private readonly AccountMailService $mail,
    ) {}

    /**
     * Запросить сброс пароля. Возвращает `void` намеренно — см. шапку класса.
     *
     * Ничего не сообщает наружу о существовании адреса; различие видно только в
     * логе сервера, куда оно попадает как факт, а не как код ответа.
     */
    public function requestPasswordReset(string $email): void
    {
        $user = $this->users->findByEmail($email);

        if ($user === null) {
            // Тишина — не ошибка. Запись в лог нужна, чтобы поддержка могла
            // отличить «письмо не пришло, потому что адреса нет» от «SMTP лежит».
            Log::info('Запрошен сброс пароля для неизвестного адреса.', ['email_hash' => sha1($email)]);

            return;
        }

        $token = $this->tokens->issue($user, AccountTokenPurpose::PasswordReset);

        SendAccountNotificationJob::dispatch(
            AccountNotificationCodes::PASSWORD_RESET,
            (string) $user->email,
            [
                'customer_name' => $this->displayName($user->first_name, $user->last_name),
                'action_url' => $this->mail->actionUrl('/reset-password', $token, (string) $user->email),
                'expires_in_minutes' => (int) (AccountTokenService::DEFAULT_TTL / 60),
            ],
            (int) $user->getKey(),
        )->afterCommit();
    }

    /**
     * Проверить токен и установить новый пароль.
     *
     * Возвращает `true` только при реальной смене. `false` означает «токен не
     * принят» — без уточнения причины, чтобы клиент не отличал просроченный
     * токен от подделанного.
     */
    public function resetPassword(string $token, string $email, string $newPassword): bool
    {
        $user = $this->tokens->verify($token, AccountTokenPurpose::PasswordReset);

        // Токен подписан на конкретный адрес: он обязан совпасть с телом запроса.
        // Это ловит ссылку, пересланную в другой аккаунт вместе с адресом.
        if ($user === null || ! hash_equals(strtolower((string) $user->email), strtolower($email))) {
            return false;
        }

        return DB::transaction(function () use ($user, $newPassword): bool {
            $this->users->update($user, ['password' => $newPassword]);

            // Отзыв всех сессий — часть события смены пароля, а не отдельное
            // действие: см. шапку класса.
            $this->sessions->revokeAllFor((int) $user->getKey());

            return true;
        });
    }

    /**
     * Подтвердить адрес по токену из письма.
     *
     * `email_verified_at` уже проставляется при регистрации (см. AuthController),
     * поэтому поток подтверждения сейчас сводится к проверке токена: адрес,
     * который не был отмечен, отмечается. Идемпотентно — повторный вызов с тем же
     * токеном не ошибка, но и не действие.
     */
    public function verifyEmail(string $token): bool
    {
        $user = $this->tokens->verify($token, AccountTokenPurpose::EmailVerification);

        if ($user === null) {
            return false;
        }

        if ($user->email_verified_at !== null) {
            return true;
        }

        $this->users->update($user, ['email_verified_at' => now()]);

        return true;
    }

    private function displayName(?string $first, ?string $last): string
    {
        $name = trim(($first ?? '') . ' ' . ($last ?? ''));

        return $name === '' ? 'покупатель' : $name;
    }
}
