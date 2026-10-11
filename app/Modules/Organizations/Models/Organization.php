<?php

declare(strict_types=1);

namespace Nabilet\Modules\Organizations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Nabilet\Modules\Events\Models\Event;
use Nabilet\Modules\System\Models\ApiKey;
use Nabilet\Modules\Tickets\Models\CheckinDevice;
use Nabilet\Modules\Venues\Models\Venue;

/**
 * ДУБЛИКАТ-НАСЛЕДИЕ. Каноническая модель — `Nabilet\Modules\Core\Organizations\Models\Organization`;
 * на неё ссылаются два десятка файлов, и только на неё стоит ориентироваться в
 * новом коде. Здесь остались три старые связи, которые переведены на канонический
 * класс.
 *
 * ЧТО ЗДЕСЬ БЫЛО СЛОМАНО. На этой модели стоял трейт `SoftDeletes`, а колонки
 * `organizations.deleted_at` в схеме НЕТ — ни в
 * `2026_09_20_000100_001_identity.php`, ни в `nabilet_core_spec/migrations.sql`
 * (проверено и на живой базе: `deleted_at` есть у `events`, `media_assets`,
 * `promo_codes`, `users` и `event_dates`, но не у `organizations`). Трейт
 * добавлял `where organizations.deleted_at is null` в КАЖДЫЙ запрос, поэтому
 * любой запрос через этот класс падал с `SQLSTATE[42S22] Column not found: 1054`
 * — не «мягкое удаление не работало», а «модель непригодна к использованию».
 * Найдено при первом прогоне тестов страницы организатора.
 *
 * ПОЧЕМУ ТРЕЙТ УБРАН, А НЕ ДОБАВЛЕНА КОЛОНКА. Схема — источник истины, и она
 * проверяется гейтами (`tools/verify-migrations.php`, `tools/verify-models-schema.php`):
 * добавление колонки — это изменение контракта, а не починка опечатки. У
 * организаций мягкого удаления нет по замыслу: с публикации снимают `status`, а
 * удаляют по-настоящему, когда у организации не осталось событий.
 *
 * ПОЧЕМУ ФАЙЛ НЕ УДАЛЁН. Проект не удаляет код по решению одного аудита
 * (`tools/verify-module-structure.php` прямо это оговаривает: удаление — решение,
 * а не уборка). Консолидация двух классов в один — отдельный шаг, и он требует
 * проверки, что дубликат не подгружается динамически.
 *
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $logo
 * @property string|null $email
 * @property string|null $phone
 * @property string $status
 * @property array|null $settings_json
 * @property \DateTimeImmutable $created_at
 * @property \DateTimeImmutable $updated_at
 */
class Organization extends Model
{
    protected $table = 'organizations';

    protected $fillable = [
        'public_id',
        'name',
        'slug',
        'description',
        'logo',
        'email',
        'phone',
        'status',
        'settings_json',
    ];

    protected $casts = [
        'settings_json' => 'array',
        'created_at' => 'datetime:Y-m-d H:i:s.u',
        'updated_at' => 'datetime:Y-m-d H:i:s.u',
    ];

    /**
     * Get all venues for this organization.
     */
    public function venues(): HasMany
    {
        return $this->hasMany(Venue::class, 'organization_id');
    }

    /**
     * Get all events for this organization.
     */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class, 'organization_id');
    }

    /**
     * Get all orders for this organization.
     */
    public function orders(): HasMany
    {
        return $this->hasMany(\Nabilet\Modules\Orders\Models\Order::class, 'organization_id');
    }

    /**
     * Get all API keys for this organization.
     */
    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class, 'organization_id');
    }

    /**
     * Get all checkin devices for this organization.
     */
    public function checkinDevices(): HasMany
    {
        return $this->hasMany(CheckinDevice::class, 'organization_id');
    }
}
