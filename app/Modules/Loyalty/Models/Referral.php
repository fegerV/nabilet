<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Nabilet\Modules\Core\Users\Models\User;

/**
 * Приглашение: строка на пару «пригласивший + email друга» (фаза 4
 * `docs/LOYALTY-PLAN.md`).
 *
 * ДРУГ ОПРЕДЕЛЯЕТСЯ АДРЕСОМ, А НЕ АККАУНТОМ
 *   Покупатель по умолчанию — гость без аккаунта: ссылку открывают до
 *   регистрации, и `user_id` в тот момент не существует. Поэтому ключ —
 *   `invited_email` (в нижнем регистре), а `invited_user_id` — необязательное
 *   уточнение на потом, проставляемое на чекауте, когда аккаунт заведён.
 *
 * СТАТУС — ЖИЗНЕННЫЙ ЦИКЛ, А НЕ НАБОР ФЛАГОВ
 *   `pending` (аккаунта друга ещё нет) → `registered` (аккаунт есть, покупка
 *   не оплачена) → `rewarded` (бонусы начислены, `bonus_paid_at` фиксирует
 *   момент). Словарь закрыт CHECK `ck_referrals_status` (сплит 021).
 *
 * ИДЕМПОТЕНТНОСТЬ В СХЕМЕ
 *   `UNIQUE (inviter_user_id, invited_email)` — повторное оформление по той же
 *   ссылке находит строку, а не создаёт вторую. Проверка «есть ли строка?»
 *   отдельным SELECT ненадёжна (два чекаута внахлёст), поэтому конфликт
 *   вставки — нормальный путь, а не авария: `upsertByEmail()` ниже
 *   обрабатывает его как «приглашение уже есть».
 */
class Referral extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_REGISTERED = 'registered';

    public const STATUS_REWARDED = 'rewarded';

    protected $table = 'referrals';

    protected $fillable = [
        'public_id',
        'inviter_user_id',
        'invited_email',
        'invited_user_id',
        'status',
        'bonus_paid_at',
    ];

    protected function casts(): array
    {
        return [
            'bonus_paid_at' => 'datetime:Y-m-d H:i:s.u',
            'created_at' => 'datetime:Y-m-d H:i:s.u',
            'updated_at' => 'datetime:Y-m-d H:i:s.u',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Referral $referral): void {
            if ($referral->public_id === null) {
                $referral->public_id = Str::ulid()->toBase32();
            }

            // Адрес — ключ в UNIQUE: приведение делает сервер, иначе
            // «Иванов@» и «иванов@» оказались бы двумя приглашениями.
            if (is_string($referral->invited_email)) {
                $referral->invited_email = strtolower($referral->invited_email);
            }
        });
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inviter_user_id');
    }

    public function invitedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_user_id');
    }

    /**
     * Приглашение пары «пригласивший + адрес», заводимое при первом обращении.
     *
     * Возвращает существующую строку, если она есть: повторный переход по
     * ссылке и повторное оформление — это ОДНО приглашение, а не второе.
     * Конфликт вставки по `uq_referrals_inviter_email` обрабатывается как
     * «строка уже есть» — ровно тот же приём, что у
     * `ReferralCode::forUserOrCreate()`.
     */
    public static function upsertByEmail(int|string $inviterUserId, string $invitedEmail, ?int $invitedUserId = null): self
    {
        $email = strtolower($invitedEmail);

        $existing = self::query()
            ->where('inviter_user_id', $inviterUserId)
            ->where('invited_email', $email)
            ->first();

        if ($existing !== null) {
            // Аккаунт друга мог появиться позже приглашения — уточняем, не
            // понижая статус: награда уже выплачена означает, что уточнять
            // нечего.
            if ($existing->status !== self::STATUS_REWARDED && $invitedUserId !== null && $existing->invited_user_id === null) {
                $existing->forceFill([
                    'invited_user_id' => $invitedUserId,
                    'status' => self::STATUS_REGISTERED,
                ])->save();
            }

            return $existing;
        }

        try {
            return self::create([
                'inviter_user_id' => $inviterUserId,
                'invited_email' => $email,
                'invited_user_id' => $invitedUserId,
                'status' => $invitedUserId !== null ? self::STATUS_REGISTERED : self::STATUS_PENDING,
            ]);
        } catch (UniqueConstraintViolationException) {
            return self::query()
                ->where('inviter_user_id', $inviterUserId)
                ->where('invited_email', $email)
                ->firstOrFail();
        }
    }

    /**
     * Приглашения, ожидающие награды за заказ друга.
     *
     * Награда ищется по аккаунту друга, и только если аккаунт на приглашении
     * не проставлен — по адресу заказа (заказ без владельца):
     * `resolveBuyerId()` заводит аккаунт на чекауте, но путь «гость оплатил,
     * аккаунт не завелся» в данных возможен, и терять из-за него награду
     * пригласившему неправильно.
     *
     * @return Builder<Referral>
     */
    public function scopeAwaitingReward(Builder $query, ?int $invitedUserId, ?string $invitedEmail): Builder
    {
        return $query
            ->where('status', '!=', self::STATUS_REWARDED)
            ->where(function (Builder $q) use ($invitedUserId, $invitedEmail): void {
                if ($invitedUserId !== null) {
                    $q->where('invited_user_id', $invitedUserId);
                }

                if ($invitedEmail !== null) {
                    $q->orWhere('invited_email', strtolower($invitedEmail));
                }
            })
            ->orderBy('created_at');
    }

    /** @return Builder<Referral> */
    public function scopeForInviter(Builder $query, int|string $inviterUserId): Builder
    {
        return $query->where('inviter_user_id', $inviterUserId);
    }

    /** @return Builder<Referral> */
    public function scopeRewarded(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_REWARDED);
    }
}
