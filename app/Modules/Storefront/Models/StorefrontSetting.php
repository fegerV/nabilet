<?php

declare(strict_types=1);

namespace Nabilet\Modules\Storefront\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Настройки витрины организации.
 *
 * `organization_id = NULL` — конфиг по умолчанию (то, что видит гость, когда
 * организация не определена). Строка организации перекрывает его целиком:
 * частичный merge двух конфигов порождал бы ситуацию «удалил секцию в одном
 * месте — она вернулась из дефолта», что администратор читает как баг.
 */
class StorefrontSetting extends Model
{
    protected $table = 'storefront_settings';

    protected $fillable = [
        'organization_id',
        'config',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'organization_id' => 'integer',
            'updated_by' => 'integer',
        ];
    }
}
