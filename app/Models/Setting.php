<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Универсальное key/value-хранилище настроек (scope + setting_key).
 *
 * Таблица `settings` несёт ТОЛЬКО `updated_at`: колонки `created_at` в спеке нет
 * (см. 2026_09_20_000800_008_integrations_system). Eloquent же по умолчанию
 * пишет обе метки времени, и первый же `create()`/`updateOrCreate()` падал с
 * «Unknown column 'created_at'». Поэтому времена отключены, а `updated_at`
 * выставляется вызывающим кодом явно.
 *
 * `$fillable` намеренно узкий: `encrypted` — признак того, что `value_json`
 * лежит зашифрованным, и менять его должен только тот код, который пишет
 * секрет (MailSettings), а не произвольный mass-assign.
 */
class Setting extends Model
{
    protected $table = 'settings';

    public $timestamps = false;

    protected $fillable = ['scope', 'setting_key', 'value_json', 'encrypted'];

    protected $casts = [
        'encrypted' => 'boolean',
        'updated_at' => 'datetime',
    ];
}
