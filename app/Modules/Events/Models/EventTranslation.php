<?php

declare(strict_types=1);

namespace Nabilet\Modules\Events\Models;

use Nabilet\Modules\Core\Tenancy\TenantScoped;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * EventTranslation Model
 */
class EventTranslation extends Model
{
    use TenantScoped;
    protected $table = 'event_translations';

    public $timestamps = false;

    protected $fillable = [
        'event_id',
        'locale',
        'title',
        'short_description',
        'description',
        'seo_title',
        'seo_description',
    ];

    /**
     * Глобальный читающий скоуп (двадцать шестая модель после Order, Payment,
     * Ticket, Refund, TicketScan, CheckinDevice, OfflineBundle,
     * PaymentTransaction, OrderItem, SeatHold, CartItem, Cart,
     * PromoCodeRedemption, WebhookDelivery, AbAssignment, AbVariant, AbMetric,
     * InventoryItem, EventFavorite, EventFollow, FollowNotification и
     * Raffle-кластера).
     *
     * Запись защищена трейтом (TenantResolver-карта ведёт через `event_id` →
     * `events`). Прямых читателей `::query()` нет — строки только
     * eager-загрузкой с витрины (`EventController::show`/`showBySlug`,
     * embed-виджет и SPA-страница события), и все четыре точки обходят скоуп
     * в bypass: у гостя контекста нет, адрес мероприятия и так уже решён
     * lookup'ом самого события.
     */
    protected static function tenantScopeEnabled(): bool
    {
        return true;
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
