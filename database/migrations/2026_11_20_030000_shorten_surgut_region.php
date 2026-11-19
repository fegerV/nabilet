<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Сократить название региона каталога Сургута до «ХМАО — Югра».
 *
 * Сидер меняет только новые строки: уже созданные 39 площадок он пропускает,
 * поэтому без этой миграции витрина продолжала бы отдавать длинное название
 * в PostalAddress.addressRegion и в подсказках geo.region.
 *
 * Миграция идемпотентна и не трогает площадки других заказчиков: обновляются
 * только строки с точным старым значением.
 */
return new class extends Migration
{
    private const OLD = 'Ханты-Мансийский автономный округ — Югра';
    private const NEW = 'ХМАО — Югра';

    public function up(): void
    {
        DB::table('venues')
            ->where('region', self::OLD)
            ->update(['region' => self::NEW]);
    }

    public function down(): void
    {
        DB::table('venues')
            ->where('region', self::NEW)
            ->update(['region' => self::OLD]);
    }
};
