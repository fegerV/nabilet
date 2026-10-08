<?php

declare(strict_types=1);

namespace Nabilet\Modules\Events\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Event Model
 */
class Event extends Model
{
    protected $table = 'events';

    public $timestamps = true;

    /** Конвенция проекта: public_id = ULID (base32) */
    protected static function booted(): void
    {
        static::creating(function (Event $event) {
            if ($event->public_id === null) {
                $event->public_id = Str::ulid()->toBase32();
            }
        });
    }

    protected $fillable = [
        'public_id',
        'organization_id',
        'category_id',
        // Макет билета для этого мероприятия. NULL — стандартный вид билета
        // (см. TicketTemplateResolver). Необязателен намеренно: требовать шаблон
        // ДО создания мероприятия значило бы блокировать продажу ради оформления.
        'ticket_template_id',
        'title',
        'slug',
        'short_description',
        'description',
        'poster',
        'cover',
        'age_limit',
        'duration_minutes',
        'status',
        'published_at',
        'seo_title',
        'seo_description',
        'canonical_url',
        'robots',
    ];

    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'ticket_template_id' => 'integer',
            'published_at' => 'datetime:Y-m-d H:i:s.u',
            'created_at' => 'datetime:Y-m-d H:i:s.u',
            'updated_at' => 'datetime:Y-m-d H:i:s.u',
            'deleted_at' => 'datetime:Y-m-d H:i:s.u',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(\Nabilet\Modules\Core\Organizations\Models\Organization::class);
    }

    /**
     * Шаблон оформления билета.
     *
     * Связь может быть null — либо шаблон не назначен, либо его удалили
     * (FK `ON DELETE SET NULL`). Оба случая обрабатываются одинаково: билет
     * уходит в стандартном виде, а не остаётся без оформления.
     */
    public function ticketTemplate(): BelongsTo
    {
        return $this->belongsTo(\Nabilet\Modules\Tickets\Models\TicketTemplate::class, 'ticket_template_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(EventCategory::class, 'category_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(\Nabilet\Modules\Sessions\Models\Session::class);
    }

    public function translations(): HasMany
    {
        return $this->hasMany(EventTranslation::class);
    }

    public function promoCodes(): HasMany
    {
        return $this->hasMany(\Nabilet\Modules\Orders\Models\PromoCode::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(\Nabilet\Modules\Tickets\Models\Ticket::class);
    }

    /**
     * Дополнительные фото и видео мероприятия.
     *
     * ПОЧЕМУ `entity_id`, А НЕ `event_id`
     *
     * `media_links` — ОДНА таблица для всех видов объектов (мероприятия,
     * площадки, залы, организации): у неё полиморфный ключ
     * `entity_type` + `entity_id` и индекс `idx_media_links_entity` по нему.
     * Поэтому связь задаётся парой «тип + идентификатор», и условие по типу
     * здесь обязательно: без него `entity_id = 5` подтянул бы файлы зала с
     * тем же номером. Это не «лишний where», а вторая половина ключа.
     *
     * Связь ведёт к `media_links`, а не к `media_assets` напрямую: роль и
     * позиция файла принадлежат связи, и один и тот же файл может быть
     * одновременно афишей и элементом галереи.
     *
     * РОЛЬ ФИЛЬТРУЕТСЯ ЗДЕСЬ, А НЕ У ПОТРЕБИТЕЛЯ. Файл, назначенный афишей
     * (`role = poster`), не должен попадать в галерею: афиша показывается
     * отдельным блоком, и без этого условия она появилась бы на странице
     * дважды. Оба потребителя — админский `EventGalleryController` и публичная
     * страница события — получают одинаковый набор.
     */
    public function galleryLinks(): HasMany
    {
        return $this->hasMany(\Nabilet\Modules\Media\Models\MediaLink::class, 'entity_id')
            ->where('entity_type', \Nabilet\Modules\Media\Domain\MediaEntityType::EVENT)
            ->where('role', \Nabilet\Modules\Media\Domain\MediaRole::GALLERY)
            ->orderBy('position')
            ->orderBy('id');
    }
}
