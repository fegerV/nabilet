<?php

declare(strict_types=1);

namespace Nabilet\Modules\System\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Запись идемпотентности (`idempotency_keys`).
 *
 *Middleware работает с таблицей через query builder, но модель должна
 * описывать те же колонки: раньше здесь были `key`/`value`, которых в таблице
 * нет, и `incrementing = false` при AUTO_INCREMENT id. Любой будущий вызов
 * `IdempotencyKey::create()` молча ломал запись.
 *
 * @property int $id
 * @property string $scope
 * @property string $key_hash
 * @property string $request_hash
 * @property int|null $response_status
 * @property string|null $response_body
 * @property \Carbon\CarbonImmutable|null $locked_at
 * @property \Carbon\CarbonImmutable|null $expires_at
 */
class IdempotencyKey extends Model
{
    protected $table = 'idempotency_keys';

    public $incrementing = true;

    protected $keyType = 'int';

    // В таблице есть created_at, но нет updated_at.
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'scope',
        'key_hash',
        'request_hash',
        'response_status',
        'response_body',
        'locked_at',
        'expires_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'response_status' => 'integer',
        'locked_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
}
