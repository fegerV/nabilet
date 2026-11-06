<?php

declare(strict_types=1);

/**
 * Что реально материализовалось в БД из опубликованных схем залов.
 *
 * Запуск (тестовая БД):
 *   DB_DATABASE=nabilet_test_suite DB_USERNAME=root DB_PASSWORD=rootpass \
 *     php .shots/hall-lab-db.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$halls = DB::table('halls')
    ->where('name', 'like', '%зал%')
    ->orderByDesc('id')
    ->limit(6)
    ->get(['id', 'public_id', 'name']);

printf("Залы в тестовой БД: %d\n\n", $halls->count());

foreach ($halls as $hall) {
    $versions = DB::table('hall_schema_versions')
        ->where('hall_id', $hall->id)
        ->orderBy('id')
        ->get(['id', 'version', 'revision', 'status', 'published_at']);

    echo "── {$hall->name}  [{$hall->public_id}]\n";
    foreach ($versions as $v) {
        echo "   версия #{$v->id} v{$v->version} rev={$v->revision} status={$v->status} published_at={$v->published_at}\n";

        $sectors = DB::table('sectors')->where('schema_version_id', $v->id)->get();
        $rows = 0;
        $seats = 0;
        $zones = 0;
        $zoneCap = 0;
        foreach ($sectors as $s) {
            $rowIds = DB::table('hall_rows')->where('sector_id', $s->id)->pluck('id');
            $rows += $rowIds->count();
            $seats += DB::table('seats')->whereIn('row_id', $rowIds)->count();
            $z = DB::table('standing_zones')->where('sector_id', $s->id)->get();
            $zones += $z->count();
            $zoneCap += (int) $z->sum('capacity');
        }
        printf(
            "      секторов=%d, рядов=%d, мест=%d, standing-зон=%d (вместимость=%d)\n",
            $sectors->count(), $rows, $seats, $zones, $zoneCap,
        );

        foreach ($sectors as $s) {
            $rowIds = DB::table('hall_rows')->where('sector_id', $s->id)->pluck('id');
            printf(
                "        • %-28s code=%-3s type=%-8s capacity=%-4d мест=%d цена_ряда=%s\n",
                $s->name, $s->code, $s->type, (int) $s->capacity,
                DB::table('seats')->whereIn('row_id', $rowIds)->count(),
                DB::table('hall_rows')->where('sector_id', $s->id)->value('price_amount'),
            );
        }

        $sessions = DB::table('sessions')->where('schema_version_id', $v->id)->pluck('id');
        if ($sessions->isNotEmpty()) {
            $items = DB::table('inventory_items')->whereIn('session_id', $sessions)->get();
            printf(
                "      инвентарь: позиций=%d (seat=%d, standing=%d), суммарная вместимость=%d, диапазон цен=%s..%s\n",
                $items->count(),
                $items->where('type', 'seat')->count(),
                $items->where('type', 'standing')->count(),
                (int) $items->sum('capacity'),
                $items->min('price_amount') ?? '-',
                $items->max('price_amount') ?? '-',
            );
        }
    }
    echo "\n";
}

echo 'hall_tables (таблица есть в схеме БД): ' . DB::table('hall_tables')->count() . " строк\n";
