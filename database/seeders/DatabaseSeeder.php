<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(NabiletAdminSeeder::class);
        $this->call(NotificationTemplateSeeder::class);

        // Стартовый каталог площадок Сургута — ПОСЛЕ NabiletAdminSeeder.
        //
        // Порядок обязателен: `venues.organization_id` NOT NULL, а организацию
        // создаёт либо установщик (шаг 5, до `db:seed`), либо NabiletAdminSeeder
        // (демо-организация вне production). Запустив этот сидер первым, мы
        // получили бы «нет организации» и пустой каталог на свежей установке.
        $this->call(SurgutVenuesSeeder::class);

        // Зал «Концертно-театральный зал» Дворца искусств «Нефтяник» — вместе с
        // опубликованной схемой (1073 места, снята с Яндекс.Афиши и адаптирована
        // под редактор). После SurgutVenuesSeeder: нужна площадка из каталога.
        $this->call(NeftyanikHallSeeder::class);

        // Клуб «Вавилон»: основной зал со столовой схемой (62 стола, 359 мест).
        $this->call(BabylonHallSeeder::class);
    }
}
