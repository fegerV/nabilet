<?php

declare(strict_types=1);

namespace Nabilet\Modules\Auth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Nabilet\Core\Errors\NotFoundError;
use Nabilet\Core\Errors\ValidationError;
use Nabilet\Modules\Auth\Http\Requests\ChangePasswordRequest;
use Nabilet\Modules\Auth\Http\Requests\ProfileUpdateRequest;
use Nabilet\Modules\Auth\Http\Resources\AuthResource;
use Nabilet\Modules\Auth\Http\Resources\UserSessionResource;
use Nabilet\Modules\Auth\Services\ClientContext;
use Nabilet\Modules\Auth\Services\SessionIssuer;
use Nabilet\Modules\Core\Models\UserSession;
use Nabilet\Modules\Core\Users\Models\User;
use Nabilet\Modules\Core\Users\Services\UserService;
use Nabilet\Modules\Users\Domain\IdentityDecision;
use Nabilet\Modules\Users\Domain\UserIdentity;
use Nabilet\Modules\Users\Domain\UserIdentityPolicy;

/**
 * Личный кабинет покупателя — пространство `/api/v1/me/*`.
 *
 * ЗАЧЕМ ЭТОТ КЛАСС, ЕСЛИ МАРШРУТЫ БЫЛИ ОБЪЯВЛЕНЫ ДАВНО
 *   `nabilet_core_spec/openapi.yaml` объявляет пять путей `/me/*` — `GET /me`,
 *   `PATCH /me`, `POST /me/password`, `GET /me/sessions`,
 *   `DELETE /me/sessions/{session}` — и ни один из них не был реализован:
 *   это записано в `docs/REVIEW-spec-bundle.md` §3.24.4 как открытый пробел
 *   («`/me/*` (5)» среди необслуживаемых пространств имён). Витрина при этом
 *   оставалась полностью гостевой: у заказа `user_id = NULL`, войти покупателю
 *   было некуда и незачем.
 *
 * ЧТО ЗДЕСЬ ПРИНЦИПИАЛЬНО
 *
 *  1. ВЛАДЕЛЕЦ БЕРЁТСЯ ИЗ СЕССИИ, А НЕ ИЗ ЗАПРОСА. Ни один метод не принимает
 *     идентификатор пользователя: `$request->user()` — это результат
 *     аутентификации по Bearer-токену (`auth:api` на маршрутах), и подставить
 *     чужой `user_id` неоткуда. Тот же принцип, что в `OrderController::index`,
 *     где `user_id` из query принудительно перезаписывается для не-сотрудника.
 *
 *  2. СМЕНА АДРЕСА ИДЁТ ЧЕРЕЗ `UserIdentityPolicy`, А НЕ ЧЕРЕЗ `update()`.
 *     Схема ничего не знает о `email_verified_at`, поэтому
 *     `UPDATE users SET email = 'other@example.com'` на MySQL 8.4 проходил с
 *     неочищенным флагом подтверждения — аккаунт начинал «владеть» адресом,
 *     который никто не подтверждал (REVIEW §3.17). Политика запрещает перенос
 *     подтверждённого адреса и очищает флаг при реальной смене; здесь она
 *     вызывается ДО записи, а `withEmail()` вычисляет итоговое значение флага.
 *
 *  3. СМЕНА ПАРОЛЯ ОТЗЫВАЕТ ВСЕ СЕССИИ — через `UserService::updateUser()`,
 *     который делает это сам (аудит S-03). Следствие, которое надо знать:
 *     вызвавший теряет и собственную сессию, то есть после успешного
 *     `POST /me/password` клиент обязан залогиниться заново. Это не побочный
 *     эффект, а правило проекта: «выйти везде» — единственный описанный способ
 *     отозвать украденный токен (`AccountRecoveryService` делает то же самое).
 */
class MeController extends Controller
{
    public function __construct(
        private readonly UserService $users,
        private readonly SessionIssuer $sessions,
        private readonly UserIdentityPolicy $identity = new UserIdentityPolicy(),
    ) {}

    /** `getMe` — профиль текущего пользователя. */
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'data' => new AuthResource($request->user()->load('roles')),
        ]);
    }

    /**
     * `updateMe` — правка собственного профиля.
     *
     * `email` обрабатывается отдельно от остальных полей: у него есть правило,
     * которого нет у фамилии или часового пояса — флаг подтверждения.
     */
    public function update(ProfileUpdateRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validated();

        if (array_key_exists('email', $data) && $data['email'] !== null) {
            $identity = $this->identityOf($user);

            $decision = $this->identity->addressChangeDecision($identity, (string) $data['email']);

            if (! $decision->isAllowed()) {
                throw new ValidationError(
                    ['email' => [$this->addressChangeMessage($decision)]],
                    'Не удалось изменить адрес.',
                    [],
                    'EMAIL_CHANGE_REJECTED',
                );
            }

            // `withEmail()` возвращает НОВУЮ идентичность с очищенным флагом,
            // если нормализованный адрес действительно изменился. Значение
            // берётся из неё, а не выставляется в `null` вручную: так правило
            // «флаг живёт только на подтверждённом адресе» остаётся в домене, и
            // повторная отправка формы без правки адреса не снимает подтверждение
            // у всей базы.
            $data['email_verified_at'] = $identity
                ->withEmail((string) $data['email'])
                ->emailVerifiedAt;
        }

        $updated = $this->users->updateUser($user, $data);

        return response()->json([
            'data' => new AuthResource($updated->load('roles')),
        ]);
    }

    /**
     * `changePassword` — смена пароля из кабинета.
     *
     * Текущий пароль проверяется только если он у аккаунта есть: у покупателя,
     * заведённого по факту покупки, `users.password IS NULL`, и требовать
     * «текущий» было бы требованием предъявить то, чего не существует. Правило
     * условное в `ChangePasswordRequest` — там же записано, почему это
     * расхождение со схемой осознанное.
     */
    public function changePassword(ChangePasswordRequest $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $hasPassword = $user->password !== null && $user->password !== '';

        if ($hasPassword && ! Hash::check((string) $request->validated('current_password'), $user->password)) {
            throw new ValidationError(
                ['current_password' => ['Текущий пароль указан неверно.']],
                'Текущий пароль указан неверно.',
                [],
                'CURRENT_PASSWORD_INVALID',
            );
        }

        // Отзыв всех сессий — часть этой операции, а не отдельный вызов:
        // см. шапку класса.
        $this->users->updateUser($user, ['password' => (string) $request->validated('password')]);

        return response()->noContent();
    }

    /** `listSessions` — активные сессии аккаунта. */
    public function sessions(Request $request): JsonResponse
    {
        $rows = UserSession::query()
            ->where('user_id', $request->user()->getKey())
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => UserSessionResource::collection($rows),
        ]);
    }

    /**
     * `revokeSession` — завершить одну сессию.
     *
     * Чужая сессия — 404, а не 403: 403 подтвердил бы, что сессия с таким id
     * существует у кого-то другого, то есть позволил бы перебором узнать размер
     * чужого набора сессий. Тот же выбор, что у `OrderController::show()`.
     *
     * Возврат `SessionIssuer::revoke()` не проверяется — ровно как в
     * `AuthController::logout()`. `false` там означает «строку не удалось
     * прочитать» (нет хеша токена или срока), а такая сессия уже не может быть
     * предъявлена guard'ом: `SessionIssuer::authenticate()` отвергает её. То есть
     * «отозвана» для неё — правда, хотя DELETE и не выполнился.
     */
    public function revokeSession(Request $request, int $session): Response
    {
        /** @var User $user */
        $user = $request->user();

        $row = UserSession::query()
            ->where('user_id', $user->getKey())
            ->whereKey($session)
            ->first();

        if ($row === null) {
            throw new NotFoundError('User session', $session);
        }

        $this->sessions->revoke(
            $row,
            ClientContext::fromRequest($request),
            identifier: $user->email === null ? null : (string) $user->email,
        );

        return response()->noContent();
    }

    /**
     * Eloquent-строка → значение для доменной политики.
     *
     * Маппинг живёт здесь, а не в домене: `UserIdentity` — значение без I/O
     * (см. её шапку), и читать модель она не может, иначе перестанет
     * проверяться без базы.
     */
    private function identityOf(User $user): UserIdentity
    {
        return new UserIdentity(
            id: (int) $user->getKey(),
            email: $user->email,
            emailVerifiedAt: $user->email_verified_at?->toDateTimeImmutable(),
            phone: $user->phone,
            phoneVerifiedAt: $user->phone_verified_at?->toDateTimeImmutable(),
            passwordHash: $user->password,
            firstName: $user->first_name,
            lastName: $user->last_name,
            status: (string) $user->status,
            deletedAt: $user->deleted_at?->toDateTimeImmutable(),
        );
    }

    /** Вердикт политики — человеческим текстом, в поле `email`. */
    private function addressChangeMessage(IdentityDecision $decision): string
    {
        return match ($decision->verdict) {
            IdentityDecision::NOT_REACHABLE => 'Нельзя убрать e-mail: у аккаунта не останется способа связи.',
            IdentityDecision::ORPHANED_VERIFICATION => 'Адрес уже подтверждён. Правка профиля не переносит подтверждение — смените адрес через подтверждение нового.',
            default => 'Этот адрес нельзя назначить аккаунту.',
        };
    }
}
