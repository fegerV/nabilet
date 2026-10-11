<?php

declare(strict_types=1);

namespace Nabilet\Modules\Content\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Redirect (ТЗ §37) — постоянный адрес источника → назначение.
 *
 * РАНЬШЕ МОДЕЛЬ НЕ СООТВЕТСТВОВАЛА ТАБЛИЦЕ. Она объявляла `source_path` и
 * `target_path`, тогда как `redirects` (nabilet_core_spec/migrations.sql:734)
 * хранит `source`, `destination`, `status_code`, `active`. Ошибка не проявлялась
 * только потому, что модель никто не читал: потребителей у неё не было вообще, а
 * расхождение было занесено в список исключений `tools/verify-models-schema.php`.
 * Как только redirect-менеджер появился (middleware `ResolveRedirect`), первое же
 * обращение к `source_path` дало бы «Unknown column» на КАЖДОМ запросе.
 *
 * @property int $id
 * @property string $source
 * @property string $destination
 * @property int $status_code 301|302|307|308
 * @property bool $active
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 */
class Redirect extends Model
{
    protected $table = 'redirects';

    protected $fillable = [
        'source',
        'destination',
        'status_code',
        'active',
    ];

    protected $casts = [
        'status_code' => 'integer',
        // `active` — TINYINT(1); без каста наружу уходит `0`/`1` вместо bool,
        // и `if ($redirect->active)` работает случайно, а `=== true` — нет.
        'active' => 'boolean',
    ];
}
