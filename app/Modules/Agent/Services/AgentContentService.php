<?php

declare(strict_types=1);

namespace Nabilet\Modules\Agent\Services;

use Illuminate\Support\Facades\DB;
use Nabilet\Modules\Agent\Support\AgentScope;
use Nabilet\Modules\Tickets\Domain\TicketTemplateConfig;
use Nabilet\Modules\Venues\Halls\Services\HallService;

/**
 * Read-only контентные срезы и валидаторы для MCP-агента.
 *
 * Правила скоупа и маскирования живут здесь, а не в тринадцати инструментах:
 * агент видит только организации владельца токена и никогда не получает
 * шифротекст/секреты из настроек. Валидация схемы и шаблона билета не
 * дублирует правила приложения — она вызывает те же владельцев.
 */
final class AgentContentService
{
    public function __construct(private readonly HallService $halls)
    {
    }

    /** @return array<string, mixed> */
    public function searchPages(?object $user, ?string $query, int $limit): array
    {
        $needle = $this->needle($query);

        return $this->scoped(function (array $orgIds) use ($needle, $limit): array {
            $rows = DB::table('pages')
                ->whereIn('organization_id', $orgIds)
                ->when($needle !== '', fn ($q) => $q->where(fn ($w) => $w
                    ->where('title', 'like', '%' . $needle . '%')
                    ->orWhere('slug', 'like', '%' . $needle . '%')))
                ->orderByDesc('updated_at')
                ->limit($this->limit($limit))
                ->get(['public_id', 'title', 'slug', 'status', 'updated_at']);

            return ['pages' => $rows->map(static fn (object $r): array => [
                'public_id' => (string) $r->public_id,
                'title' => (string) $r->title,
                'slug' => (string) $r->slug,
                'status' => (string) $r->status,
                'updated_at' => (string) $r->updated_at,
            ])->all()];
        }, $user);
    }

    /** @return array<string, mixed> */
    public function getPage(?object $user, string $publicId): array
    {
        return $this->scoped(function (array $orgIds) use ($publicId): array {
            $row = DB::table('pages')
                ->whereIn('organization_id', $orgIds)
                ->where('public_id', $publicId)
                ->first();
            if ($row === null) {
                return ['found' => false];
            }

            $seo = DB::table('seo_meta')
                ->where('entity_type', 'page')
                ->where('entity_id', (int) $row->id)
                ->get(['locale', 'title', 'description', 'canonical_url', 'og_title', 'og_image'])
                ->map(static fn (object $s): array => [
                    'locale' => (string) $s->locale,
                    'title' => $s->title,
                    'description' => $s->description,
                    'canonical_url' => $s->canonical_url,
                    'og_title' => $s->og_title,
                    'og_image' => $s->og_image,
                ])->all();

            return [
                'found' => true,
                'page' => [
                    'public_id' => (string) $row->public_id,
                    'title' => (string) $row->title,
                    'slug' => (string) $row->slug,
                    'status' => (string) $row->status,
                    'content' => (string) ($row->content ?? ''),
                    'published_at' => $row->published_at,
                ],
                'seo' => $seo,
            ];
        }, $user);
    }

    /** @return array<string, mixed> */
    public function searchMedia(?object $user, ?string $query, int $limit): array
    {
        $needle = $this->needle($query);

        return $this->scoped(function (array $orgIds) use ($needle, $limit): array {
            $rows = DB::table('media_assets')
                ->whereNull('deleted_at')
                ->whereIn('organization_id', $orgIds)
                ->when($needle !== '', fn ($q) => $q->where(fn ($w) => $w
                    ->where('filename', 'like', '%' . $needle . '%')
                    ->orWhere('title', 'like', '%' . $needle . '%')))
                ->orderByDesc('created_at')
                ->limit($this->limit($limit))
                ->get(['public_id', 'filename', 'mime_type', 'size_bytes', 'width', 'height', 'title', 'alt_text']);

            return ['media' => $rows->map(static fn (object $r): array => [
                'public_id' => (string) $r->public_id,
                'filename' => (string) $r->filename,
                'mime_type' => (string) $r->mime_type,
                'size_bytes' => (int) $r->size_bytes,
                'width' => $r->width,
                'height' => $r->height,
                'title' => $r->title,
                'alt_text' => $r->alt_text,
            ])->all()];
        }, $user);
    }

    /** @return array<string, mixed> */
    public function getSiteSettings(?object $user): array
    {
        return $this->scoped(function (array $orgIds): array {
            $config = $this->storefrontConfig($orgIds);

            return [
                'settings' => $this->mask($config),
                'note' => 'Ключи с password/token/secret/key заменены на ***.',
            ];
        }, $user);
    }

    /** @return array<string, mixed> */
    public function getThemeSettings(?object $user): array
    {
        return $this->scoped(function (array $orgIds): array {
            $config = $this->storefrontConfig($orgIds);
            $theme = [];
            foreach ($config as $key => $value) {
                if (is_string($key) && str_starts_with($key, 'theme')) {
                    $theme[$key] = $value;
                }
            }

            return [
                'theme' => $this->mask($theme),
                'note' => 'Показываются только ключи с префиксом theme; секреты замаскированы.',
            ];
        }, $user);
    }

    /** @return array<string, mixed> */
    public function getSeoReport(?object $user): array
    {
        return $this->scoped(function (array $orgIds): array {
            $pages = DB::table('pages')->whereIn('organization_id', $orgIds)->get(['id', 'status']);
            $pageIds = $pages->pluck('id')->all();
            $meta = $pageIds === []
                ? collect()
                : DB::table('seo_meta')->where('entity_type', 'page')->whereIn('entity_id', $pageIds)
                    ->get(['entity_id', 'description', 'canonical_url', 'og_image']);

            $withDescription = $meta->filter(static fn (object $m): bool => trim((string) ($m->description ?? '')) !== '')->count();
            $withCanonical = $meta->filter(static fn (object $m): bool => trim((string) ($m->canonical_url ?? '')) !== '')->count();
            $withOg = $meta->filter(static fn (object $m): bool => trim((string) ($m->og_image ?? '')) !== '')->count();
            $total = max(1, count($pageIds));

            return [
                'pages_total' => count($pageIds),
                'pages_published' => $pages->filter(static fn (object $p): bool => (string) $p->status === 'published')->count(),
                'seo_rows' => $meta->count(),
                'with_description' => $withDescription,
                'with_canonical' => $withCanonical,
                'with_og_image' => $withOg,
                'coverage_percent' => round($withDescription / $total * 100, 1),
                'hint' => $withDescription < count($pageIds)
                    ? 'Части страниц без SEO-описания: проверьте карточки в админке.'
                    : 'Описания заполнены у всех страниц.',
            ];
        }, $user);
    }

    /** @return array<string, mixed> */
    public function searchVenues(?object $user, ?string $query, int $limit): array
    {
        $needle = $this->needle($query);

        return $this->scoped(function (array $orgIds) use ($needle, $limit): array {
            $rows = DB::table('venues')
                ->whereIn('organization_id', $orgIds)
                ->when($needle !== '', fn ($q) => $q->where(fn ($w) => $w
                    ->where('name', 'like', '%' . $needle . '%')
                    ->orWhere('city', 'like', '%' . $needle . '%')
                    ->orWhere('slug', 'like', '%' . $needle . '%')))
                ->orderBy('name')
                ->limit($this->limit($limit))
                ->get(['public_id', 'name', 'slug', 'city', 'address', 'status']);

            return ['venues' => $rows->map(static fn (object $r): array => [
                'public_id' => (string) $r->public_id,
                'name' => (string) $r->name,
                'slug' => (string) $r->slug,
                'city' => $r->city,
                'address' => $r->address,
                'status' => (string) $r->status,
            ])->all()];
        }, $user);
    }

    /** @return array<string, mixed> */
    public function searchHalls(?object $user, ?string $query, int $limit): array
    {
        $needle = $this->needle($query);

        return $this->scoped(function (array $orgIds) use ($needle, $limit): array {
            $rows = DB::table('halls')
                ->join('venues', 'venues.id', '=', 'halls.venue_id')
                ->whereIn('venues.organization_id', $orgIds)
                ->when($needle !== '', fn ($q) => $q->where('halls.name', 'like', '%' . $needle . '%'))
                ->orderBy('halls.name')
                ->limit($this->limit($limit))
                ->get(['halls.public_id', 'halls.name', 'halls.capacity', 'halls.width', 'halls.height', 'halls.status', 'venues.public_id as venue_public_id']);

            return ['halls' => $rows->map(static fn (object $r): array => [
                'public_id' => (string) $r->public_id,
                'name' => (string) $r->name,
                'capacity' => $r->capacity,
                'width' => $r->width,
                'height' => $r->height,
                'status' => (string) $r->status,
                'venue_public_id' => (string) $r->venue_public_id,
            ])->all()];
        }, $user);
    }

    /** @return array<string, mixed> */
    public function getHallSchema(?object $user, int $hallId): array
    {
        return $this->scoped(function (array $orgIds) use ($hallId): array {
            $row = DB::table('halls')
                ->join('venues', 'venues.id', '=', 'halls.venue_id')
                ->whereIn('venues.organization_id', $orgIds)
                ->where('halls.id', $hallId)
                ->first(['halls.id', 'halls.name', 'halls.capacity']);
            if ($row === null) {
                return ['found' => false];
            }

            $version = DB::table('hall_schema_versions')
                ->where('hall_id', $hallId)
                ->where('status', 'published')
                ->orderByDesc('version')
                ->first(['version', 'public_id', 'schema_json', 'width', 'height', 'published_at']);
            if ($version === null) {
                return ['found' => true, 'hall_id' => $hallId, 'published_version' => null,
                    'hint' => 'Опубликованной версии схемы нет.'];
            }

            $decoded = json_decode((string) $version->schema_json, true);

            return [
                'found' => true,
                'hall_id' => $hallId,
                'hall_name' => (string) $row->name,
                'published_version' => [
                    'version' => (int) $version->version,
                    'public_id' => (string) $version->public_id,
                    'width' => $version->width,
                    'height' => $version->height,
                    'published_at' => $version->published_at,
                    'schema' => is_array($decoded) ? $decoded : [],
                ],
            ];
        }, $user);
    }

    /** @param array<string, mixed> $schema @return array<string, mixed> */
    public function validateHallSchema(array $schema): array
    {
        return $this->halls->validateForAgent($schema);
    }

    /** @param array<string, mixed> $event @return array<string, mixed> */
    public function validateEvent(array $event): array
    {
        $validator = validator($event, [
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'venue_id' => ['nullable', 'integer'],
            'schema_version_id' => ['nullable', 'integer'],
            'age_limit' => ['nullable', 'string', 'max:16'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
        ]);

        if ($validator->fails()) {
            return ['valid' => false, 'errors' => $validator->errors()->toArray(), 'message' => 'Мероприятие не прошло проверку.'];
        }

        return ['valid' => true, 'errors' => [], 'message' => 'Мероприятие прошло проверку.'];
    }

    /** @param array<string, mixed> $template @return array<string, mixed> */
    public function validateTicketTemplate(array $template): array
    {
        try {
            $canonical = TicketTemplateConfig::normalize($template);
            TicketTemplateConfig::validate($canonical);

            return ['valid' => true, 'errors' => [], 'message' => 'Шаблон билета корректен.', 'format' => $canonical['version'] ?? null];
        } catch (\Nabilet\Core\Errors\ValidationError $e) {
            return ['valid' => false, 'errors' => $e->errors, 'message' => $e->getMessage()];
        } catch (\Throwable $e) {
            return ['valid' => false, 'errors' => [], 'message' => get_class($e) . ': не удалось нормализовать шаблон.'];
        }
    }

    /** @return array<string, mixed> */
    public function validatePaymentConfiguration(): array
    {
        $shop = trim((string) config('nabilet.payments.yookassa.shop_id', ''));
        $secret = trim((string) config('nabilet.payments.yookassa.secret_key', ''));
        $webhook = trim((string) config('nabilet.payments.yookassa.webhook_secret', ''));
        $providers = array_values((array) config('nabilet.payments.providers', []));
        $default = (string) config('nabilet.payments.default_provider', 'yookassa');
        $problems = [];

        if (! in_array('yookassa', $providers, true)) {
            $problems[] = 'ЮKassa не входит в список провайдеров.';
        }
        if (! in_array($default, $providers, true)) {
            $problems[] = 'Провайдер по умолчанию не включён в список.';
        }
        if ($shop === '' || $secret === '') {
            $problems[] = 'Не заданы Shop ID или секретный ключ ЮKassa.';
        }
        if ($webhook === '') {
            $problems[] = 'Не задан секрет вебхука: подпись входящих уведомлений проверить нельзя.';
        }

        return [
            'valid' => $problems === [],
            'problems' => $problems,
            'configured' => ['shop_id' => $shop !== '', 'secret_key' => $secret !== '', 'webhook_secret' => $webhook !== ''],
            'hint' => 'Проверка конфигурации не проверяет доступность API провайдера.',
        ];
    }

    /** @return array<string, mixed> */
    private function scoped(callable $callback, ?object $user): array
    {
        $scope = AgentScope::resolve($user);
        if ($scope === null) {
            return ['error_message' => 'Токен не привязан ни к одной организации.'];
        }

        /** @var array<string, mixed> $result */
        $result = $scope->run($callback);

        return $result;
    }

    /** @param array<int, int> $orgIds @return array<string, mixed> */
    private function storefrontConfig(array $orgIds): array
    {
        $row = DB::table('storefront_settings')
            ->whereIn('organization_id', $orgIds)
            ->orderByDesc('id')
            ->first(['config']);
        if ($row === null) {
            return [];
        }
        $decoded = json_decode((string) $row->config, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function needle(?string $query): string
    {
        return mb_substr(trim((string) $query), 0, 120);
    }

    private function limit(int $limit): int
    {
        return max(1, min(50, $limit));
    }

    /** @param mixed $value @return mixed */
    private function mask(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && preg_match('/(password|secret|token|api_?key|credential|authorization)/i', $key) === 1) {
                $out[$key] = '***';
                continue;
            }
            $out[$key] = $this->mask($item);
        }

        return $out;
    }
}
