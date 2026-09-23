# Руководство по расширению информации о мероприятиях в NABILET

## Обзор

Это руководство описывает три подхода к расширению контента мероприятий в системе NABILET: от быстрого прототипирования до production-решений с полной типизацией.

---

## Три подхода к расширению контента

### 1. JSON-поле `meta` (для быстрого старта)

Использует существующее поле `meta` в таблице `events` (или добавляет его).

**Подходит для:**
- Биографий спикеров
- Логотипов спонсоров
- FAQ
- Расписания
- Артистов

**✅ Преимущества:**
- Не требует миграций (если поле уже существует)
- Гибкая структура данных
- Быстрая итерация при разработке

**❌ Недостатки:**
- Сложнее фильтровать и искать по данным
- Нет строгой типизации
- Нет внешних ключей и связей

#### Пример структуры JSON

```json
{
  "speakers": [
    {
      "id": "spk_abc123",
      "name": "Иван Петров",
      "bio": "Эксперт в области...",
      "avatar": "/uploads/speakers/ivan.jpg",
      "position": "CTO @ Company",
      "social_links": {
        "telegram": "@ivan",
        "linkedin": "ivan-petrov"
      }
    }
  ],
  "sponsors": [
    {
      "id": "spn_xyz789",
      "name": "TechCorp",
      "logo": "/uploads/sponsors/techcorp.png",
      "tier": "gold",
      "website": "https://techcorp.com"
    }
  ],
  "faqs": [
    {
      "question": "Как купить билет?",
      "answer": "Нажмите кнопку 'Купить'..."
    }
  ],
  "schedule": [
    {
      "time": "10:00",
      "title": "Регистрация",
      "duration_minutes": 30
    },
    {
      "time": "10:30",
      "title": "Открытие конференции",
      "speaker_ids": ["spk_abc123"],
      "duration_minutes": 45
    }
  ],
  "artists": [
    {
      "name": "DJ NightWave",
      "genre": "Electronic",
      "photo": "/uploads/artists/nightwave.jpg"
    }
  ]
}
```

---

### 2. Отдельные таблицы (для production)

Создаются нормализованные таблицы для каждого типа контента.

**Таблицы:**
- `event_speakers` — спикеры мероприятий
- `event_sponsors` — спонсоры мероприятий
- `event_faqs` — часто задаваемые вопросы
- `event_artists` — артисты (для концертов, фестивалей)
- `event_schedule_items` — элементы расписания

**✅ Преимущества:**
- Строгая типизация данных
- Возможность фильтрации и поиска
- Внешние ключи и целостность данных
- Производительность на больших объёмах
- Возможность связей между сущностями

**❌ Недостатки:**
- Требует миграций БД
- Фиксированная схема (сложнее изменять)
- Больше кода для поддержки

---

### 3. Гибридный подход (рекомендуется)

Комбинирует преимущества обоих подходов.

**Стратегия:**
- **JSON `meta`** для простых данных:
  - FAQ
  - Артисты (без сложных связей)
  - Партнёры второго уровня
  
- **Отдельные таблицы** для критически важных сущностей:
  - Спикеры (связи с другими мероприятиями, профили)
  - Спонсоры (типы, контракты, аналитика)
  - Расписание (поиск по времени, конфликты)

**✅ Преимущества:**
- Баланс между гибкостью и производительностью
- Критические данные типизированы
- Быстрое добавление вспомогательного контента

---

## Миграции базы данных

### Migration: Добавление поля `meta` в таблицу `events`

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            if (!Schema::hasColumn('events', 'meta')) {
                $table->json('meta')->nullable()->after('robots');
            }
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->dropColumn('meta');
        });
    }
};
```

### Migration: Создание таблиц для отдельных сущностей

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Таблица спикеров
        Schema::create('event_speakers', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26);
            $table->unsignedBigInteger('event_id');
            $table->string('name', 255);
            $table->text('bio')->nullable();
            $table->string('avatar', 2048)->nullable();
            $table->string('position', 255)->nullable();
            $table->string('company', 255)->nullable();
            $table->json('social_links')->nullable(); // {"telegram": "...", "linkedin": "..."}
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_featured')->default(false);
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            
            $table->unique(['public_id'], 'uq_event_speakers_public_id');
            $table->index(['event_id'], 'idx_event_speakers_event');
            $table->foreign('event_id')->references('id')->on('events')->onDelete('cascade');
        });

        // Таблица спонсоров
        Schema::create('event_sponsors', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26);
            $table->unsignedBigInteger('event_id');
            $table->string('name', 255);
            $table->string('logo', 2048)->nullable();
            $table->string('tier', 64)->default('standard'); // gold, silver, bronze, standard
            $table->string('website', 2048)->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_featured')->default(false);
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            
            $table->unique(['public_id'], 'uq_event_sponsors_public_id');
            $table->index(['event_id'], 'idx_event_sponsors_event');
            $table->index(['tier'], 'idx_event_sponsors_tier');
            $table->foreign('event_id')->references('id')->on('events')->onDelete('cascade');
        });

        // Таблица FAQ
        Schema::create('event_faqs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('event_id');
            $table->string('question', 500);
            $table->text('answer');
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('category', 100)->nullable(); // tickets, venue, schedule
            $table->boolean('is_active')->default(true);
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            
            $table->index(['event_id'], 'idx_event_faqs_event');
            $table->index(['is_active', 'sort_order'], 'idx_event_faqs_active_sort');
            $table->foreign('event_id')->references('id')->on('events')->onDelete('cascade');
        });

        // Таблица артистов
        Schema::create('event_artists', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26);
            $table->unsignedBigInteger('event_id');
            $table->string('name', 255);
            $table->text('bio')->nullable();
            $table->string('photo', 2048)->nullable();
            $table->string('genre', 100)->nullable();
            $table->json('social_links')->nullable();
            $table->dateTime('performance_time', 6)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_headliner')->default(false);
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            
            $table->unique(['public_id'], 'uq_event_artists_public_id');
            $table->index(['event_id'], 'idx_event_artists_event');
            $table->index(['performance_time'], 'idx_event_artists_time');
            $table->foreign('event_id')->references('id')->on('events')->onDelete('cascade');
        });

        // Таблица элементов расписания
        Schema::create('event_schedule_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('event_id');
            $table->string('title', 500);
            $table->text('description')->nullable();
            $table->dateTime('start_time', 6);
            $table->unsignedInteger('duration_minutes');
            $table->string('location', 255)->nullable(); // зал, сцена
            $table->unsignedBigInteger('speaker_id')->nullable();
            $table->json('speaker_ids')->nullable(); // для нескольких спикеров [1,2,3]
            $table->string('session_type', 64)->nullable(); // keynote, workshop, panel, break
            $table->unsignedInteger('sort_order')->default(0);
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            
            $table->index(['event_id'], 'idx_schedule_items_event');
            $table->index(['event_id', 'start_time'], 'idx_schedule_items_event_time');
            $table->foreign('event_id')->references('id')->on('events')->onDelete('cascade');
            $table->foreign('speaker_id')->references('id')->on('event_speakers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_schedule_items');
        Schema::dropIfExists('event_artists');
        Schema::dropIfExists('event_faqs');
        Schema::dropIfExists('event_sponsors');
        Schema::dropIfExists('event_speakers');
    }
};
```

---

## Модели Eloquent

### Event Model (обновлённая)

```php
<?php

declare(strict_types=1);

namespace Nabilet\Modules\Events\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Event Model
 */
class Event extends Model
{
    use HasFactory;

    protected $table = 'events';

    public $timestamps = true;

    protected $fillable = [
        'public_id',
        'organization_id',
        'category_id',
        'title',
        'slug',
        'short_description',
        'description',
        'poster',
        'cover',
        'age_limit',
        'duration_minutes',
        'status',
        'published_at',
        'seo_title',
        'seo_description',
        'canonical_url',
        'robots',
        'meta', // Добавлено для JSON-данных
    ];

    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'published_at' => 'datetime:Y-m-d H:i:s.u',
            'created_at' => 'datetime:Y-m-d H:i:s.u',
            'updated_at' => 'datetime:Y-m-d H:i:s.u',
            'deleted_at' => 'datetime:Y-m-d H:i:s.u',
            'meta' => 'array', // Автоматическая каста JSON в массив
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(\Nabilet\Modules\Core\Organizations\Models\Organization::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(EventCategory::class, 'category_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(\Nabilet\Modules\Sessions\Models\Session::class);
    }

    public function translations(): HasMany
    {
        return $this->hasMany(EventTranslation::class);
    }

    public function promoCodes(): HasMany
    {
        return $this->hasMany(\Nabilet\Modules\Orders\Models\PromoCode::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(\Nabilet\Modules\Tickets\Models\Ticket::class);
    }

    // === Новые отношения для расширенного контента ===

    public function speakers(): HasMany
    {
        return $this->hasMany(EventSpeaker::class)->orderBy('sort_order');
    }

    public function sponsors(): HasMany
    {
        return $this->hasMany(EventSponsor::class)->orderBy('sort_order');
    }

    public function faqs(): HasMany
    {
        return $this->hasMany(EventFaq::class)->where('is_active', true)->orderBy('sort_order');
    }

    public function artists(): HasMany
    {
        return $this->hasMany(EventArtist::class)->orderBy('sort_order');
    }

    public function scheduleItems(): HasMany
    {
        return $this->hasMany(EventScheduleItem::class)->orderBy('start_time');
    }

    // === Scopes для фильтрации ===

    public function scopeWithSpeakers($query)
    {
        return $query->with('speakers');
    }

    public function scopeWithSponsors($query)
    {
        return $query->with('sponsors');
    }

    public function scopeWithSchedule($query)
    {
        return $query->with('scheduleItems');
    }

    public function scopeWithFullContent($query)
    {
        return $query->with([
            'speakers',
            'sponsors',
            'faqs',
            'artists',
            'scheduleItems',
        ]);
    }

    public function scopeFeaturedSpeakers($query)
    {
        return $query->whereHas('speakers', fn($q) => $q->where('is_featured', true));
    }

    public function scopeHasActiveFaqs($query)
    {
        return $query->whereHas('faqs', fn($q) => $q->where('is_active', true));
    }
}
```

### EventSpeaker Model

```php
<?php

declare(strict_types=1);

namespace Nabilet\Modules\Events\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class EventSpeaker extends Model
{
    protected $table = 'event_speakers';

    public $timestamps = true;

    protected $fillable = [
        'public_id',
        'event_id',
        'name',
        'bio',
        'avatar',
        'position',
        'company',
        'social_links',
        'sort_order',
        'is_featured',
    ];

    protected function casts(): array
    {
        return [
            'social_links' => 'array',
            'sort_order' => 'integer',
            'is_featured' => 'boolean',
            'created_at' => 'datetime:Y-m-d H:i:s.u',
            'updated_at' => 'datetime:Y-m-d H:i:s.u',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    // Спикер может выступать на нескольких мероприятиях
    public function events(): BelongsToMany
    {
        return $this->belongsToMany(Event::class, 'event_speaker_schedule')
                    ->withPivot('start_time', 'duration_minutes')
                    ->withTimestamps();
    }

    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }
}
```

### EventSponsor Model

```php
<?php

declare(strict_types=1);

namespace Nabilet\Modules\Events\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventSponsor extends Model
{
    protected $table = 'event_sponsors';

    public $timestamps = true;

    protected $fillable = [
        'public_id',
        'event_id',
        'name',
        'logo',
        'tier',
        'website',
        'description',
        'sort_order',
        'is_featured',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_featured' => 'boolean',
            'created_at' => 'datetime:Y-m-d H:i:s.u',
            'updated_at' => 'datetime:Y-m-d H:i:s.u',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function scopeByTier($query, string $tier)
    {
        return $query->where('tier', $tier);
    }

    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }
}
```

### EventFaq Model

```php
<?php

declare(strict_types=1);

namespace Nabilet\Modules\Events\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventFaq extends Model
{
    protected $table = 'event_faqs';

    public $timestamps = true;

    protected $fillable = [
        'event_id',
        'question',
        'answer',
        'sort_order',
        'category',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'created_at' => 'datetime:Y-m-d H:i:s.u',
            'updated_at' => 'datetime:Y-m-d H:i:s.u',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }
}
```

### EventArtist Model

```php
<?php

declare(strict_types=1);

namespace Nabilet\Modules\Events\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventArtist extends Model
{
    protected $table = 'event_artists';

    public $timestamps = true;

    protected $fillable = [
        'public_id',
        'event_id',
        'name',
        'bio',
        'photo',
        'genre',
        'social_links',
        'performance_time',
        'sort_order',
        'is_headliner',
    ];

    protected function casts(): array
    {
        return [
            'social_links' => 'array',
            'performance_time' => 'datetime:Y-m-d H:i:s.u',
            'sort_order' => 'integer',
            'is_headliner' => 'boolean',
            'created_at' => 'datetime:Y-m-d H:i:s.u',
            'updated_at' => 'datetime:Y-m-d H:i:s.u',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function scopeHeadliners($query)
    {
        return $query->where('is_headliner', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }
}
```

### EventScheduleItem Model

```php
<?php

declare(strict_types=1);

namespace Nabilet\Modules\Events\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventScheduleItem extends Model
{
    protected $table = 'event_schedule_items';

    public $timestamps = true;

    protected $fillable = [
        'event_id',
        'title',
        'description',
        'start_time',
        'duration_minutes',
        'location',
        'speaker_id',
        'speaker_ids',
        'session_type',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'start_time' => 'datetime:Y-m-d H:i:s.u',
            'duration_minutes' => 'integer',
            'speaker_ids' => 'array',
            'sort_order' => 'integer',
            'created_at' => 'datetime:Y-m-d H:i:s.u',
            'updated_at' => 'datetime:Y-m-d H:i:s.u',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function speaker(): BelongsTo
    {
        return $this->belongsTo(EventSpeaker::class, 'speaker_id');
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('session_type', $type);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('start_time');
    }

    // Проверка на пересечение по времени
    public function scopeOverlapping($query, $startTime, $endTime)
    {
        return $query->where(function ($q) use ($startTime, $endTime) {
            $q->whereBetween('start_time', [$startTime, $endTime])
              ->orWhere(function ($q2) use ($startTime, $endTime) {
                  $q2->where('start_time', '<=', $startTime)
                     ->whereRaw('DATE_ADD(start_time, INTERVAL duration_minutes MINUTE) > ?', [$startTime]);
              });
        });
    }
}
```

---

## Интеграция с Filament Admin Panel

### EventResource с расширенными формами

```php
<?php

namespace Nabilet\Modules\Events\Filament\Resources;

use Nabilet\Modules\Events\Models\Event;
use Nabilet\Modules\Events\Models\EventSpeaker;
use Nabilet\Modules\Events\Models\EventSponsor;
use Nabilet\Modules\Events\Models\EventFaq;
use Nabilet\Modules\Events\Models\EventArtist;
use Nabilet\Modules\Events\Models\EventScheduleItem;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class EventResource extends Resource
{
    protected static ?string $model = Event::class;
    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Основная информация')
                    ->schema([
                        Forms\Components\TextInput::make('title')
                            ->required()
                            ->maxLength(500),
                        Forms\Components\TextInput::make('slug')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        Forms\Components\Select::make('category_id')
                            ->relationship('category', 'name'),
                        Forms\Components\Textarea::make('short_description')
                            ->rows(3),
                        Forms\Components\RichEditor::make('description')
                            ->columnSpanFull(),
                    ])->columns(2),

                Forms\Components\Section::make('Медиа')
                    ->schema([
                        Forms\Components\FileUpload::make('poster')
                            ->image()
                            ->directory('events/posters'),
                        Forms\Components\FileUpload::make('cover')
                            ->image()
                            ->directory('events/covers'),
                    ])->columns(2),

                Forms\Components\Section::make('Детали')
                    ->schema([
                        Forms\Components\Select::make('status')
                            ->options([
                                'draft' => 'Черновик',
                                'pending_review' => 'На проверке',
                                'published' => 'Опубликовано',
                                'archived' => 'Архив',
                            ])
                            ->required(),
                        Forms\Components\DateTimePicker::make('published_at'),
                        Forms\Components\TextInput::make('age_limit')
                            ->maxLength(32),
                        Forms\Components\TextInput::make('duration_minutes')
                            ->numeric(),
                    ])->columns(3),

                // === Расширенный контент ===
                
                Forms\Components\Section::make('Спикеры')
                    ->schema([
                        Forms\Components\Repeater::make('speakers')
                            ->relationship()
                            ->schema([
                                Forms\Components\TextInput::make('name')
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\Textarea::make('bio')
                                    ->rows(3),
                                Forms\Components\FileUpload::make('avatar')
                                    ->image(),
                                Forms\Components\TextInput::make('position')
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('company')
                                    ->maxLength(255),
                                Forms\Components\KeyValue::make('social_links')
                                    ->keyLabel('Платформа')
                                    ->valueLabel('Ссылка/Ник'),
                                Forms\Components\Toggle::make('is_featured')
                                    ->label('Главный спикер'),
                                Forms\Components\TextInput::make('sort_order')
                                    ->numeric()
                                    ->default(0),
                            ])->orderColumn('sort_order'),
                    ])->collapsible(),

                Forms\Components\Section::make('Спонсоры')
                    ->schema([
                        Forms\Components\Repeater::make('sponsors')
                            ->relationship()
                            ->schema([
                                Forms\Components\TextInput::make('name')
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\FileUpload::make('logo')
                                    ->image(),
                                Forms\Components\Select::make('tier')
                                    ->options([
                                        'gold' => 'Золотой',
                                        'silver' => 'Серебряный',
                                        'bronze' => 'Бронзовый',
                                        'standard' => 'Стандартный',
                                    ])
                                    ->default('standard'),
                                Forms\Components\TextInput::make('website')
                                    ->url(),
                                Forms\Components\Textarea::make('description'),
                                Forms\Components\Toggle::make('is_featured'),
                                Forms\Components\TextInput::make('sort_order')
                                    ->numeric()
                                    ->default(0),
                            ])->orderColumn('sort_order'),
                    ])->collapsible(),

                Forms\Components\Section::make('FAQ')
                    ->schema([
                        Forms\Components\Repeater::make('faqs')
                            ->relationship()
                            ->schema([
                                Forms\Components\TextInput::make('question')
                                    ->required()
                                    ->maxLength(500),
                                Forms\Components\Textarea::make('answer')
                                    ->required()
                                    ->rows(3),
                                Forms\Components\Select::make('category')
                                    ->options([
                                        'tickets' => 'Билеты',
                                        'venue' => 'Площадка',
                                        'schedule' => 'Расписание',
                                        'other' => 'Другое',
                                    ]),
                                Forms\Components\Toggle::make('is_active')
                                    ->default(true),
                                Forms\Components\TextInput::make('sort_order')
                                    ->numeric()
                                    ->default(0),
                            ])->orderColumn('sort_order'),
                    ])->collapsible(),

                Forms\Components\Section::make('Артисты')
                    ->schema([
                        Forms\Components\Repeater::make('artists')
                            ->relationship()
                            ->schema([
                                Forms\Components\TextInput::make('name')
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\Textarea::make('bio'),
                                Forms\Components\FileUpload::make('photo')
                                    ->image(),
                                Forms\Components\TextInput::make('genre')
                                    ->maxLength(100),
                                Forms\Components\KeyValue::make('social_links'),
                                Forms\Components\DateTimePicker::make('performance_time'),
                                Forms\Components\Toggle::make('is_headliner')
                                    ->label('Хедлайнер'),
                                Forms\Components\TextInput::make('sort_order')
                                    ->numeric()
                                    ->default(0),
                            ])->orderColumn('sort_order'),
                    ])->collapsible(),

                Forms\Components\Section::make('Расписание')
                    ->schema([
                        Forms\Components\Repeater::make('scheduleItems')
                            ->relationship()
                            ->schema([
                                Forms\Components\TextInput::make('title')
                                    ->required()
                                    ->maxLength(500),
                                Forms\Components\Textarea::make('description'),
                                Forms\Components\DateTimePicker::make('start_time')
                                    ->required(),
                                Forms\Components\TextInput::make('duration_minutes')
                                    ->required()
                                    ->numeric(),
                                Forms\Components\TextInput::make('location')
                                    ->maxLength(255),
                                Forms\Components\Select::make('session_type')
                                    ->options([
                                        'keynote' => 'Keynote',
                                        'workshop' => 'Воркшоп',
                                        'panel' => 'Панельная дискуссия',
                                        'break' => 'Перерыв',
                                        'registration' => 'Регистрация',
                                    ]),
                                Forms\Components\TextInput::make('sort_order')
                                    ->numeric()
                                    ->default(0),
                            ])->orderColumn('sort_order'),
                    ])->collapsible(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn(string $state): string => match($state) {
                        'published' => 'success',
                        'draft' => 'gray',
                        'pending_review' => 'warning',
                        'archived' => 'danger',
                    }),
                Tables\Columns\TextColumn::make('published_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('speakers_count')
                    ->counts('speakers')
                    ->label('Спикеры'),
                Tables\Columns\TextColumn::make('sponsors_count')
                    ->counts('sponsors')
                    ->label('Спонсоры'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'draft' => 'Черновик',
                        'published' => 'Опубликовано',
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }
}
```

### Отдельные ресурсы для спикеров и спонсоров

```php
<?php

namespace Nabilet\Modules\Events\Filament\Resources;

use Nabilet\Modules\Events\Models\EventSpeaker;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class EventSpeakerResource extends Resource
{
    protected static ?string $model = EventSpeaker::class;
    protected static ?string $navigationIcon = 'heroicon-o-user-group';
    protected static ?string $navigationGroup = 'Events';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('event_id')
                    ->relationship('event', 'title')
                    ->required()
                    ->searchable(),
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Textarea::make('bio')
                    ->rows(5),
                Forms\Components\FileUpload::make('avatar')
                    ->image()
                    ->directory('speakers'),
                Forms\Components\TextInput::make('position')
                    ->maxLength(255),
                Forms\Components\TextInput::make('company')
                    ->maxLength(255),
                Forms\Components\KeyValue::make('social_links'),
                Forms\Components\Toggle::make('is_featured'),
                Forms\Components\TextInput::make('sort_order')
                    ->numeric()
                    ->default(0),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('avatar')
                    ->circular(),
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('event.title')
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_featured')
                    ->boolean(),
            ])
            ->filters([])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }
}
```

---

## Blade-компоненты для фронтенда

### Компонент спикеров

```blade
{{-- resources/views/components/events/speakers.blade.php --}}
@props(['speakers'])

@if($speakers->isNotEmpty())
<section class="py-12 bg-gray-50">
    <div class="container mx-auto px-4">
        <h2 class="text-3xl font-bold text-center mb-8">Спикеры</h2>
        
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            @foreach($speakers as $speaker)
                <div class="bg-white rounded-lg shadow-md overflow-hidden hover:shadow-lg transition-shadow">
                    @if($speaker->avatar)
                        <img src="{{ $speaker->avatar }}" alt="{{ $speaker->name }}" 
                             class="w-full h-64 object-cover">
                    @else
                        <div class="w-full h-64 bg-gray-200 flex items-center justify-center">
                            <svg class="w-24 h-24 text-gray-400" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M24 20.993V24H0v-2.996A14.977 14.977 0 0112.004 15c4.904 0 9.26 2.354 11.996 5.993zM16.002 8.999a4 4 0 11-8 0 4 4 0 018 0z"/>
                            </svg>
                        </div>
                    @endif
                    
                    <div class="p-6">
                        <h3 class="text-xl font-semibold mb-2">{{ $speaker->name }}</h3>
                        
                        @if($speaker->position || $speaker->company)
                            <p class="text-gray-600 mb-3">
                                {{ $speaker->position }}
                                @if($speaker->position && $speaker->company) @endif
                                {{ $speaker->company }}
                            </p>
                        @endif
                        
                        @if($speaker->bio)
                            <p class="text-gray-700 mb-4 line-clamp-3">{{ $speaker->bio }}</p>
                        @endif
                        
                        @if($speaker->social_links)
                            <div class="flex space-x-3">
                                @if(isset($speaker->social_links['telegram']))
                                    <a href="https://t.me/{{ str_replace('@', '', $speaker->social_links['telegram']) }}" 
                                       class="text-blue-500 hover:text-blue-600">
                                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                                            <path d="M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z"/>
                                        </svg>
                                    </a>
                                @endif
                                @if(isset($speaker->social_links['linkedin']))
                                    <a href="https://linkedin.com/in/{{ $speaker->social_links['linkedin'] }}" 
                                       class="text-blue-700 hover:text-blue-800">
                                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                                            <path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433c-1.144 0-2.063-.926-2.063-2.065 0-1.138.92-2.063 2.063-2.063 1.14 0 2.064.925 2.064 2.063 0 1.139-.925 2.065-2.064 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/>
                                        </svg>
                                    </a>
                                @endif
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</section>
@endif
```

### Компонент спонсоров

```blade
{{-- resources/views/components/events/sponsors.blade.php --}}
@props(['sponsors'])

@if($sponsors->isNotEmpty())
<section class="py-12 bg-white">
    <div class="container mx-auto px-4">
        <h2 class="text-3xl font-bold text-center mb-8">Партнёры и спонсоры</h2>
        
        @php
            $tiers = ['gold', 'silver', 'bronze', 'standard'];
        @endphp
        
        @foreach($tiers as $tier)
            @php
                $tierSponsors = $sponsors->where('tier', $tier);
            @endphp
            
            @if($tierSponsors->isNotEmpty())
                <div class="mb-10">
                    <h3 class="text-xl font-semibold mb-4 text-gray-700 capitalize">
                        @lang('events.sponsors.tier.' . $tier, [], app()->getLocale())
                    </h3>
                    
                    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-6">
                        @foreach($tierSponsors as $sponsor)
                            <div class="flex flex-col items-center">
                                @if($sponsor->logo)
                                    <a href="{{ $sponsor->website ?? '#' }}" target="_blank" rel="noopener noreferrer">
                                        <img src="{{ $sponsor->logo }}" alt="{{ $sponsor->name }}" 
                                             class="h-20 w-auto object-contain hover:opacity-80 transition-opacity">
                                    </a>
                                @endif
                                
                                @if($sponsor->website)
                                    <a href="{{ $sponsor->website }}" target="_blank" rel="noopener noreferrer" 
                                       class="text-sm text-gray-600 hover:text-gray-900 mt-2">
                                        {{ $sponsor->name }}
                                    </a>
                                @else
                                    <span class="text-sm text-gray-600 mt-2">{{ $sponsor->name }}</span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        @endforeach
    </div>
</section>
@endif
```

### Компонент FAQ

```blade
{{-- resources/views/components/events/faq.blade.php --}}
@props(['faqs'])

@if($faqs->isNotEmpty())
<section class="py-12 bg-gray-50">
    <div class="container mx-auto px-4 max-w-4xl">
        <h2 class="text-3xl font-bold text-center mb-8">Часто задаваемые вопросы</h2>
        
        <div class="space-y-4">
            @foreach($faqs as $faq)
                <div class="bg-white rounded-lg shadow-sm overflow-hidden" 
                     x-data="{ open: false }">
                    <button @click="open = !open" 
                            class="w-full px-6 py-4 text-left flex justify-between items-center hover:bg-gray-50 transition-colors">
                        <span class="font-medium text-gray-900">{{ $faq->question }}</span>
                        <svg class="w-5 h-5 transform transition-transform" 
                             :class="open ? 'rotate-180' : ''"
                             fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </button>
                    
                    <div x-show="open" 
                         x-collapse
                         class="px-6 pb-4 text-gray-700">
                        {{ $faq->answer }}
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</section>
@endif
```

### Компонент расписания

```blade
{{-- resources/views/components/events/schedule.blade.php --}}
@props(['scheduleItems'])

@if($scheduleItems->isNotEmpty())
<section class="py-12 bg-white">
    <div class="container mx-auto px-4">
        <h2 class="text-3xl font-bold text-center mb-8">Программа мероприятия</h2>
        
        <div class="max-w-4xl mx-auto">
            @foreach($scheduleItems->groupBy(fn($item) => $item->start_time->format('Y-m-d')) as $date => $items)
                <div class="mb-10">
                    <h3 class="text-xl font-semibold mb-4 text-gray-800 sticky top-0 bg-white py-2">
                        {{ \Carbon\Carbon::parse($date)->isoFormat('DD MMMM YYYY, dddd') }}
                    </h3>
                    
                    <div class="space-y-4">
                        @foreach($items as $item)
                            <div class="flex gap-4 p-4 border rounded-lg hover:shadow-md transition-shadow">
                                <div class="flex-shrink-0 w-24 text-center">
                                    <div class="text-lg font-bold text-gray-900">
                                        {{ $item->start_time->format('H:i') }}
                                    </div>
                                    <div class="text-sm text-gray-500">
                                        {{ $item->duration_minutes }} мин
                                    </div>
                                </div>
                                
                                <div class="flex-grow">
                                    <div class="flex items-center gap-2 mb-1">
                                        @if($item->session_type)
                                            <span class="px-2 py-1 text-xs font-medium rounded-full 
                                                @switch($item->session_type)
                                                    @case('keynote') bg-blue-100 text-blue-800 @break
                                                    @case('workshop') bg-green-100 text-green-800 @break
                                                    @case('panel') bg-purple-100 text-purple-800 @break
                                                    @case('break') bg-gray-100 text-gray-800 @break
                                                    @default bg-gray-100 text-gray-800
                                                @endswitch">
                                                {{ $item->session_type }}
                                            </span>
                                        @endif
                                        
                                        @if($item->location)
                                            <span class="text-sm text-gray-500">📍 {{ $item->location }}</span>
                                        @endif
                                    </div>
                                    
                                    <h4 class="text-lg font-semibold text-gray-900">{{ $item->title }}</h4>
                                    
                                    @if($item->description)
                                        <p class="text-gray-600 mt-1">{{ $item->description }}</p>
                                    @endif
                                    
                                    @if($item->speaker)
                                        <div class="mt-2 flex items-center gap-2">
                                            @if($item->speaker->avatar)
                                                <img src="{{ $item->speaker->avatar }}" 
                                                     alt="{{ $item->speaker->name }}"
                                                     class="w-8 h-8 rounded-full object-cover">
                                            @endif
                                            <span class="text-sm text-gray-700">{{ $item->speaker->name }}</span>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</section>
@endif
```

### Использование в шаблоне мероприятия

```blade
{{-- resources/views/events/show.blade.php --}}
@extends('layouts.app')

@section('content')
    <article>
        {{-- Hero секция --}}
        <header class="relative h-96 bg-cover bg-center" style="background-image: url('{{ $event->cover ?? $event->poster }}')">
            <div class="absolute inset-0 bg-black bg-opacity-50"></div>
            <div class="relative container mx-auto px-4 h-full flex items-end pb-12">
                <div class="text-white">
                    <h1 class="text-5xl font-bold mb-4">{{ $event->title }}</h1>
                    @if($event->short_description)
                        <p class="text-xl opacity-90">{{ $event->short_description }}</p>
                    @endif
                </div>
            </div>
        </header>

        {{-- Основной контент --}}
        <div class="container mx-auto px-4 py-12">
            @if($event->description)
                <section class="prose max-w-none mb-12">
                    {!! $event->description !!}
                </section>
            @endif

            {{-- Подключаем компоненты расширенного контента --}}
            @if($event->scheduleItems->isNotEmpty())
                <x-events.schedule :schedule-items="$event->scheduleItems" />
            @endif

            @if($event->speakers->isNotEmpty())
                <x-events.speakers :speakers="$event->speakers" />
            @endif

            @if($event->sponsors->isNotEmpty())
                <x-events.sponsors :sponsors="$event->sponsors" />
            @endif

            @if($event->faqs->isNotEmpty())
                <x-events.faq :faqs="$event->faqs" />
            @endif

            @if($event->artists->isNotEmpty())
                <x-events.artists :artists="$event->artists" />
            @endif
        </div>
    </article>
@endsection
```

---

## Best Practices

### 1. Сортировка и порядок отображения

```php
// Всегда используйте sort_order для явного контроля порядка
EventSpeaker::where('event_id', $eventId)
    ->orderBy('is_featured', 'desc')
    ->orderBy('sort_order')
    ->get();

// Для расписания сортируйте по времени
EventScheduleItem::where('event_id', $eventId)
    ->orderBy('start_time')
    ->get();
```

### 2. Кэширование

```php
// Кэширование тяжёлых запросов
$event = Cache::remember(
    "event.{$eventId}.full-content", 
    3600, // 1 час
    fn() => Event::withFullContent()->find($eventId)
);

// Инвалидация кэша при обновлении
Event::updated(function ($event) {
    Cache::forget("event.{$event->id}.full-content");
});
```

### 3. Валидация данных

```php
// Form Request для создания спикера
class StoreEventSpeakerRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'event_id' => 'required|exists:events,id',
            'name' => 'required|string|max:255',
            'bio' => 'nullable|string|max:5000',
            'avatar' => 'nullable|image|max:2048',
            'social_links' => 'nullable|array',
            'social_links.*' => 'nullable|url',
            'sort_order' => 'nullable|integer|min:0',
        ];
    }
}
```

### 4. Eager Loading для производительности

```php
// Избегайте N+1 проблемы
$events = Event::with([
    'speakers',
    'sponsors',
    'faqs',
    'scheduleItems',
    'scheduleItems.speaker',
])->where('status', 'published')->get();

// Или используйте withCount для счётчиков
$events = Event::withCount(['speakers', 'sponsors', 'faqs'])->get();
```

### 5. API Endpoints (RESTful CRUD)

```php
// routes/api.php
Route::apiResource('events', EventController::class);
Route::apiResource('events.speakers', EventSpeakerController::class)->shallow();
Route::apiResource('events.sponsors', EventSponsorController::class)->shallow();
Route::apiResource('events.faqs', EventFaqController::class)->shallow();
Route::apiResource('events.artists', EventArtistController::class)->shallow();
Route::apiResource('events.schedule', EventScheduleItemController::class)->shallow();

// Пример контроллера
class EventSpeakerController extends Controller
{
    public function index(Event $event)
    {
        return EventSpeakerResource::collection(
            $event->speakers()->ordered()->get()
        );
    }

    public function store(StoreEventSpeakerRequest $request, Event $event)
    {
        $speaker = $event->speakers()->create([
            'public_id' => Str::random(26),
            ...$request->validated(),
        ]);

        return new EventSpeakerResource($speaker->fresh());
    }

    public function update(UpdateEventSpeakerRequest $request, EventSpeaker $speaker)
    {
        $speaker->update($request->validated());
        
        return new EventSpeakerResource($speaker->fresh());
    }

    public function destroy(EventSpeaker $speaker)
    {
        $speaker->delete();
        
        return response()->noContent();
    }
}
```

---

## Миграция с JSON на отдельные таблицы

### Скрипт миграции данных

```php
<?php

namespace Database\Migrations\Data;

use Illuminate\Support\Facades\DB;
use Nabilet\Modules\Events\Models\Event;
use Nabilet\Modules\Events\Models\EventSpeaker;
use Nabilet\Modules\Events\Models\EventSponsor;
use Nabilet\Modules\Events\Models\EventFaq;
use Nabilet\Modules\Events\Models\EventArtist;
use Nabilet\Modules\Events\Models\EventScheduleItem;

class MigrateEventMetaToTables
{
    public function handle(): void
    {
        $events = Event::whereNotNull('meta')->get();

        foreach ($events as $event) {
            $meta = $event->meta;

            // Миграция спикеров
            if (isset($meta['speakers']) && is_array($meta['speakers'])) {
                foreach ($meta['speakers'] as $index => $speakerData) {
                    EventSpeaker::create([
                        'public_id' => $speakerData['id'] ?? \Str::random(26),
                        'event_id' => $event->id,
                        'name' => $speakerData['name'] ?? 'Unknown',
                        'bio' => $speakerData['bio'] ?? null,
                        'avatar' => $speakerData['avatar'] ?? null,
                        'position' => $speakerData['position'] ?? null,
                        'company' => $speakerData['company'] ?? null,
                        'social_links' => $speakerData['social_links'] ?? null,
                        'sort_order' => $index,
                        'is_featured' => $speakerData['is_featured'] ?? false,
                    ]);
                }
            }

            // Миграция спонсоров
            if (isset($meta['sponsors']) && is_array($meta['sponsors'])) {
                foreach ($meta['sponsors'] as $index => $sponsorData) {
                    EventSponsor::create([
                        'public_id' => $sponsorData['id'] ?? \Str::random(26),
                        'event_id' => $event->id,
                        'name' => $sponsorData['name'] ?? 'Unknown',
                        'logo' => $sponsorData['logo'] ?? null,
                        'tier' => $sponsorData['tier'] ?? 'standard',
                        'website' => $sponsorData['website'] ?? null,
                        'description' => $sponsorData['description'] ?? null,
                        'sort_order' => $index,
                        'is_featured' => $sponsorData['is_featured'] ?? false,
                    ]);
                }
            }

            // Миграция FAQ
            if (isset($meta['faqs']) && is_array($meta['faqs'])) {
                foreach ($meta['faqs'] as $index => $faqData) {
                    EventFaq::create([
                        'event_id' => $event->id,
                        'question' => $faqData['question'] ?? '',
                        'answer' => $faqData['answer'] ?? '',
                        'sort_order' => $index,
                        'category' => $faqData['category'] ?? null,
                        'is_active' => true,
                    ]);
                }
            }

            // Миграция артистов
            if (isset($meta['artists']) && is_array($meta['artists'])) {
                foreach ($meta['artists'] as $index => $artistData) {
                    EventArtist::create([
                        'public_id' => \Str::random(26),
                        'event_id' => $event->id,
                        'name' => $artistData['name'] ?? 'Unknown',
                        'bio' => $artistData['bio'] ?? null,
                        'photo' => $artistData['photo'] ?? null,
                        'genre' => $artistData['genre'] ?? null,
                        'social_links' => $artistData['social_links'] ?? null,
                        'performance_time' => $artistData['performance_time'] ?? null,
                        'sort_order' => $index,
                        'is_headliner' => $artistData['is_headliner'] ?? false,
                    ]);
                }
            }

            // Миграция расписания
            if (isset($meta['schedule']) && is_array($meta['schedule'])) {
                foreach ($meta['schedule'] as $index => $scheduleData) {
                    EventScheduleItem::create([
                        'event_id' => $event->id,
                        'title' => $scheduleData['title'] ?? 'Untitled',
                        'description' => $scheduleData['description'] ?? null,
                        'start_time' => $scheduleData['time'] ?? now(),
                        'duration_minutes' => $scheduleData['duration_minutes'] ?? 60,
                        'location' => $scheduleData['location'] ?? null,
                        'session_type' => $scheduleData['type'] ?? null,
                        'sort_order' => $index,
                    ]);
                }
            }

            // Очищаем meta после успешной миграции (опционально)
            // $event->update(['meta' => null]);
        }

        echo "Migration completed for {$events->count()} events.\n";
    }
}
```

### Запуск миграции данных

```bash
# Создайте artisan команду
php artisan make:command MigrateEventMetaToTables

# Затем выполните
php artisan migrate:event-meta-to-tables
```

---

## Рекомендации по выбору подхода

| Сценарий | Рекомендуемый подход | Обоснование |
|----------|---------------------|-------------|
| Прототип / MVP | JSON `meta` | Быстро, гибко, без миграций |
| Конференции со спикерами | Отдельные таблицы | Нужны связи, профили спикеров |
| Музыкальные фестивали | Гибридный | Артисты в JSON, расписание в таблицах |
| Корпоративные события | JSON `meta` | Простые данные, нет сложных связей |
| Production с высокой нагрузкой | Отдельные таблицы | Производительность, кэширование |
| Частые изменения структуры | Гибридный | Баланс гибкости и надёжности |

---

## Заключение

Выбор подхода зависит от:
1. **Сложности данных** — простые списки → JSON, связанные сущности → таблицы
2. **Требований к поиску** — нужна фильтрация → таблицы
3. **Частоты изменений** — частые изменения структуры → JSON
4. **Нагрузки** — high-load → таблицы с индексами

Гибридный подход рекомендуется как наиболее сбалансированный для production-системы NABILET.
