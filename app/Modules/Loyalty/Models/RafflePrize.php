<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Призовое место розыгрыша (фаза 5).
 *
 * МЕСТО — СВОЙСТВО ПРИЗА, А НЕ ПОРЯДОК СТРОКИ
 *   `place` = 1, 2, 3... — номер позиции в розыгрыше, и у него есть
 *   инвариант схемы: `UNIQUE (raffle_id, place)`. Сортировка выборки по
 *   месту даёт порядок выдачи, а привязка победителя (`raffle_winners`)
 *   ссылается именно на приз — поэтому смена порядка строк в форме не
 *   меняет результатов уже проведённого тиража.
 *
 * ПРИЗ — СЛОВА, А НЕ ДЕНЬГИ
 *   `title`/`description` описывают, что получает победитель, и вручает это
 *   организатор. В схеме намеренно нет ни `*_amount`, ни ссылки на
 *   `bonus_transactions`: приз розыгрыша — не движение по счёту, и «крутить
 *   барабан» не должно быть способом получить бонусы (см. `022_raffles.sql`).
 *
 * КАСКАД ПО РОЗЫГРЫШУ
 *   Приз без розыгрыша не существует: `ON DELETE CASCADE` удалит и его, и
 *   ссылающихся на него победителей. Обратное направление (удаление приза
 *   при живом розыгрыше) невозможно — сервис отказывает в правке призов
 *   после тиража (`RAFFLE_ALREADY_DRAWN`).
 */
class RafflePrize extends Model
{
    protected $table = 'raffle_prizes';

    protected $fillable = [
        'public_id',
        'raffle_id',
        'place',
        'title',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'place' => 'integer',
            'created_at' => 'datetime:Y-m-d H:i:s.u',
            'updated_at' => 'datetime:Y-m-d H:i:s.u',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (RafflePrize $prize): void {
            if ($prize->public_id === null) {
                $prize->public_id = Str::ulid()->toBase32();
            }
        });
    }

    /** @return BelongsTo<Raffle, $this> */
    public function raffle(): BelongsTo
    {
        return $this->belongsTo(Raffle::class, 'raffle_id');
    }
}
