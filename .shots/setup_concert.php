<?php

declare(strict_types=1);

/**
 * Setup тестового концерта: зал + схема + сектора + ряды с ценами + места
 * + событие + дата + сессия. Исполняется через artisan tinker.
 */

use Illuminate\Support\Str;

function genId(): string
{
    return Str::ulid()->toBase32();
}

$venue = \App\Models\Venue::where('slug', 'test-concert-hall')->first();
if (!$venue) {
    echo "VENUE NOT FOUND\n";
    exit(1);
}
echo "Venue: id={$venue->id} name={$venue->name}\n";

$orgId = 1;
$now = now();

// ---------- 1. Hall ----------
$hall = \Nabilet\Modules\Venues\Models\Hall::where('venue_id', $venue->id)->where('name', 'Большой концертный зал')->first();
if (!$hall) {
    $data = [
        'public_id' => genId(),
        'venue_id' => $venue->id,
        'name' => 'Большой концертный зал',
        'description' => 'Тестовый зал для концерта',
        'capacity' => 96,
        'width' => 60,
        'height' => 40,
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ];
    $hall = \Nabilet\Modules\Venues\Models\Hall::create($data);
    echo "Hall created: id={$hall->id}\n";
} else {
    echo "Hall exists: id={$hall->id}\n";
}

// ---------- 2. Schema version ----------
$schema = \Nabilet\Modules\Venues\Models\HallSchemaVersion::where('hall_id', $hall->id)->where('version', 1)->first();
if (!$schema) {
    $schemaJson = json_encode([
        'name' => 'Схема концертного зала',
        'width' => 60,
        'height' => 40,
        'sectors' => [
            ['name' => 'Партер', 'code' => 'P', 'type' => 'seated', 'x' => 0, 'y' => 0, 'width' => 60, 'height' => 40],
        ],
    ]);
    $schema = \Nabilet\Modules\Venues\Models\HallSchemaVersion::create([
        'public_id' => genId(),
        'hall_id' => $hall->id,
        'version' => 1,
        'status' => 'published',
        'width' => 60,
        'height' => 40,
        'schema_json' => $schemaJson,
        'published_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    echo "Schema created: id={$schema->id}\n";
} else {
    echo "Schema exists: id={$schema->id}\n";
}

// ---------- 3. Sector ----------
$sector = \Nabilet\Modules\Venues\Models\Sector::where('schema_version_id', $schema->id)->where('code', 'P')->first();
if (!$sector) {
    $sector = \Nabilet\Modules\Venues\Models\Sector::create([
        'public_id' => genId(),
        'schema_version_id' => $schema->id,
        'name' => 'Партер',
        'code' => 'P',
        'type' => 'seated',
        'x' => 0,
        'y' => 0,
        'width' => 60,
        'height' => 40,
        'color' => '#7c3aed',
        'capacity' => 96,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    echo "Sector created: id={$sector->id}\n";
} else {
    echo "Sector exists: id={$sector->id}\n";
}

// ---------- 4. Rows with different prices (копейки) ----------
$rowSpecs = [
    ['1', 'VIP', 500000],
    ['2', 'Премиум', 350000],
    ['3', 'Стандарт', 250000],
    ['4', 'Эконом+', 150000],
    ['5', 'Эконом', 100000],
];
$rowY = 5;
$rows = [];
foreach ($rowSpecs as $spec) {
    $row = \Nabilet\Modules\Venues\Models\HallRow::where('sector_id', $sector->id)->where('number', $spec[0])->first();
    if (!$row) {
        $row = \Nabilet\Modules\Venues\Models\HallRow::create([
            'public_id' => genId(),
            'sector_id' => $sector->id,
            'number' => $spec[0],
            'name' => $spec[1],
            'price_amount' => $spec[2],
            'currency' => 'RUB',
            'x' => 5,
            'y' => $rowY,
            'rotation' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        echo "Row {$spec[0]} ({$spec[1]}) created: " . round($spec[2] / 100) . " руб.\n";
    } else {
        echo "Row {$spec[0]} exists\n";
    }
    $rows[$spec[0]] = $row;
    $rowY += 6;
}

// ---------- 5. Seats ----------
$seatsCreated = 0;
foreach ($rowSpecs as $spec) {
    $row = $rows[$spec[0]];
    $seatCount = 12;
    for ($i = 1; $i <= $seatCount; $i++) {
        $exists = \Nabilet\Modules\Venues\Models\Seat::where('row_id', $row->id)->where('number', (string) $i)->first();
        if (!$exists) {
            \Nabilet\Modules\Venues\Models\Seat::create([
                'public_id' => genId(),
                'row_id' => $row->id,
                'number' => (string) $i,
                'label' => "Ряд {$spec[0]} Место {$i}",
                'x' => $i * 4,
                'y' => $row->y,
                'width' => 3,
                'height' => 3,
                'rotation' => 0,
                'type' => $spec[0] === '1' ? 'vip' : 'standard',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $seatsCreated++;
        }
    }
}
echo "Seats created: {$seatsCreated}\n";

// ---------- 6. Event ----------
$event = \Nabilet\Modules\Events\Models\Event::where('slug', 'test-concert')->first();
if (!$event) {
    $event = \Nabilet\Modules\Events\Models\Event::create([
        'public_id' => genId(),
        'organization_id' => $orgId,
        'title' => 'Тестовый концерт: классика в большом зале',
        'slug' => 'test-concert',
        'short_description' => 'Симфонический оркестр, 96 мест, 5 категорий цен',
        'description' => '<p>Тестовое мероприятие для проверки продажи билетов.</p><p>Ряд 1 (VIP) — 5000 ₽, Ряд 2 — 3500 ₽, Ряд 3 — 2500 ₽, Ряд 4 — 1500 ₽, Ряд 5 — 1000 ₽.</p>',
        'age_limit' => '0+',
        'duration_minutes' => 120,
        'status' => 'published',
        'published_at' => $now,
        'seo_title' => 'Тестовый концерт — купить билеты',
        'seo_description' => 'Концерт классической музыки, билеты от 1000 ₽',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    echo "Event created: id={$event->id}\n";
} else {
    echo "Event exists: id={$event->id}\n";
}

// ---------- 7. EventDate ----------
$eventDate = \App\Models\EventDate::where('event_id', $event->id)->first();
if (!$eventDate) {
    $start = $now->addDays(14)->setTime(19, 0, 0);
    $eventDate = \App\Models\EventDate::create([
        'public_id' => genId(),
        'event_id' => $event->id,
        'start_at' => $start,
        'end_at' => $start->addMinutes(120),
        'status' => 'scheduled',
        'name' => 'Основной концерт',
        'capacity' => 96,
        'is_sold_out' => false,
        'sales_start_at' => $now->subMinutes(5),
        'sales_end_at' => $start->subMinutes(30),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    echo "EventDate created: id={$eventDate->id}\n";
} else {
    echo "EventDate exists: id={$eventDate->id}\n";
}

// ---------- 8. Session ----------
$session = \Nabilet\Modules\Sessions\Models\Session::where('event_id', $event->id)->first();
if (!$session) {
    $session = \Nabilet\Modules\Sessions\Models\Session::create([
        'public_id' => genId(),
        'event_id' => $event->id,
        'venue_id' => $venue->id,
        'hall_id' => $hall->id,
        'schema_version_id' => $schema->id,
        'starts_at' => $eventDate->start_at,
        'ends_at' => $eventDate->end_at,
        'sales_start_at' => $now->subMinutes(5),
        'sales_end_at' => $eventDate->start_at->subMinutes(30),
        'timezone' => 'Asia/Yekaterinburg',
        'status' => 'on_sale',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    echo "Session created: id={$session->id}\n";
} else {
    echo "Session exists: id={$session->id}\n";
}

echo "\nDONE\n";
echo "Venue: {$venue->id}\nHall: {$hall->id}\nSchema: {$schema->id}\nSector: {$sector->id}\nRows: {$rows['1']->id}..{$rows['5']->id}\nEvent: {$event->id}\nEventDate: {$eventDate->id}\nSession: {$session->id}\n";