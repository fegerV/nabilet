<?php

declare(strict_types=1);

namespace Nabilet\Modules\Notifications\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Str;

/**
 * Подписчик на рассылку афиши.
 *
 * Отдельная таблица, а не «согласие в consents»: consents хранит ФАКТ
 * согласия (для GDPR-запросов) и не умеет хранить адрес получателя — письма
 * без email не существует. Токен отписки обязателен: владелец адреса должен
 * уметь отменить подписку без пароля и без письма в ответ (152-ФЗ §9).
 */
final class NewsletterSubscriber extends Model
{
    public const STATUS_SUBSCRIBED = 'subscribed';
    public const STATUS_UNSUBSCRIBED = 'unsubscribed';

    public const CONSENT_TYPE = 'newsletter';

    protected $table = 'newsletter_subscribers';

    protected $fillable = [
        'email',
        'unsubscribe_token',
        'status',
        'anonymous_id',
        'policy_version',
    ];

    public static function booted(): void
    {
        static::creating(function (self $model): void {
            if ($model->anonymous_id === null) {
                $model->anonymous_id = (string) Str::uuid();
            }
        });
    }
}
