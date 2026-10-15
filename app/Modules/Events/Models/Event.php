<?php

declare(strict_types=1);

namespace Nabilet\Modules\Events\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Event Model
 *
 * УДАЛЕНИЕ — МЯГКОЕ (SEO-аудит P-24). Трейт подключён не «на всякий случай»:
 * до него `DELETE /api/v1/events/{id}` выполнял физический `DELETE`, и это
 * имело три следствия, каждое из которых заметно только на живых данных.
 *
 *   1. `ON DELETE CASCADE` уносил вместе с событием сеансы, переводы, промокоды,
 *      пакеты офлайн-продаж, даты, спикеров, спонсоров, вопросы, артистов и
 *      программу — то есть историю заказов нельзя было восстановить даже
 *      теоретически. Одним запросом с кодом 204.
 *   2. `fk_tickets_event` объявлен `ON DELETE RESTRICT`: у события с проданными
 *      билетами физическое удаление падало ошибкой MySQL 1451 → 500. То есть
 *      «удалить» работало ровно до первой продажи и потом начинало отвечать
 *      серверной ошибкой.
 *   3. Публичный адрес исчезал мгновенно: `slug` освобождался, и его мог занять
 *      другой концерт — тот же URL начинал означать другое событие, причём
 *      молча. Индекс поисковика хранил ссылку, которая теперь ведёт не туда.
 *
 * Теперь строка остаётся, `deleted_at` заполняется, и вместе с ним:
 *   — slug остаётся ЗАНЯТЫМ (уникальный индекс не различает удалённые строки) —
 *     адрес нельзя переиспользовать под другое событие;
 *   — Eloquent скрывает удалённые события из списков, sitemap и витрины
 *     (глобальный scope трейта);
 *   — страница события отвечает 410 Gone, а не 404: ресурс существовал и
 *     исчез навсегда, и это единственный сигнал, по которому поисковик убирает
 *     URL из индекса быстро, не дожидаясь повторных обходов.
 */
class Event extends Model
{
    use SoftDeletes;
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

    /**
     * Артисты события — КАНОНИЧЕСКИЕ, а не строки программы (стратегия SEO, B-1).
     *
     * ЗАЧЕМ ОТДЕЛЬНАЯ СВЯЗЬ, ЕСЛИ ЕСТЬ `event_artists`. `event_artists` хранит
     * имя СВОБОДНЫМ ТЕКСТОМ внутри события: «Би-2» в одном мероприятии и
     * «БИ-2» в другом — две несвязанные строки, и адреса у них нет вовсе.
     * Здесь связь ведёт к канонической записи `artists`, у которой есть
     * `/artist/{slug}`, — ровно она нужна странице события, чтобы дать ссылку
     * на исполнителя. Подробнее — в докблоках `Artist` и `SyncArtistsCommand`.
     *
     * `status = 'active'` — ЧАСТЬ УСЛОВИЯ, А НЕ УКРАШЕНИЕ. Ссылка на снятого с
     * публикации артиста ведёт на 404, а ссылка в 404 обесценивает и страницу
     * события: краулер помечает её как «URL not found» и тратит бюджет на
     * заведомо мёртвый адрес. Тем же правилом артист отсеивается и на своей
     * странице (`CatalogService::artistBySlug()`).
     *
     * ПОРЯДОК ЗАДАЁТ СВЯЗЬ, А НЕ АЛФАВИТ. Хедлайнер идёт первым, затем по
     * `sort_order` — так же, как исполнителей показывает программа события.
     * Алфавит дал бы «Аквариум» выше хедлайнера, то есть список, не совпадающий
     * с афишей.
     *
     * `orderBy` НА КВАЛИФИЦИРОВАННЫХ КОЛОНКАХ, потому что в запросе есть join:
     * `artist_event` и `artists` содержат `sort_order`/`name` каждая по-своему
     * (в `artists` есть `name`), и неполное имя столбца дало бы
     * `SQLSTATE[23000] Column 'name' in order clause is ambiguous`.
     */
    public function artists(): BelongsToMany
    {
        return $this->belongsToMany(Artist::class, 'artist_event')
            ->where('artists.status', 'active')
            ->withPivot(['is_headliner', 'sort_order'])
            ->orderByDesc('artist_event.is_headliner')
            ->orderBy('artist_event.sort_order')
            ->orderBy('artists.name');
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
