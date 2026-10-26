<?php

declare(strict_types=1);

namespace Nabilet\Modules\Auth\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nabilet\Modules\Core\Users\Models\User;

/**
 * Внешний аккаунт, привязанный к пользователю (соц-вход, ТЗ §17).
 *
 * Уникальность пары (`provider`, `provider_user_id`) — это правило, а не
 * украшение: без него вход по одному внешнему аккаунту мог бы разрешиться в
 * двух разных пользователей, и «кто это?» не имело бы ответа. Индекс объявлен
 * в миграции; `SocialAuthService` создаёт связь через `firstOrCreate()`, чтобы
 * гонка двух одновременных входов не падала на дубликате.
 *
 * Модель НЕ использует `HasPublicId`: у таблицы нет колонки `public_id`
 * (внутренняя связь, наружу не выходит).
 */
final class SocialAccount extends Model
{
    protected $table = 'social_accounts';

    protected $fillable = [
        'user_id',
        'provider',
        'provider_user_id',
        'email',
        'name',
        'avatar_url',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
