<?php

declare(strict_types=1);

namespace Nabilet\Modules\Webhooks\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Nabilet\Modules\Core\Models\Concerns\HasPublicId;
use Nabilet\Modules\Core\Organizations\Models\Organization;

/**
 * Подписка на исходящие вебхуки — одна строка `webhooks`.
 *
 * Колонки: `public_id`, `organization_id`, `url`, `secret_encrypted`,
 * `events_json`, `active`, `retry_limit`. Владелец таблицы — этот класс.
 *
 * ЧТО ЗДЕСЬ БЫЛО НЕ ТАК. Рядом лежала ВТОРАЯ модель той же таблицы —
 * `System\Models\Webhook` — и объявляла `name`, `secret`, `events`, `is_active`.
 * Ни одной из этих колонок в таблице нет. Мёртвой она при этом не была:
 * `Organization::webhookEndpoints()` указывала именно на неё, то есть связь
 * выбирала несуществующие колонки, а `->create()` по ней упал бы на 42S22.
 * Её спутники `System\Models\WebhookDelivery` и `System\Models\WebhookEvent`
 * врали так же (`webhook_event_id`, `status`, `attempt_count`, `response_code`,
 * `event_type`, `payload`). Все три удалены, связь переведена сюда. Ровно этот
 * же дефект уже был закрыт для `api_keys` (см. докблок `System\Models\ApiKey`) —
 * `webhooks` остался недоделанным хвостом того же прохода.
 *
 * ПОЧЕМУ CAST НА `events_json` — ЭТО НЕ КОСМЕТИКА. Пока его не было, правило
 * «эта колонка — массив» повторялось в пяти местах: два `json_encode` при
 * записи (`store`, `update`), два ручных `is_string` + `json_decode` при чтении
 * (`present()` и `WebhookDispatchService::subscribesTo()`) и ещё один
 * `json_encode` в фикстуре теста. Каждое место формулировало правило само, и
 * первое же изменение формата разошлось бы с остальными. Теперь оно одно — здесь.
 *
 * `active` и `retry_limit` типизированы по той же причине: без cast'ов они
 * приходили строками (`"1"`, `"10"`) и сравнивались с числами в сервисе доставки.
 */
class Webhook extends Model
{
    use HasPublicId;

    protected $table = 'webhooks';

    protected $fillable = [
        'organization_id',
        'url',
        'secret_encrypted',
        'events_json',
        'active',
        'retry_limit',
    ];

    protected $casts = [
        'events_json' => 'array',
        'active' => 'boolean',
        'retry_limit' => 'integer',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }
}
