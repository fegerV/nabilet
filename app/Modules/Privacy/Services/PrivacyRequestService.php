<?php

declare(strict_types=1);

namespace Nabilet\Modules\Privacy\Services;

use Illuminate\Support\Carbon;
use Nabilet\Core\Errors\ConflictError;
use Nabilet\Modules\Core\Users\Models\User;
use Nabilet\Modules\Privacy\Domain\PrivacyRequest;
use Nabilet\Modules\Privacy\Domain\PrivacyRequestPolicy;
use Nabilet\Modules\Privacy\Models\PrivacyRequest as PrivacyRequestModel;

/**
 * Запись обращений субъекта ПДн и их жизненный цикл — единый владелец.
 *
 * ДО ЭТОГО
 *   Домен (`PrivacyRequest`, `PrivacyRequestPolicy`, `ErasurePlan`) был
 *   готов и покрыт юнит-тестами, но обращение нельзя было подать НИКАК:
 *   у модуля не было ни одного HTTP-пути, ни одного писателя строк
 *   `privacy_requests`. Права субъекта (152-ФЗ: доступ к данным,
 *   исправление, удаление, ограничение, переносимость) существовали в виде
 *   классов, которые никто не вызывал, — тот же разрыв, что был у бэкапов
 *   до появления `backup:run`.
 *
 * ДЕДЛАЙН
 *   `due_at` вычисляется здесь и записывается в строку при создании:
 *   колонка `NOT NULL` (секция 026 бандла, §3.15), а число дней задаёт
 *   оператор (`nabilet.privacy.request_sla_days`). Домен сознательно не
 *   держит это число — см. докблок `PrivacyRequest`: где спека молчит,
 *   значение передаётся, а не изобретается.
 *
 * ПОЧЕМУ СЕРВИС, А НЕ ЛОГИКА В КОНТРОЛЛЕРЕ
 *   Субъект подаёт обращение с витрины, оператор отвечает из админки —
 *   два входа, один владелец правил записи и переходов; правило из
 *   `PrivacyRequestPolicy` здесь НЕ дублируется, а вызывается.
 */
final class PrivacyRequestService
{
    public function __construct(
        private readonly PrivacyRequestPolicy $policy = new PrivacyRequestPolicy(),
    ) {
    }

    /**
     * Подать обращение. Строка рождается со статусом `requested` и записанным
     * дедлайном. Контактный адрес кладётся в `payload_json`: это единственное
     * место, где он нужен после подачи (канал связи с субъектом), и он не
     * должен нигде появляться «второй копией».
     */
    public function submit(?User $user, string $type, ?string $contactEmail, ?string $message): PrivacyRequestModel
    {
        $now = now();

        $model = new PrivacyRequestModel([
            'user_id' => $user?->id,
            'type' => $type,
            'status' => PrivacyRequest::REQUESTED,
            'due_at' => $now->copy()->addDays($this->slaDays()),
            'payload_json' => $this->payload($contactEmail, $message),
            'created_at' => $now,
        ]);

        $model->save();

        return $model;
    }

    /**
     * Отслеживание и обработка адресуют строку публичным идентификатором:
     * он ответит и на витрине (без входа), и в админке. Внутренний
     * инкрементальный id наружу не отдаётся.
     */
    public function findByPublicId(string $publicId): ?PrivacyRequestModel
    {
        return PrivacyRequestModel::query()->where('public_id', $publicId)->first();
    }

    /**
     * Перевод по жизненному циклу (`PrivacyRequestPolicy`); отказ — конфликт
     * (409), а не валидация: запрос корректен, состояние не то. `no_change`
     * возвращает строку без записи: UPDATE, который меняет лишь timestamp
     * обновления, — это шум в аудите.
     *
     * Момент закрытия пишется только здесь и один раз: терминальный статус
     * обязан нести `completed_at` (требование домена `MISSING_COMPLETED_AT`
     * и словарь базы).
     */
    public function transition(PrivacyRequestModel $model, string $to): PrivacyRequestModel
    {
        $decision = $this->policy->canTransition($this->toDomain($model), $to);

        if (! $decision->isAllowed()) {
            throw new ConflictError(
                $decision->reason ?? 'Переход обращения запрещён жизненным циклом.',
                'PRIVACY_REQUEST_TRANSITION_DENIED',
                ['public_id' => $model->public_id, 'from' => $model->status, 'to' => $to],
            );
        }

        if (! $decision->requiresWrite()) {
            return $model;
        }

        $model->status = $to;

        if (in_array($to, [PrivacyRequest::COMPLETED, PrivacyRequest::REJECTED], true)) {
            // `now()` объявлен `: CarbonInterface`, а модель описывает
            // `@property \Carbon\Carbon` — явный подкласс закрывает тип.
            $model->completed_at = Carbon::now();
        }

        $model->save();

        return $model;
    }

    /**
     * Срок ответа: не меньше суток — опечатка в `.env` не должна превращаться
     * в нулевой дедлайн.
     */
    private function slaDays(): int
    {
        return max(1, (int) config('nabilet.privacy.request_sla_days', 30));
    }

    /**
     * @return array<string, string>
     */
    private function payload(?string $contactEmail, ?string $message): array
    {
        $payload = [];

        if ($contactEmail !== null && trim($contactEmail) !== '') {
            $payload['contact_email'] = $contactEmail;
        }

        if ($message !== null && trim($message) !== '') {
            $payload['message'] = $message;
        }

        return $payload;
    }

    private function toDomain(PrivacyRequestModel $model): PrivacyRequest
    {
        return new PrivacyRequest(
            publicId: $model->public_id,
            type: $model->type,
            status: $model->status,
            createdAt: $model->created_at->toImmutable(),
            completedAt: $model->completed_at?->toImmutable(),
        );
    }
}
