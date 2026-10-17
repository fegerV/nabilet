<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Support\Str;

/**
 * Дворец искусств «Нефтяник» (Сургут) — зал «Концертно-театральный зал» с
 * опубликованной схемой (1073 места, 4 сектора, цены по рядам с витрины
 * Яндекса). Логика — в базовом HallSchemaSeeder; здесь только адресаты.
 */
final class NeftyanikHallSeeder extends HallSchemaSeeder
{
    protected function venueSlug(): string
    {
        return 'dvorec-iskusstv-neftyanik';
    }

    protected function venueDisplayName(): string
    {
        return 'Дворец искусств «Нефтяник»';
    }

    protected function venueAddress(): string
    {
        return 'Энгельса проспект, 5';
    }

    protected function venueDescription(): string
    {
        return 'Крупнейшая концертная площадка Сургута: большая сцена, камерный зал, гастрольные мероприятия и фестивали.';
    }

    protected function hallName(): string
    {
        return 'Концертно-театральный зал';
    }

    protected function payloadFile(): string
    {
        return 'data/neftyanik-hall-schema.json';
    }
}
