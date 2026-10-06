<?php

declare(strict_types=1);

namespace Nabilet\Modules\Storefront\Services;

use Illuminate\Support\Arr;
use Nabilet\Modules\Storefront\Models\StorefrontSetting;

/**
 * Конструктор витрины: дефолтный конфиг, нормализация и чтение/запись.
 *
 * Почему нормализация строгая, а не «доверяем JSON из админки»:
 * конфиг витрины попадает в HTML и в CSS-переменные. Любой ключ вне
 * белого списка мы выбрасываем — так в пользовательский браузер не уедет
 * ни произвольный HTML, ни css-инъекция в var(--brand). Поля вида
 * «цвет» проверяются регуляркой #rgb|#rrggbb, числа — границами,
 * перечисления — списком допустимых значений.
 *
 * Ни один виджет не рендерит HTML: тексты хранятся и отдаются как обычные
 * строки, фронт выводит их через textContent/интерполяцию Vue.
 */
final class StorefrontService
{
    /** Виджеты витрины: тип → подпись для админки. */
    public const WIDGETS = [
        'hero' => 'Герой-баннер',
        'posters' => 'Афиша мероприятий',
        'featured' => 'Подборка',
        'categories' => 'Категории',
        'countdown' => 'Обратный отсчёт',
        'promo' => 'Промо-полоса',
        'richtext' => 'Текстовый блок',
        'faq' => 'Вопросы и ответы',
        'stats' => 'Цифры',
        'subscribe' => 'Подписка',
    ];

    /** Готовые цветовые схемы. Значения — css-переменные бренда/акцента. */
    public const PRESETS = [
        'violet' => ['brand' => '#6D4AFF', 'brandStrong' => '#5A31F0', 'accent' => '#FF5C22', 'accentStrong' => '#F03F00'],
        'indigo' => ['brand' => '#3B5BDB', 'brandStrong' => '#2F49AF', 'accent' => '#F76707', 'accentStrong' => '#D9480F'],
        'emerald' => ['brand' => '#0CA678', 'brandStrong' => '#087F5B', 'accent' => '#F08C00', 'accentStrong' => '#C76A00'],
        'crimson' => ['brand' => '#E03131', 'brandStrong' => '#C92A2A', 'accent' => '#1C7ED6', 'accentStrong' => '#1971C2'],
        'graphite' => ['brand' => '#343A40', 'brandStrong' => '#212529', 'accent' => '#F59F00', 'accentStrong' => '#E67700'],
        'lagoon' => ['brand' => '#1098AD', 'brandStrong' => '#0C8599', 'accent' => '#D6336C', 'accentStrong' => '#A61E4D'],
    ];

    /**
     * Конфиг по умолчанию — то, что видит организатор до первой настройки.
     * Порядок секций = порядок на странице.
     */
    public function defaultConfig(): array
    {
        return [
            'version' => 1,
            'theme' => [
                'preset' => 'violet',
                'brand' => '#6D4AFF',
                'brandStrong' => '#5A31F0',
                'accent' => '#FF5C22',
                'accentStrong' => '#F03F00',
                'radius' => 16,
                'mode' => 'auto',
                'surface' => 'tinted',
            ],
            'branding' => [
                'name' => 'NABILET',
                'tagline' => 'Билеты на события',
                'logoUrl' => '',
                'logoMark' => 'Н',
                'faviconUrl' => '',
            ],
            'header' => [
                'showSearch' => true,
                'showCart' => true,
                'showThemeToggle' => true,
                'nav' => [
                    ['label' => 'Афиша', 'to' => '/'],
                    ['label' => 'Мои билеты', 'to' => '/tickets'],
                    ['label' => 'Организаторам', 'to' => '/admin'],
                ],
            ],
            'footer' => [
                'text' => 'NABILET — билеты на события без наценки за кассу.',
                'links' => [
                    ['label' => 'Помощь', 'url' => '/'],
                    ['label' => 'Возврат', 'url' => '/'],
                    ['label' => 'Организаторам', 'url' => '/admin'],
                ],
                'social' => [],
            ],
            'sections' => [
                $this->defaultSection('hero', [
                    'badge' => 'Билеты без наценки за кассу',
                    'title' => 'Выберите событие — место найдём на схеме зала',
                    'subtitle' => 'Реальная рассадка, честные цены и билет с QR, который контролёр считает даже без интернета.',
                    'ctaLabel' => 'Смотреть афишу',
                    'ctaLink' => '/',
                    'image' => '',
                    'align' => 'left',
                    'height' => 'medium',
                    'showSearch' => true,
                ]),
                $this->defaultSection('categories', [
                    'source' => 'auto',
                    'style' => 'chips',
                    'items' => [],
                ], ['title' => 'Куда пойти']),
                $this->defaultSection('posters', [
                    'layout' => 'grid',
                    'columns' => 3,
                    'limit' => 12,
                    'sort' => 'date',
                    'category' => '',
                    'showFilters' => true,
                ], ['title' => 'Афиша', 'subtitle' => 'Ближайшие мероприятия']),
                $this->defaultSection('featured', [
                    'source' => 'upcoming',
                    'limit' => 6,
                    'layout' => 'carousel',
                    'ids' => [],
                ], ['title' => 'Рекомендуем', 'subtitle' => 'То, что стоит увидеть']),
                $this->defaultSection('promo', [
                    'tone' => 'brand',
                    'title' => 'Организаторам',
                    'text' => 'Подключите продажи на своей площадке: схема зала, свой брендинг и контроль на входе.',
                    'buttonLabel' => 'Подключить площадку',
                    'buttonLink' => '/admin',
                    'code' => '',
                ]),
                $this->defaultSection('stats', [
                    'source' => 'auto',
                    'items' => [],
                ]),
                $this->defaultSection('subscribe', [
                    'title' => 'Узнавайте о событиях первыми',
                    'text' => 'Рассказываем о премьерах и открытых продажах. Без спама.',
                    'placeholder' => 'E-mail',
                    'buttonLabel' => 'Подписаться',
                    'privacy' => 'Нажимая кнопку, вы соглашаетесь с политикой обработки данных.',
                ]),
            ],
        ];
    }

    /** Заготовка секции: id стабилен, чтобы фронт не терял состояние при сохранении. */
    public function defaultSection(string $type, array $settings = [], array $meta = []): array
    {
        return [
            'id' => 'sec_' . $type . '_' . substr(md5($type . microtime(true) . random_int(0, 999999)), 0, 8),
            'type' => $type,
            'visible' => true,
            'title' => $meta['title'] ?? '',
            'subtitle' => $meta['subtitle'] ?? '',
            'settings' => $settings,
        ];
    }

    /**
     * Конфиг для отображения: сохранённый организацией или дефолт.
     *
     * `$organizationId === null` — гостевая витрина без организации: берём
     * глобальный конфиг (строка с NULL). Если его ещё нет, создаём из дефолта,
     * чтобы у администратора была точка, которую он реально редактирует.
     */
    public function resolve(?string $organizationId): array
    {
        // Нет своей строки у организации — берём глобальный конфиг, и только
        // если нет и его, дефолт из кода. Иначе организация, у которой
        // настроена только «шапка по умолчанию», внезапно потеряла бы её.
        $row = $this->findRow($organizationId)
            ?? ($organizationId !== null ? $this->findRow(null) : null);

        if ($row === null) {
            return $this->defaultConfig();
        }

        return $this->normalize($row->config ?? []);
    }

    /** Признак «организатор ещё ничего не сохранял». */
    public function isDefault(?string $organizationId): bool
    {
        return $this->findRow($organizationId) === null;
    }

    public function save(?string $organizationId, array $input, ?int $userId = null): array
    {
        $config = $this->normalize($input);

        StorefrontSetting::query()->updateOrCreate(
            ['organization_id' => $organizationId],
            [
                'config' => $config,
                'updated_by' => $userId,
            ],
        );

        return $config;
    }

    /** Сброс: удаляем строку организации — витрина снова берёт дефолт. */
    public function reset(?string $organizationId): array
    {
        if ($organizationId !== null) {
            StorefrontSetting::query()->where('organization_id', $organizationId)->delete();
        }

        return $this->defaultConfig();
    }

    private function findRow(?string $organizationId): ?StorefrontSetting
    {
        if ($organizationId === null) {
            return StorefrontSetting::query()->whereNull('organization_id')->first();
        }

        return StorefrontSetting::query()
            ->where('organization_id', $organizationId)
            ->first();
    }

    /* ── Нормализация ─────────────────────────────────────────────────────
     * Всё, что не описано здесь, до фронта не доезжает.
     */

    public function normalize(array $input): array
    {
        $defaults = $this->defaultConfig();

        return [
            'version' => 1,
            'theme' => $this->normalizeTheme(is_array($input['theme'] ?? null) ? $input['theme'] : [], $defaults['theme']),
            'branding' => $this->normalizeBranding(is_array($input['branding'] ?? null) ? $input['branding'] : [], $defaults['branding']),
            'header' => $this->normalizeHeader(is_array($input['header'] ?? null) ? $input['header'] : [], $defaults['header']),
            'footer' => $this->normalizeFooter(is_array($input['footer'] ?? null) ? $input['footer'] : [], $defaults['footer']),
            'sections' => $this->normalizeSections(is_array($input['sections'] ?? null) ? $input['sections'] : []),
        ];
    }

    private function normalizeTheme(array $input, array $defaults): array
    {
        $preset = $this->enum($input['preset'] ?? null, array_keys(self::PRESETS), $defaults['preset']);

        // Палитра задаёт кисти: выбранный пресет подставляет свои значения,
        // если администратор не переопределил цвет руками.
        $brush = self::PRESETS[$preset];
        $custom = [
            'brand' => $this->color($input['brand'] ?? null) ?? $brush['brand'],
            'brandStrong' => $this->color($input['brandStrong'] ?? null) ?? $brush['brandStrong'],
            'accent' => $this->color($input['accent'] ?? null) ?? $brush['accent'],
            'accentStrong' => $this->color($input['accentStrong'] ?? null) ?? $brush['accentStrong'],
        ];

        return [
            'preset' => $preset,
            'brand' => $custom['brand'],
            'brandStrong' => $custom['brandStrong'],
            'accent' => $custom['accent'],
            'accentStrong' => $custom['accentStrong'],
            'radius' => $this->intBetween($input['radius'] ?? null, 0, 28, $defaults['radius']),
            'mode' => $this->enum($input['mode'] ?? null, ['light', 'dark', 'auto'], $defaults['mode']),
            'surface' => $this->enum($input['surface'] ?? null, ['tinted', 'clean', 'dark'], $defaults['surface']),
        ];
    }

    private function normalizeBranding(array $input, array $defaults): array
    {
        return [
            'name' => $this->text($input['name'] ?? null, 60, $defaults['name']),
            'tagline' => $this->text($input['tagline'] ?? null, 120, $defaults['tagline']),
            'logoUrl' => $this->url($input['logoUrl'] ?? null),
            'logoMark' => $this->text($input['logoMark'] ?? null, 4, $defaults['logoMark']),
            'faviconUrl' => $this->url($input['faviconUrl'] ?? null),
        ];
    }

    private function normalizeHeader(array $input, array $defaults): array
    {
        return [
            'showSearch' => $this->bool($input['showSearch'] ?? null, $defaults['showSearch']),
            'showCart' => $this->bool($input['showCart'] ?? null, $defaults['showCart']),
            'showThemeToggle' => $this->bool($input['showThemeToggle'] ?? null, $defaults['showThemeToggle']),
            'nav' => $this->normalizeLinks($input['nav'] ?? [], 'to', 6),
        ];
    }

    private function normalizeFooter(array $input, array $defaults): array
    {
        return [
            'text' => $this->text($input['text'] ?? null, 240, $defaults['text']),
            'links' => $this->normalizeLinks($input['links'] ?? [], 'url', 8),
            'social' => $this->normalizeLinks($input['social'] ?? [], 'url', 6),
        ];
    }

    /** @return array<int, array{label: string, to: string}|array{label: string, url: string}> */
    private function normalizeLinks(mixed $input, string $targetKey, int $max): array
    {
        if (! is_array($input)) {
            return [];
        }

        $out = [];
        foreach (array_slice(array_values($input), 0, $max) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $label = $this->text($item['label'] ?? null, 40, '');
            $target = $this->text($item[$targetKey] ?? null, 200, '');
            if ($label === '' || $target === '') {
                continue;
            }
            $out[] = ['label' => $label, $targetKey => $target];
        }

        return $out;
    }

    private function normalizeSections(mixed $input): array
    {
        if (! is_array($input)) {
            return [];
        }

        $out = [];
        foreach (array_slice(array_values($input), 0, 40) as $index => $section) {
            if (! is_array($section)) {
                continue;
            }
            $type = $this->enum($section['type'] ?? null, array_keys(self::WIDGETS), '');
            if ($type === '') {
                continue;
            }
            $out[] = [
                'id' => $this->id($section['id'] ?? null) ?? 'sec_' . $type . '_' . $index,
                'type' => $type,
                'visible' => $this->bool($section['visible'] ?? null, true),
                'title' => $this->text($section['title'] ?? null, 120, ''),
                'subtitle' => $this->text($section['subtitle'] ?? null, 240, ''),
                'settings' => $this->normalizeSettings($type, is_array($section['settings'] ?? null) ? $section['settings'] : []),
            ];
        }

        return $out;
    }

    /** Поля виджета: белый список по типу секции. */
    private function normalizeSettings(string $type, array $input): array
    {
        $settings = match ($type) {
            'hero' => [
                'badge' => $this->text($input['badge'] ?? null, 80, ''),
                'title' => $this->text($input['title'] ?? null, 160, ''),
                'subtitle' => $this->text($input['subtitle'] ?? null, 320, ''),
                'ctaLabel' => $this->text($input['ctaLabel'] ?? null, 40, ''),
                'ctaLink' => $this->text($input['ctaLink'] ?? null, 200, '/'),
                'image' => $this->url($input['image'] ?? null),
                'align' => $this->enum($input['align'] ?? null, ['left', 'center'], 'left'),
                'height' => $this->enum($input['height'] ?? null, ['compact', 'medium', 'tall'], 'medium'),
                'showSearch' => $this->bool($input['showSearch'] ?? null, true),
            ],
            'posters' => [
                'layout' => $this->enum($input['layout'] ?? null, ['grid', 'list'], 'grid'),
                'columns' => $this->intBetween($input['columns'] ?? null, 2, 4, 3),
                'limit' => $this->intBetween($input['limit'] ?? null, 1, 60, 12),
                'sort' => $this->enum($input['sort'] ?? null, ['date', 'price', 'title'], 'date'),
                'category' => $this->text($input['category'] ?? null, 60, ''),
                'showFilters' => $this->bool($input['showFilters'] ?? null, true),
            ],
            'featured' => [
                'source' => $this->enum($input['source'] ?? null, ['upcoming', 'manual'], 'upcoming'),
                'limit' => $this->intBetween($input['limit'] ?? null, 1, 24, 6),
                'layout' => $this->enum($input['layout'] ?? null, ['carousel', 'grid'], 'carousel'),
                'ids' => $this->stringList($input['ids'] ?? [], 24, 64),
            ],
            'categories' => [
                'source' => $this->enum($input['source'] ?? null, ['auto', 'manual'], 'auto'),
                'style' => $this->enum($input['style'] ?? null, ['chips', 'tiles'], 'chips'),
                'items' => $this->normalizeCategoryItems($input['items'] ?? []),
            ],
            'countdown' => [
                'eventId' => $this->text($input['eventId'] ?? null, 64, ''),
                'target' => $this->text($input['target'] ?? null, 40, ''),
                'label' => $this->text($input['label'] ?? null, 80, 'До начала'),
            ],
            'promo' => [
                'tone' => $this->enum($input['tone'] ?? null, ['brand', 'accent', 'dark'], 'brand'),
                'title' => $this->text($input['title'] ?? null, 120, ''),
                'text' => $this->text($input['text'] ?? null, 320, ''),
                'buttonLabel' => $this->text($input['buttonLabel'] ?? null, 40, ''),
                'buttonLink' => $this->text($input['buttonLink'] ?? null, 200, '/'),
                'code' => $this->text($input['code'] ?? null, 40, ''),
            ],
            'richtext' => [
                'text' => $this->text($input['text'] ?? null, 4000, ''),
                'align' => $this->enum($input['align'] ?? null, ['left', 'center'], 'left'),
                'width' => $this->enum($input['width'] ?? null, ['narrow', 'wide'], 'wide'),
            ],
            'faq' => [
                'items' => $this->normalizeFaq($input['items'] ?? []),
            ],
            'stats' => [
                'source' => $this->enum($input['source'] ?? null, ['auto', 'manual'], 'auto'),
                'items' => $this->normalizeStats($input['items'] ?? []),
            ],
            'subscribe' => [
                'title' => $this->text($input['title'] ?? null, 120, ''),
                'text' => $this->text($input['text'] ?? null, 240, ''),
                'placeholder' => $this->text($input['placeholder'] ?? null, 60, 'E-mail'),
                'buttonLabel' => $this->text($input['buttonLabel'] ?? null, 40, 'Подписаться'),
                'privacy' => $this->text($input['privacy'] ?? null, 240, ''),
            ],
            default => [],
        };

        return $settings;
    }

    private function normalizeCategoryItems(mixed $input): array
    {
        if (! is_array($input)) {
            return [];
        }
        $out = [];
        foreach (array_slice(array_values($input), 0, 16) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $label = $this->text($item['label'] ?? null, 40, '');
            if ($label === '') {
                continue;
            }
            $out[] = [
                'label' => $label,
                'icon' => $this->text($item['icon'] ?? null, 4, ''),
                'query' => $this->text($item['query'] ?? null, 60, $label),
            ];
        }

        return $out;
    }

    private function normalizeFaq(mixed $input): array
    {
        if (! is_array($input)) {
            return [];
        }
        $out = [];
        foreach (array_slice(array_values($input), 0, 20) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $q = $this->text($item['q'] ?? null, 200, '');
            $a = $this->text($item['a'] ?? null, 1200, '');
            if ($q === '' || $a === '') {
                continue;
            }
            $out[] = ['q' => $q, 'a' => $a];
        }

        return $out;
    }

    private function normalizeStats(mixed $input): array
    {
        if (! is_array($input)) {
            return [];
        }
        $out = [];
        foreach (array_slice(array_values($input), 0, 6) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $label = $this->text($item['label'] ?? null, 60, '');
            $value = $this->text($item['value'] ?? null, 24, '');
            if ($label === '' || $value === '') {
                continue;
            }
            $out[] = ['label' => $label, 'value' => $value];
        }

        return $out;
    }

    /* ── Примитивы ───────────────────────────────────────────────────────── */

    /** Текст: обрезаем по длине и сносим управляющие символы — это не HTML-поле. */
    private function text(mixed $value, int $max, string $default): string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return $default;
        }
        $clean = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $value) ?? '');

        return $clean === '' ? $default : mb_substr($clean, 0, $max);
    }

    /** Цвет #rgb / #rrggbb. null — «не задан», тогда берём кисть пресета. */
    private function color(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = strtolower(trim($value));
        if (! preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/', $value)) {
            return null;
        }

        return $value;
    }

    /** Ссылка: только http(s), относительный путь или data:image для логотипа. */
    private function url(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (str_starts_with($value, '/') && ! str_starts_with($value, '//')) {
            return mb_substr($value, 0, 500);
        }
        if (preg_match('#^https?://#i', $value) || preg_match('#^data:image/(png|jpe?g|gif|webp|svg\+xml);base64,#i', $value)) {
            return mb_substr($value, 0, 1000);
        }

        return '';
    }

    private function bool(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'on', 'yes'], true);
        }
        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        return $default;
    }

    private function intBetween(mixed $value, int $min, int $max, int $default): int
    {
        if (! is_numeric($value)) {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }

    private function enum(mixed $value, array $allowed, string $default): string
    {
        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            return $default;
        }

        return $value;
    }

    /** id секции: только безопасные символы — уходит в DOM и в ключи Vue. */
    private function id(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);
        if (! preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $value)) {
            return null;
        }

        return $value;
    }

    /** @return list<string> */
    private function stringList(mixed $input, int $max, int $maxLength): array
    {
        if (! is_array($input)) {
            return [];
        }
        $out = [];
        foreach (array_slice(array_values($input), 0, $max) as $item) {
            if (! is_string($item) && ! is_numeric($item)) {
                continue;
            }
            $value = trim((string) $item);
            if ($value === '' || mb_strlen($value) > $maxLength) {
                continue;
            }
            $out[] = $value;
        }

        return $out;
    }

    /** Справочник виджетов для админки: тип, подпись, поля и дефолты. */
    public function schema(): array
    {
        return [
            'widgets' => self::WIDGETS,
            'presets' => self::PRESETS,
            'defaults' => $this->defaultConfig(),
        ];
    }
}
