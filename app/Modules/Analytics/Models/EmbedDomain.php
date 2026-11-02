<?php

declare(strict_types=1);

namespace Nabilet\Modules\Analytics\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nabilet\Modules\Core\Organizations\Models\Organization;
use Nabilet\Modules\Core\Tenancy\TenantScoped;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $domain
 * @property bool $active
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 */
class EmbedDomain extends Model
{
    use TenantScoped;

    protected $table = 'embed_domains';

    /**
     * Глобальный читающий скоуп (сорок вторая модель; кластер embed-конфига
     * вместе с `ApiKey`). Whitelist доменов виджета — конфиг организатора:
     * чтение строго по владельцу; запись защищена трейтом, владелец наполняется
     * из staff-контекста, поэтому `organization_id` выведен из `$fillable`
     * (required-валидация в store сохранена — контракт API прежний).
     *
     * Гонка `createOrFirst()` на составном индексе `uq_embed_domains_org_domain`
     * под скоупом сохраняет пер-организационную семантику: дубль в своей
     * организации — 409, одинаковый домен у двух организаций — две легальные
     * строки (Origin-проверка всегда идёт по organization_id из embed-ключа).
     *
     * Origin-проверка виджета (`EmbedAccessService::assertOriginAllowed`) —
     * bypass: гость без контекста, организация уже выведена из ключа.
     * `update`/`destroy` адресуются `IdentifierLookup` (bypass по design) —
     * живой изоляции там нет, отдельный аудит.
     */
    protected static function tenantScopeEnabled(): bool
    {
        return true;
    }

    protected $fillable = [
        'domain',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
        'created_at' => 'datetime:Y-m-d H:i:s.u',
        'updated_at' => 'datetime:Y-m-d H:i:s.u',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
