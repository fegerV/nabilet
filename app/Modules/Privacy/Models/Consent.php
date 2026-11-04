<?php

declare(strict_types=1);

namespace Nabilet\Modules\Privacy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nabilet\Modules\Core\Users\Models\User;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string|null $anonymous_id
 * @property string $consent_type
 * @property string $status
 * @property \Carbon\Carbon|null $withdrawn_at момент отзыва согласия;
 *                                            NULL у строк `granted`/`expired`
 * @property string|null $policy_version
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property \Carbon\Carbon $created_at
 *
 * БЕЗ HasPublicId. Таблица `consents` не имеет колонки `public_id` (секция
 * 006 схемы): журнал согласий — внутренний учёт, адресуемый субъектом и
 * целью, наружу эти строки не выдаются вне `/me/consents` (это списки
 * владельца, а не разделяемые ссылки). Трейт стоял здесь раньше и делал
 * любую запись через Eloquent невозможной: хук `creating` проставлял
 * атрибут `public_id`, которого колонка не знает — INSERT падал «Unknown
 * column». Запись согласий всегда шла мимо модели (`NewsletterSubscriptionService`
 * пишет `DB::table('consents')` именно поэтому); теперь запись через модель
 * работает тоже — её ведёт `ConsentService`.
 */
class Consent extends Model
{
    /**
     * Явно, а не через множественное число Eloquent: имя таблицы — часть
     * контракта со схемой (`tools/verify-models-schema.php` сверяет колонки
     * именно с ней), и переименование класса не должно молча менять таблицу.
     */
    protected $table = 'consents';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'anonymous_id',
        'consent_type',
        'status',
        'withdrawn_at',
        'policy_version',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected $casts = [
        'withdrawn_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

}
