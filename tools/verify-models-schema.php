<?php

declare(strict_types=1);

/**
 * Model ↔ schema drift.
 *
 * Usage:
 *     php tools/verify-models-schema.php [--list] [--report]
 *
 * WHY THIS EXISTS
 *   This dependency-free check compares the Eloquent layer under
 *   `app/Modules/{module}/Models/` with the canonical SQL DDL plus explicitly
 *   declared application-owned additive columns. It does so without booting Laravel
 *   or opening a database connection. Each extension must point to a migration whose
 *   exact table/column declaration is verified below; this is not a general drift
 *   allowlist. The check catches invalid table/fillable/cast names, but it does not
 *   prove that a runtime query, relation or migration works; those still need
 *   PHP/MySQL integration tests.
 *
 *   This project has already paid for schema-name drift once: middleware read
 *   `key`, `response_code` and `updated_at`, none of which existed. Checking model
 *   fields against the DDL makes that class of error visible before runtime.
 *
 * WHAT IT CHECKS
 *   * every model's `$table` exists in the schema;
 *   * every `$fillable` entry is a real column of that table;
 *   * every `$casts` key is a real column of that table;
 *   * a model that declares the `SoftDeletes` trait has a `deleted_at` column.
 *
 *   It does NOT check types, nullability or casts semantics. A `$casts` entry of
 *   'array' on a TEXT column is wrong in a way this tool cannot see; only a real
 *   database can settle that.
 *
 * WHY THE SOFT-DELETE CHECK EXISTS
 *   `SoftDeletes` is not a hint — it rewrites every query on the model, adding
 *   `where <table>.deleted_at is null`. A model that declares the trait on a table
 *   without the column is therefore not "soft delete does not work"; it is a model
 *   that CANNOT BE QUERIED AT ALL: the first `Organization::query()` raises
 *   `SQLSTATE[42S22] Column not found: 1054`.
 *
 *   That is not hypothetical. `app/Modules/Organizations/Models/Organization.php`
 *   (a legacy duplicate of the canonical
 *   `Nabilet\Modules\Core\Organizations\Models\Organization`) carried the trait
 *   while `organizations` has no `deleted_at` in either the migration or the spec
 *   bundle. Nothing here noticed, because a trait name is not a `$fillable` entry;
 *   it surfaced only when a live request hit the model. Checking it here costs one
 *   regex over text this loop already reads.
 *
 * HOW A GREEN RESULT IS TRUSTED
 *   The DDL parser is asserted against EXPECTED_TABLES / EXPECTED_COLUMNS, taken
 *   from `information_schema` on MySQL 8.4. A parser that under-reads reports
 *   phantom drift, and `verify-contract-schema.php` spent a while doing exactly
 *   that: it read only CREATE blocks, so `tickets.revoked_at` looked absent and the
 *   false conclusion was written into its ACCEPTED_DRIFT list as a known gap. The
 *   parse is therefore proved before any drift is reported.
 *
 * DRIFT THAT IS KNOWN
 *   INVENTED_FIELDS is a ratchet, not a silencing mechanism. Every entry names the
 *   field and says why it is tolerated; anything not listed fails the build. Fixing
 *   a field means deleting its entry, and the list can only shrink.
 */

$root = dirname(__DIR__);
$spec = $root . '/nabilet_core_spec/migrations.sql';

$listMode = in_array('--list', $argv, true);
$reportMode = in_array('--report', $argv, true);

/*
 * Сколько таблиц и колонок в спеке — из `tools/spec-counts.php`. Раньше числа
 * стояли здесь, в `verify-contract-schema.php` и в `verify-live.php`, то есть в
 * трёх местах, и разъезжались трижды: обновляли два файла из трёх.
 */
require __DIR__ . '/spec-counts.php';

/**
 * Application-owned additions to the Core schema, each tied to its owning migration.
 * These are included only after the migration's exact declaration is verified. Keep
 * this list minimal; a stale or renamed declaration must fail instead of masking drift.
 *
 * @var list<array{table: string, column: string, migration: string, pattern: string}>
 */
const APPLICATION_SCHEMA_EXTENSIONS = [
    // EMPTY on purpose. Every column that used to be listed here now lives in the
    // Core DDL itself (nabilet_core_spec/migrations/012_application_domain.sql):
    // hall_schema_versions.revision, halls.city/address/exterior_photo_url/
    // interior_photo_url and seats.price_amount. The mechanism stays because it is
    // the right place to record the NEXT application-owned column — the guard
    // below fails loudly if an entry duplicates the Core DDL, which is exactly how
    // this list was emptied.
];

/**
 * Fields the generated models declare that the schema does not have.
 *
 * Grouped by model. The reason is shared per model because the cause is shared:
 * these models were produced from a description of the domain rather than from the
 * DDL, so they name the column the author expected instead of the column that
 * exists. Each entry is a to-do, not a decision — either the model is wrong and
 * loses the field, or the schema is wrong and gains it. The schema is the source
 * of truth, so the default is that the model is wrong.
 *
 * @var array<string, array<string, string>>
 */
const APPLICATION_EXTENSION_FIELDS = [
    'HallSchemaVersion' => [
        'revision' => 'optimistic-lock token owned by the Laravel application migration 2026_10_06_000100; checked for nullable/no-default in verify-migrations.php and deliberately excluded from the standalone Core bundle',
    ],
    'Hall' => [
        'city' => 'сведения о зале, добавленные миграцией 2026_10_07_000400; правятся отдельно от версии схемы и намеренно не входят в Core-бандл',
        'address' => 'сведения о зале, добавленные миграцией 2026_10_07_000400; правятся отдельно от версии схемы',
        'exterior_photo_url' => 'фото зала, добавленное миграцией 2026_10_07_000400',
        'interior_photo_url' => 'фото зала, добавленное миграцией 2026_10_07_000400',
    ],
    'Seat' => [
        'price_amount' => 'цена конкретного места (миграция 2026_10_07_000400); перекрывает цену ряда, поэтому не выводима из inventories',
    ],
];

/**
 * Таблицы, которых нет в Core-бандле потому, что их создают миграции приложения.
 *
 * Проверять поля такой модели нечем: DDL для неё отсутствует, поэтому модель
 * пропускается целиком. Список обязан совпадать с app-only таблицами в
 * tools/verify-migrations.php — там же обосновывается, почему таблица не в спеке.
 *
 * @var list<string>
 */
const APPLICATION_ONLY_TABLES = [
    'storefront_settings', // конструктор витрины (модуль Storefront)
];

const INVENTED_FIELDS = [
    'AbAssignment' => [
        'ab_experiment_id' => 'names ab_experiment_id/ab_variant_id/visitor_id; ab_assignments stores the experiment and variant as experiment_id/variant_id',
        'ab_variant_id' => 'names ab_experiment_id/ab_variant_id/visitor_id; ab_assignments stores the experiment and variant as experiment_id/variant_id',
        'visitor_id' => 'names ab_experiment_id/ab_variant_id/visitor_id; ab_assignments stores the experiment and variant as experiment_id/variant_id',
    ],
    'AbExperiment' => [
        'ended_at' => 'names started_at/ended_at; ab_experiments stores starts_at/ends_at',
        'started_at' => 'names started_at/ended_at; ab_experiments stores starts_at/ends_at',
    ],
    'AbMetric' => [
        'ab_experiment_id' => 'names ab_experiment_id and four columns (name, goal_type, configuration) that ab_metrics does not have at all',
        'configuration' => 'names ab_experiment_id and four columns (name, goal_type, configuration) that ab_metrics does not have at all',
        'goal_type' => 'names ab_experiment_id and four columns (name, goal_type, configuration) that ab_metrics does not have at all',
        'name' => 'names ab_experiment_id and four columns (name, goal_type, configuration) that ab_metrics does not have at all',
    ],
    'AbVariant' => [
        'ab_experiment_id' => 'names ab_experiment_id/weight/configuration; ab_variants stores experiment_id, allocation_percent, payload_json',
        'configuration' => 'names ab_experiment_id/weight/configuration; ab_variants stores experiment_id, allocation_percent, payload_json',
        'weight' => 'names ab_experiment_id/weight/configuration; ab_variants stores experiment_id, allocation_percent, payload_json',
    ],
    'AnalyticsEvent' => [
        'event_type' => 'names event_type/properties; analytics_events stores event_name/properties_json',
        'properties' => 'names event_type/properties; analytics_events stores event_name/properties_json',
    ],
    // `ApiKey` is deliberately absent: `app/Modules/System/Models/ApiKey.php`
    // declared `token_hash`, `abilities` and `last_used_at`, none of which is a
    // column. The model was rewritten against the real DDL
    // (`key_prefix`, `key_hash`, `scopes_json`, `expires_at`, `revoked_at`) and
    // the three entries were removed here in the same change — a ratchet that
    // keeps an entry after the debt is paid stops being a ratchet.
    // `Cart`, `MediaAsset`, `MediaLink`, `PromoCode`, `SeatHold`, `Ticket`,
    // `TicketScan` — записи удалены проверкой на устаревание (см. `$inventedSeen`
    // ниже). Все эти модели уже приведены к реальной схеме, поэтому исключения
    // не срабатывали ни разу: они остались от прошлых правок и молча прикрывали
    // бы следующий класс с тем же коротким именем. Нашлось в тот же прогон, в
    // котором проверка появилась, — то есть цена «оставить на всякий случай»
    // оказалась ровно такой, как предупреждает комментарий про `Page`.
    'HeatmapEvent' => [
        'properties' => 'names properties/scroll_depth; heatmap_events stores payload_json and has no scroll depth',
        'scroll_depth' => 'names properties/scroll_depth; heatmap_events stores payload_json and has no scroll depth',
    ],
    'IdempotencyKey' => [
        'key' => 'names key/value; idempotency_keys stores key_hash and has no value column. This is the SAME defect the middleware had before it was fixed, reintroduced in the model',
        'value' => 'names key/value; idempotency_keys stores key_hash and has no value column. This is the SAME defect the middleware had before it was fixed, reintroduced in the model',
    ],
    'IpRule' => [
        'is_active' => 'names is_active; ip_rules stores active',
    ],
    'Module' => [
        'config' => 'names is_active/config; modules stores enabled/config_json',
        'is_active' => 'names is_active/config; modules stores enabled/config_json',
    ],
    // `Page` снят с исключений (стратегия SEO, B-2): модель приведена к реальной
    // схеме (`public_id`/`organization_id`/`title`/`slug`/`content`/`status`/
    // `published_at`), и расхождения больше нет. Запись удалена, а не оставлена
    // «на всякий случай»: исключение без причины — это молчаливое разрешение
    // снова разойтись.
    // `Redirect` снят по той же причине: модель переписана под реальную схему
    // (`source`/`destination`/`active`), расхождения больше нет.
    'PageTranslation' => [
        'meta_description' => 'names meta_description; page_translations stores description/seo_description',
    ],
    'SeoMeta' => [
        'meta_description' => 'names meta_title/meta_description/page_id/subject_type/subject_id; seo_meta stores entity_type/entity_id, title/description',
        'meta_title' => 'names meta_title/meta_description/page_id/subject_type/subject_id; seo_meta stores entity_type/entity_id, title/description',
        'page_id' => 'names meta_title/meta_description/page_id/subject_type/subject_id; seo_meta stores entity_type/entity_id, title/description',
        'subject_id' => 'names meta_title/meta_description/page_id/subject_type/subject_id; seo_meta stores entity_type/entity_id, title/description',
        'subject_type' => 'names meta_title/meta_description/page_id/subject_type/subject_id; seo_meta stores entity_type/entity_id, title/description',
    ],
    // `Webhook`, `WebhookDelivery`, `WebhookEvent` — записи удалены.
    //
    // Они прикрывали ТРИ модели в `app/Modules/System/Models/`, которые
    // объявляли колонки, не существующие ни в одной таблице: `name`, `secret`,
    // `events`, `is_active` (таблица `webhooks` хранит `url`, `secret_encrypted`,
    // `events_json`, `active`, `retry_limit`), `webhook_event_id`, `status`,
    // `attempt_count`, `response_code` (в `webhook_deliveries` их нет),
    // `event_type`, `payload` (в `webhook_events` — `provider`,
    // `provider_event_id`, `event_name`, `payload_json`).
    //
    // Это была вторая, «мёртвая» половина пары: живые модели для тех же таблиц
    // лежат в `app/Modules/Webhooks/Models/` и схеме соответствуют. Держать
    // исключения после удаления фантомов нельзя — иначе следующий класс с тем
    // же коротким именем разойдётся со схемой молча. Ровно так же здесь уже
    // снимались записи `ApiKey`, `Page` и `Redirect`.
    //
    // Заодно: причина, записанная в этих исключениях, сама была неверной —
    // «webhooks stores url/secret_hash/event_types/active» описывает таблицу,
    // которой нет. Исключение, обоснование которого никто не перепроверил,
    // переживает и модель, и схему, ради которых его писали.
];

/**
 * Parse the spec DDL into table => columns.
 *
 * @return array<string, list<string>>
 */
function parseDdl(string $file): array
{
    $sql = file_get_contents($file);
    $tables = [];

    preg_match_all(
        '/CREATE TABLE (?:IF NOT EXISTS )?`?(\w+)`?\s*\((.*?)\n\)\s*ENGINE=/s',
        $sql,
        $matches,
        \PREG_SET_ORDER
    );

    foreach ($matches as $match) {
        $columns = [];

        foreach (explode("\n", $match[2]) as $line) {
            $line = trim($line);

            // Table-level clauses are not columns. The lookahead is load-bearing:
            // a case-insensitive `CHECK` otherwise matches the first five letters
            // of `checkin_device_id` and the column is silently dropped.
            if (preg_match('/^(PRIMARY KEY|UNIQUE KEY|KEY|INDEX|CONSTRAINT|CHECK|FULLTEXT|SPATIAL)(?=[\s(])/i', $line)) {
                continue;
            }

            if ($line === '' || str_starts_with($line, '--')) {
                continue;
            }

            if (preg_match('/^`?(\w+)`?\s/', $line, $m)) {
                $columns[] = $m[1];
            }
        }

        $tables[$match[1]] = $columns;
    }

    // Migration 010 appends columns to tables that already exist, so these are in
    // no CREATE block. Reading only CREATE blocks is the bug described up top.
    preg_match_all('/ALTER TABLE\s+`?(\w+)`?\s+(.*?);\s*(?:\n|$)/si', $sql, $alters, \PREG_SET_ORDER);

    foreach ($alters as $alter) {
        $table = $alter[1];

        if (! isset($tables[$table])) {
            continue;
        }

        if (preg_match_all('/ADD\s+COLUMN\s+`?(\w+)`?/i', $alter[2], $added)) {
            foreach ($added[1] as $name) {
                $tables[$table][] = $name;
            }
        }
    }

    return $tables;
}

/**
 * Add only application columns whose owning migration still declares them exactly.
 *
 * @param array<string, list<string>> $tables
 * @return array<string, list<string>>
 */
function applyApplicationSchemaExtensions(string $root, array $tables): array
{
    foreach (APPLICATION_SCHEMA_EXTENSIONS as $extension) {
        $table = $extension['table'];
        $column = $extension['column'];
        $migrationPath = $root . '/' . $extension['migration'];

        if (! isset($tables[$table])) {
            throw new RuntimeException(sprintf(
                'Application extension %s.%s targets a table absent from the Core schema',
                $table,
                $column,
            ));
        }

        if (! is_file($migrationPath)) {
            throw new RuntimeException('Application extension migration is missing: ' . $extension['migration']);
        }

        $migration = file_get_contents($migrationPath);

        if ($migration === false || ! preg_match($extension['pattern'], $migration)) {
            throw new RuntimeException(sprintf(
                'Application extension %s.%s is no longer declared by %s',
                $table,
                $column,
                $extension['migration'],
            ));
        }

        if (in_array($column, $tables[$table], true)) {
            throw new RuntimeException(sprintf(
                'Application extension %s.%s is already in the Core DDL; remove the duplicate extension entry',
                $table,
                $column,
            ));
        }

        $tables[$table][] = $column;
    }

    return $tables;
}

/**
 * Does the model's own source declare the `SoftDeletes` trait?
 *
 * IMPORT LINES MUST NOT COUNT. `use Illuminate\Database\Eloquent\SoftDeletes;`
 * is a use statement and says nothing about whether the trait is applied. Trait
 * usage never contains a namespace separator, so a statement containing a
 * backslash is treated as an import and skipped — which also covers the combined
 * form `use HasFactory, SoftDeletes;`.
 *
 * Only the model's own source is read, not the `extends` chain: every parent in
 * this tree is another model, and this loop already visits all of them, so a
 * trait inherited from a parent is reported against the parent.
 */
function usesSoftDeletes(string $src): bool
{
    if (! preg_match_all('/^\s*use\s+([^;]+);/m', $src, $statements)) {
        return false;
    }

    foreach ($statements[1] as $statement) {
        if (str_contains($statement, '\\')) {
            continue;
        }

        if (preg_match('/\bSoftDeletes\b/', $statement)) {
            return true;
        }
    }

    return false;
}

/**
 * Every model file: app/Modules/ * /Models/ plus app/Models.
 *
 * app/Models used to be excluded, which left the twelve legacy models there
 * unchecked — and they are exactly the layer that was generated from a
 * description of the domain rather than from the DDL, so they are the ones most
 * likely to name a column that does not exist. Most of that directory (42 files,
 * 2045 lines) turned out to be unreachable from anywhere outside itself and was
 * deleted; the survivors are live, so they get checked like everything else.
 *
 * @return list<string>
 */
function findModels(string $root): array
{
    $found = [];

    $scan = static function (string $dir, bool $requireModelsSegment) use (&$found): void {
        if (! is_dir($dir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());

            if ($requireModelsSegment && ! str_contains($path, '/Models/')) {
                continue;
            }

            // `Models/Concerns/` держит ТРЕЙТЫ, а не модели: у трейта нет ни
            // `$table`, ни своей таблицы в схеме, и проверять его как модель
            // бессмысленно. Пропускаем по виду объявления, а не по пути —
            // трейт в любом месте под `Models/` перестаёт считаться моделью.
            $source = (string) file_get_contents($file->getPathname());

            if (preg_match('/^\s*(?:final\s+|abstract\s+)?trait\s+\w+/m', $source) === 1) {
                continue;
            }

            $found[] = $file->getPathname();
        }
    };

    $scan($root . '/app/Modules', true);
    $scan($root . '/app/Models', false);

    sort($found);

    return $found;
}

/**
 * Extract a PHP string-array property body, e.g. `protected $fillable = [...]`.
 */
function extractArrayBody(string $src, string $property): ?string
{
    if (preg_match('/\$' . preg_quote($property, '/') . '\s*=\s*\[(.*?)\]/s', $src, $m)) {
        return $m[1];
    }

    return null;
}

/**
 * Keys of a `['key' => ...]` map.
 *
 * @return list<string>
 */
function mapKeys(string $body): array
{
    if (preg_match_all('/[\'"]([^\'"]+)[\'"]\s*=>/', $body, $m)) {
        return $m[1];
    }

    return [];
}

/**
 * Values of a `['value', 'value']` list.
 *
 * Every quoted string in the body is a value. Matching on a trailing comma or
 * bracket instead would silently drop the last element — which is the kind of
 * miss that makes a verifier report success while inspecting almost everything.
 *
 * @return list<string>
 */
function listValues(string $body): array
{
    if (preg_match_all('/[\'"]([^\'"]+)[\'"]/', $body, $m)) {
        return $m[1];
    }

    return [];
}

echo "\nNABILET Core — model ↔ schema drift\n";
echo str_repeat('─', 74), "\n\n";

$tables = parseDdl($spec);
$columnCount = array_sum(array_map('count', $tables));

if (count($tables) !== EXPECTED_TABLES || $columnCount !== EXPECTED_COLUMNS) {
    fwrite(\STDERR, sprintf(
        "  FAIL  the DDL parser read %d tables / %d columns, expected %d / %d.\n"
        . "        The parser is wrong, not the schema — fix parseDdl() before trusting\n"
        . "        any drift reported below.\n\n",
        count($tables),
        $columnCount,
        EXPECTED_TABLES,
        EXPECTED_COLUMNS
    ));

    exit(1);
}

$tables = applyApplicationSchemaExtensions($root, $tables);
$columnCount = array_sum(array_map('count', $tables));

$models = findModels($root);
$rootPrefix = str_replace('\\', '/', $root) . '/';

if ($models === []) {
    fwrite(\STDERR, "  FAIL  no models found under app/Modules/ * /Models/. Either they moved or\n"
        . "        the discovery in findModels() is broken; a green run here would mean nothing.\n\n");

    exit(1);
}

printf(
    "  Core schema: %d tables, %d columns; verified application extensions: %d column(s)\n",
    count($tables),
    EXPECTED_COLUMNS,
    count(APPLICATION_SCHEMA_EXTENSIONS),
);
printf("  Models: %d\n\n", count($models));

$unknownTables = [];
$unknownFields = [];
$softDeleteWithoutColumn = [];
$noTable = [];
$checkedFields = 0;
$applicationExtensionsSeen = [];
/**
 * Class => field => true, for every `INVENTED_FIELDS` entry actually used.
 *
 * Без этого набора исключение живёт вечно: модель удалили или привели к схеме,
 * а запись осталась и молча прикрывает следующий класс с тем же именем. Именно
 * так `Webhook`, `WebhookDelivery` и `WebhookEvent` пережили и свои фантомные
 * модели, и правку схемы.
 *
 * @var array<string, array<string, true>>
 */
$inventedSeen = [];

/**
 * FQCN => source, for every model we scan.
 *
 * Needed to follow `extends`. A module may declare a thin alias model that
 * inherits $table from a sibling (e.g.
 * Nabilet\Modules\Venues\Halls\Models\Hall extends Nabilet\Modules\Venues\Models\Hall).
 * Reading only the file's own text reported those as "no $table" and failed the
 * gate on code that is correct — a false positive, which is worse than no check,
 * because the tempting fix is to sprinkle redundant $table properties around
 * until the checker stops complaining.
 */
$srcByFqcn = [];
foreach ($models as $path) {
    $src = file_get_contents($path);
    $ns = preg_match('/^\s*namespace\s+([^;]+);/m', $src, $nm) ? trim($nm[1]) : '';
    if (preg_match('/^\s*(?:final\s+|abstract\s+)?class\s+(\w+)/m', $src, $cm)) {
        $srcByFqcn[($ns !== '' ? $ns . '\\' : '') . $cm[1]] = $src;
    }
}

/**
 * Find `$table` in a model, following `extends` up to 4 levels.
 * Returns null when nothing in the chain declares one.
 */
$findTable = function (string $src, string $fqcn, int $depth = 0) use (&$findTable, $srcByFqcn): ?string {
    if (preg_match('/\$table\s*=\s*[\'"]([^\'"]+)[\'"]/', $src, $tm)) {
        return $tm[1];
    }
    if ($depth >= 4 || ! preg_match('/\bclass\s+\w+\s+extends\s+([\\\\\w]+)/', $src, $em)) {
        return null;
    }

    $parent = ltrim($em[1], '\\');

    // Unqualified parent: either a `use ... as X;` alias, or a sibling in the
    // same namespace.
    if (! str_contains($parent, '\\')) {
        $aliases = [];
        if (preg_match_all('/^use\s+([\\\\\w]+?)(?:\s+as\s+(\w+))?\s*;/m', $src, $um, \PREG_SET_ORDER)) {
            foreach ($um as $u) {
                $aliases[$u[2] ?? substr($u[1], (int) strrpos('\\' . $u[1], '\\'))] = ltrim($u[1], '\\');
            }
        }
        if (isset($aliases[$parent])) {
            $parent = $aliases[$parent];
        } else {
            $ns = preg_match('/^\s*namespace\s+([^;]+);/m', $src, $nm) ? trim($nm[1]) : '';
            $parent = ($ns !== '' ? $ns . '\\' : '') . $parent;
        }
    }

    return isset($srcByFqcn[$parent]) ? $findTable($srcByFqcn[$parent], $parent, $depth + 1) : null;
};

foreach ($models as $path) {
    $src = file_get_contents($path);
    $rel = str_replace($rootPrefix, '', str_replace('\\', '/', $path));
    $class = pathinfo($path, \PATHINFO_FILENAME);

    $ownNs = preg_match('/^\s*namespace\s+([^;]+);/m', $src, $onm) ? trim($onm[1]) : '';
    $table = $findTable($src, ($ownNs !== '' ? $ownNs . '\\' : '') . $class);

    if ($table === null) {
        // No $table anywhere in the chain: Eloquent would pluralise the class
        // name, which for this schema is wrong often enough to be worth naming
        // rather than guessing at.
        $noTable[] = $rel;
        continue;
    }

    if (! isset($tables[$table])) {
        if (in_array($table, APPLICATION_ONLY_TABLES, true)) {
            continue; // таблица приложения, DDL для неё проверяет verify-migrations.php
        }

        $unknownTables[] = sprintf('%s (%s) -> %s', $rel, $class, $table);
        continue;
    }

    // `SoftDeletes` переписывает КАЖДЫЙ запрос модели, добавляя
    // `where <table>.deleted_at is null`, поэтому при отсутствующей колонке
    // модель непригодна к использованию, а не «мягко не удаляет».
    if (usesSoftDeletes($src) && ! in_array('deleted_at', $tables[$table], true)) {
        $softDeleteWithoutColumn[] = sprintf(
            '%s  %s -> %s has no deleted_at column',
            str_pad($rel, 52),
            $class,
            $table,
        );
    }

    $declared = [];

    if (($body = extractArrayBody($src, 'fillable')) !== null) {
        $declared = array_merge($declared, listValues($body));
    }

    if (($body = extractArrayBody($src, 'casts')) !== null) {
        $declared = array_merge($declared, mapKeys($body));
    }

    if (($body = extractArrayBody($src, 'dates')) !== null) {
        $declared = array_merge($declared, listValues($body));
    }

    foreach (array_unique($declared) as $field) {
        $checkedFields++;

        if ($field === 'id') {
            continue;
        }

        if (isset(APPLICATION_EXTENSION_FIELDS[$class][$field])) {
            if (!in_array($field, $tables[$table], true)) {
                $unknownFields[$class . '.' . $field] = sprintf(
                    '%s  %s -> %s.%s (declared application extension is not present in parsed schema)',
                    str_pad($rel, 52),
                    $class,
                    $table,
                    $field,
                );
            } else {
                $applicationExtensionsSeen[$class][$field] = true;
            }

            continue;
        }

        if (in_array($field, $tables[$table], true)) {
            continue;
        }

        $key = $class . '.' . $field;

        if ($listMode) {
            $unknownFields[$key] = sprintf('%s  (%s)', $field, $table);
            continue;
        }

        if (isset(INVENTED_FIELDS[$class][$field])) {
            $inventedSeen[$class][$field] = true;
            continue;
        }

        $unknownFields[$key] = sprintf(
            '%s  %s -> %s.%s',
            str_pad($rel, 52),
            $class,
            $table,
            $field
        );
    }
}

$staleApplicationExtensions = [];
foreach (APPLICATION_EXTENSION_FIELDS as $class => $fields) {
    foreach ($fields as $field => $reason) {
        if (!isset($applicationExtensionsSeen[$class][$field])) {
            $staleApplicationExtensions[] = $class . '.' . $field . ' — ' . $reason;
        }
    }
}

if ($staleApplicationExtensions !== []) {
    foreach ($staleApplicationExtensions as $extension) {
        $unknownFields['stale:' . $extension] = 'stale application extension entry: ' . $extension;
    }
}

/**
 * Исключение обязано оставаться нужным.
 *
 * `APPLICATION_EXTENSION_FIELDS` такую проверку имел с самого начала, а
 * `INVENTED_FIELDS` — нет. Разница вылезла на `Webhook`: три записи прикрывали
 * модели, которых больше нет, и ничто не заставляло их снять. Исключение без
 * владельца — это разрешение, которое никто не пересматривает; поэтому набор
 * использованных записей собирается выше, а неиспользованные падают здесь.
 */
$staleInvented = [];
foreach (INVENTED_FIELDS as $class => $fields) {
    foreach ($fields as $field => $reason) {
        if (! isset($inventedSeen[$class][$field])) {
            $staleInvented[] = $class . '.' . $field . ' — ' . $reason;
        }
    }
}

if ($staleInvented !== []) {
    foreach ($staleInvented as $entry) {
        $unknownFields['stale:' . $entry] = 'stale invented-field entry: ' . $entry;
    }
}

if ($listMode) {
    foreach ($unknownFields as $key => $line) {
        printf("  %-46s %s\n", $key, $line);
    }

    printf("\n  %d fields checked, %d not in the schema\n\n", $checkedFields, count($unknownFields));

    exit(0);
}

printf("  %d fields declared across %d models\n\n", $checkedFields, count($models) - count($noTable));

$failed = false;

if ($noTable !== []) {
    $failed = true;
    echo "  Models with no \$table (Eloquent would pluralise the class name):\n";
    foreach ($noTable as $line) {
        echo "    $line\n";
    }
    echo "\n";
}

if ($unknownTables !== []) {
    $failed = true;
    echo "  \$table not in the schema:\n";
    foreach ($unknownTables as $line) {
        echo "    $line\n";
    }
    echo "\n";
}

if ($unknownFields !== []) {
    $failed = true;
    echo sprintf("  %d declared fields are not columns of their table:\n\n", count($unknownFields));
    foreach ($unknownFields as $line) {
        echo "    $line\n";
    }
    echo "\n";
}

if ($softDeleteWithoutColumn !== []) {
    $failed = true;
    echo "  Models with the SoftDeletes trait on a table that has no deleted_at:\n\n";
    foreach ($softDeleteWithoutColumn as $line) {
        echo "    $line\n";
    }
    echo "\n    This is not a soft-delete bug — it is a model that cannot be queried:\n";
    echo "    every request through it fails with SQLSTATE[42S22] Column not found.\n";
    echo "    Either drop the trait from the model, or add the column to the spec\n";
    echo "    bundle together with the decision that organizations are soft-deleted.\n\n";
}

if ($reportMode) {
    printf(
        "  %d tables, %d models, %d fields checked, %d unknown, %d known\n\n",
        count($tables),
        count($models),
        $checkedFields,
        count($unknownFields),
        array_sum(array_map('count', INVENTED_FIELDS))
    );
}

if ($failed) {
    echo "  FAIL — the models do not match the schema. Either fix the model, or change\n";
    echo "         the schema in the spec bundle and record the decision. Do not add to\n";
    echo "         INVENTED_FIELDS to silence it.\n\n";

    exit(1);
}

$known = array_sum(array_map('count', INVENTED_FIELDS));

if ($known > 0) {
    printf(
        "  PASS — no NEW drift. %d declared fields still name columns the schema does not\n"
        . "         have, all listed in INVENTED_FIELDS. Each is a to-do: fix the model, or\n"
        . "         change the spec bundle and record the decision. This list may only shrink.\n\n",
        $known
    );

    exit(0);
}

echo "  PASS — every declared field exists in its table.\n\n";

exit(0);
