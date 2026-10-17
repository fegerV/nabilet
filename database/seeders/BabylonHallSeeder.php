<?php

declare(strict_types=1);

namespace Database\Seeders;

/**
 * Клуб «Вавилон» (Сургут) — зал «Основной зал» со столовой схемой
 * (62 стола, 359 мест, цены 1000–1800 ₽), снята с витрины Яндекса
 * и адаптирована под редактор. Логика — в базовом HallSchemaSeeder.
 */
final class BabylonHallSeeder extends HallSchemaSeeder
{
    protected function venueSlug(): string
    {
        return 'vavilon';
    }

    protected function venueDisplayName(): string
    {
        return 'Вавилон';
    }

    protected function venueAddress(): string
    {
        return 'ул. Университетская, 55';
    }

    protected function venueDescription(): string
    {
        return 'Мультиформатное арт-пространство: бургерная, бар, концерты. Здесь проходит всё: лекции и спектакли, вечеринки по пятницам («Особенный вечер», «Йодаль») и золотой клубный звук.';
    }

    protected function hallName(): string
    {
        return 'Основной зал';
    }

    protected function payloadFile(): string
    {
        return 'data/vavilon-hall-schema.json';
    }
}
