<?php

declare(strict_types=1);

namespace Nabilet\Modules\Privacy\Services;

use Illuminate\Database\Query\Expression;
use Nabilet\Core\Errors\ConflictError;
use Nabilet\Core\Errors\NotFoundError;
use Nabilet\Core\Support\PackedIp;
use Nabilet\Modules\Privacy\Domain\ConsentDecision;
use Nabilet\Modules\Privacy\Domain\ConsentPolicy;
use Nabilet\Modules\Privacy\Domain\ConsentRecord;
use Nabilet\Modules\Privacy\Models\Consent;
use Nabilet\Modules\Core\Users\Models\User;

/**
 * Согласия субъекта-аккаунта: чтение «последнее решает» и отзыв.
 *
 * ДО ЭТОГО
 *   Домен (`ConsentRecord`, `ConsentPolicy`) и модель были готовы и покрыты
 *   юнит-тестами, но HTTP-слоя для согласий не существовало вовсе: субъект
 *   не мог ни увидеть свои согласия, ни отозвать их. Единственный работающий
 *   контур — рассылка (`NewsletterSubscriptionService`), и он закрывает
 *   своё согласие по токену отписки (152-ФЗ §9: владелец адреса отписывается
 *   без пароля).
 *
 * ЧТО ЗДЕСЬ, А ЧТО НЕТ
 *   Объктом этого сервиса являются строки `consents` с СУБЪЕКТОМ-АККАУНТОМ
 *   (`user_id`). Согласия гостей живут под анонимным субъектом (XOR —
 *   `ConsentRecord::INVALID_CONSENT_SUBJECT`) и управляются своими
 *   механизмами: токен отписки для рассылки. Смешивать два способа
 *   идентификации в одном списке значило бы выдавать пользователю за «его
 *   данные» строки, принадлежность которых держится на совпадении адреса,
 *   а не на аутентификации.
 *
 * ПОЧЕМУ НЕТ GENERIC GRANT
 *   POST /me/consents/{type} (self-service выдача согласия) сознательно не
 *   построен. Правило `ConsentPolicy` по умолчанию ЗАПРЕЩАЕТ обработку:
 *   согласие имеет смысл только при существующем потребителе обработки,
 *   который запишет, какую версию политики принял субъект (как рассылка
 *   пишет `policy_version` в той же транзакции, что и подписку). Строка
 *   `granted`, которую никто не читает, — «разрешение в стол»: мусор в
 *   аудите, похожий на доказательство. Когда появится первый контур
 *   обработки вне рассылки, grant-эндпоинт добавится к нему — а не в
 *   общий список «на будущее».
 *
 * ПОЧЕМУ ОТЗЫВ — НОВАЯ СТРОКА
 *   Журнал не переписывается: отзыв пишет вторую строку с `withdrawn_at`
 *   (требование `ck_consents_withdrawn_has_moment` и домена одно), историю
 *   «grant → withdrawn» видно по двум строкам. Повторный отзыв уже
 *   отозванного не пишет третью: ответ тот же, записи нет — пустой UPDATE
 *   не должен выглядеть как активность уверителя.
 */
final class ConsentService
{
    public function __construct(
        private readonly ConsentPolicy $policy = new ConsentPolicy(),
    ) {
    }

    /**
     * Согласия пользователя, ПОСЛЕДНЕЕ РЕШАЕТ: одна запись на каждый
     * `consent_type`, у которого у субъекта есть строки. Порядок чтения —
     * `created_at desc, id desc`: у истории нет уникального ключа
     * (subject, type) — повторная выдача права даёт вторую строку, и читать
     * надо newest-first, иначе отзыв выглядит как разрешение. `id` —
     * тайбрейк, когда два создания попали в одну микросекунду.
     *
     * @return array<string, Consent> consent_type => последняя строка
     */
    public function latestByType(User $user): array
    {
        $rows = Consent::query()
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        /** @var array<string, Consent> $latest */
        $latest = [];

        foreach ($rows as $row) {
            if (! isset($latest[$row->consent_type])) {
                $latest[$row->consent_type] = $row;
            }
        }

        return $latest;
    }

    /**
     * Отозвать согласие субъекта на конкретную цель. Возвращает строку,
     * которую предъявить субъекту (`written = false` — запись не менялась,
     * согласие уже отозвано).
     *
     * Решение о возможности отзыва — доменное (`ConsentPolicy::canWithdraw`),
     * здесь только раскладка вердиктов на HTTP-номенклатуру: «нет такого
     * согласия» — 404, «уже отозвано» — тот же ответ без записи,
     * «просрочено» — конфликт (409): отзывать нечего, цель не активна.
     *
     * @return array{written: bool, consent: Consent}
     */
    public function withdraw(User $user, string $type, ?string $ip, ?string $ua): array
    {
        $latest = $this->latestByType($user)[$type]
            ?? throw new NotFoundError('Consent', $type);

        $decision = $this->policy->canWithdraw($this->toRecord($latest));

        if ($decision->isPermitted()) {
            $moment = now();

            // Отзыв — новая строка того же субъекта и той же цели; момент
            // отзыва обязателен, иначе строку отвергнут и домен, и CHECK.
            // IP — упакованные байты (`binary(16)`) в виде SQL-литерала:
            // связывать как строку нельзя (см. PackedIp — резка на NUL-байте
            // делала бы аудиторскую запись похожей на заполненную и при этом
            // неверной); UA обрезается до ширины колонки, чтобы длинный заголовок клиента
            // вместо отказа записи отзывом не ронял запрос.
            $row = new Consent([
                'user_id' => $user->id,
                'anonymous_id' => null,
                'consent_type' => $type,
                'status' => ConsentRecord::WITHDRAWN,
                'withdrawn_at' => $moment,
                'policy_version' => $latest->policy_version,
                'ip_address' => $this->packedIp($ip),
                'user_agent' => $ua === null ? null : mb_substr($ua, 0, 1024),
                'created_at' => $moment,
            ]);

            $row->save();

            return ['written' => true, 'consent' => $row];
        }

        if ($decision->verdict === ConsentDecision::WITHDRAWN) {
            return ['written' => false, 'consent' => $latest];
        }

        throw new ConflictError(
            $decision->reason ?? 'Это согласие нельзя отозвать — оно не активно.',
            'CONSENT_NOT_WITHDRAWABLE',
            ['consent_type' => $type, 'status' => $latest->status],
        );
    }

    /**
     * IP в упакованную форму SQL-литерала. `null` (или неадресуемая строка)
     * хранится как «не записано» — честнее байтов мусорной строки; вопрос
     * кодировки решённый здесь же, в сервисе, а не расползается по вызывающим.
     */
    private function packedIp(?string $ip): mixed
    {
        $literal = PackedIp::toSqlLiteral($ip);

        return $literal === null ? null : new Expression($literal);
    }

    /**
     * Перенос строки в домен для решения политики. XOR субъекта и словарь
     * статусов держит база (CHECK 026/017), поэтому и здесь конструктор
     * домена не откажет — а если когда-нибудь откажет, это сигнал утечки
     * мимо барьеров, а не случай для исправления на месте.
     */
    private function toRecord(Consent $row): ConsentRecord
    {
        return new ConsentRecord(
            type: $row->consent_type,
            status: $row->status,
            grantedAt: $row->created_at->toImmutable(),
            userId: $row->user_id,
            anonymousId: $row->anonymous_id,
            policyVersion: $row->policy_version,
            withdrawnAt: $row->withdrawn_at?->toImmutable(),
        );
    }
}
