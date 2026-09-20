<?php

declare(strict_types=1);

/**
 * Model ↔ schema drift.
 *
 * Usage:
 *     php tools/verify-models-schema.php [--list] [--report]
 *
 * WHY THIS EXISTS
 *   The Eloquent layer under `app/Modules/ * /Models/` was generated, not written
 *   against the database, and it cannot be executed here — there is no `vendor/`
 *   and no reachable MySQL, so `php artisan migrate` has never run and not one of
 *   these models has ever loaded a row. Every column name in them is therefore a
 *   claim that nothing has tested.
 *
 *   This project has already paid for that once: the idempotency middleware read
 *   `key`, `response_code` and `updated_at`, and none of those columns exist. The
 *   failure mode is always the same — a name that looks right, in code that cannot
 *   run, discovered in production. So the names are checked against the DDL here,
 *   where the check is cheap.
 *
 * WHAT IT CHECKS
 *   * every model's `$table` exists in the schema;
 *   * every `$fillable` entry is a real column of that table;
 *   * every `$casts` key is a real column of that table.
 *
 *   It does NOT check types, nullability or casts semantics. A `$casts` entry of
 *   'array' on a TEXT column is wrong in a way this tool cannot see; only a real
 *   database can settle that.
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

/** From information_schema on MySQL 8.4. Update in the same commit as the schema. */
const EXPECTED_TABLES = 64;
const EXPECTED_COLUMNS = 678;

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
    'ApiKey' => [
        'abilities' => 'names token_hash/abilities/last_used_at; api_keys stores key_hash/scopes_json and has NO last-used column at all -- the COUNT of such columns returned 0 (REVIEW §3.19)',
        'last_used_at' => 'names token_hash/abilities/last_used_at; api_keys stores key_hash/scopes_json and has NO last-used column at all -- the COUNT of such columns returned 0 (REVIEW §3.19)',
        'token_hash' => 'names token_hash/abilities/last_used_at; api_keys stores key_hash/scopes_json and has NO last-used column at all -- the COUNT of such columns returned 0 (REVIEW §3.19)',
    ],
    'Cart' => [
        'currency' => 'names currency/total_amount; carts stores total_price and has no currency column',
        'total_amount' => 'names currency/total_amount; carts stores total_price and has no currency column',
    ],
    'CheckinDevice' => [
        'device_token' => 'names device_token/is_active/last_synced_session_id; checkin_devices stores token_hash/active and no session pointer',
        'is_active' => 'names device_token/is_active/last_synced_session_id; checkin_devices stores token_hash/active and no session pointer',
        'last_synced_session_id' => 'names device_token/is_active/last_synced_session_id; checkin_devices stores token_hash/active and no session pointer',
    ],
    'Consent' => [
        'channel' => 'names channel/is_subscribed; consents stores consent_type/status',
        'is_subscribed' => 'names channel/is_subscribed; consents stores consent_type/status',
    ],
    'HeatmapEvent' => [
        'properties' => 'names properties/scroll_depth; heatmap_events stores payload_json and has no scroll depth',
        'scroll_depth' => 'names properties/scroll_depth; heatmap_events stores payload_json and has no scroll depth',
    ],
    'IdempotencyKey' => [
        'key' => 'names key/value; idempotency_keys stores key_hash and has no value column. This is the SAME defect the middleware had before it was fixed, reintroduced in the model',
        'value' => 'names key/value; idempotency_keys stores key_hash and has no value column. This is the SAME defect the middleware had before it was fixed, reintroduced in the model',
    ],
    'InventoryItem' => [
        'price' => 'names quantity/price; inventory_items stores capacity/available_quantity and price_amount (the quantity model, not the old status enum)',
        'quantity' => 'names quantity/price; inventory_items stores capacity/available_quantity and price_amount (the quantity model, not the old status enum)',
    ],
    'IpRule' => [
        'is_active' => 'names is_active; ip_rules stores active',
    ],
    'MediaAsset' => [
        'name' => 'names name; media_assets stores filename/title',
    ],
    'MediaLink' => [
        'caption' => 'names subject_type/subject_id/caption; media_links stores entity_type/entity_id and has no caption',
        'subject_id' => 'names subject_type/subject_id/caption; media_links stores entity_type/entity_id and has no caption',
        'subject_type' => 'names subject_type/subject_id/caption; media_links stores entity_type/entity_id and has no caption',
    ],
    'Module' => [
        'config' => 'names is_active/config; modules stores enabled/config_json',
        'is_active' => 'names is_active/config; modules stores enabled/config_json',
    ],
    'Notification' => [
        'notification_template_id' => 'names notification_template_id/payload; notifications stores template_id/payload_json',
        'payload' => 'names notification_template_id/payload; notifications stores template_id/payload_json',
    ],
    'NotificationTemplate' => [
        'body_template' => 'names name/subject_template/body_template/variables/is_active; notification_templates stores code/subject/body_text/body_html/active',
        'is_active' => 'names name/subject_template/body_template/variables/is_active; notification_templates stores code/subject/body_text/body_html/active',
        'name' => 'names name/subject_template/body_template/variables/is_active; notification_templates stores code/subject/body_text/body_html/active',
        'subject_template' => 'names name/subject_template/body_template/variables/is_active; notification_templates stores code/subject/body_text/body_html/active',
        'variables' => 'names name/subject_template/body_template/variables/is_active; notification_templates stores code/subject/body_text/body_html/active',
    ],
    'OfflineBundle' => [
        'created_by' => 'names name/created_by/encrypted_payload; offline_bundles stores bundle_hash/payload_json and no creator column',
        'encrypted_payload' => 'names name/created_by/encrypted_payload; offline_bundles stores bundle_hash/payload_json and no creator column',
        'name' => 'names name/created_by/encrypted_payload; offline_bundles stores bundle_hash/payload_json and no creator column',
    ],
    'Order' => [
        'metadata' => 'names subtotal/tax_amount/metadata; orders stores subtotal_amount, has no tax column, and stores no metadata',
        'subtotal' => 'names subtotal/tax_amount/metadata; orders stores subtotal_amount, has no tax column, and stores no metadata',
        'tax_amount' => 'names subtotal/tax_amount/metadata; orders stores subtotal_amount, has no tax column, and stores no metadata',
    ],
    'OrderItem' => [
        'metadata' => 'names total_price/metadata; order_items stores total_amount and has no metadata',
        'total_price' => 'names total_price/metadata; order_items stores total_amount and has no metadata',
    ],
    'Page' => [
        'is_published' => 'names is_published; pages stores status and published_at',
    ],
    'PageTranslation' => [
        'meta_description' => 'names meta_description; page_translations stores description/seo_description',
    ],
    'Payment' => [
        'metadata' => 'names transaction_id/metadata; payments stores provider_payment_id/metadata_json',
        'transaction_id' => 'names transaction_id/metadata; payments stores provider_payment_id/metadata_json',
    ],
    'PaymentTransaction' => [
        'provider_response' => 'names provider_response; payment_transactions stores response_json',
    ],
    'PrivacyRequest' => [
        'reason' => 'names reason; privacy_requests stores type/status/payload_json',
    ],
    'PromoCode' => [
        'expires_at' => 'names type/value/max_uses/used_count/expires_at/is_active; promo_codes stores discount_type, value_amount/value_percent, max_redemptions, redemptions_count, valid_until, status',
        'is_active' => 'names type/value/max_uses/used_count/expires_at/is_active; promo_codes stores discount_type, value_amount/value_percent, max_redemptions, redemptions_count, valid_until, status',
        'max_uses' => 'names type/value/max_uses/used_count/expires_at/is_active; promo_codes stores discount_type, value_amount/value_percent, max_redemptions, redemptions_count, valid_until, status',
        'type' => 'names type/value/max_uses/used_count/expires_at/is_active; promo_codes stores discount_type, value_amount/value_percent, max_redemptions, redemptions_count, valid_until, status',
        'used_count' => 'names type/value/max_uses/used_count/expires_at/is_active; promo_codes stores discount_type, value_amount/value_percent, max_redemptions, redemptions_count, valid_until, status',
        'value' => 'names type/value/max_uses/used_count/expires_at/is_active; promo_codes stores discount_type, value_amount/value_percent, max_redemptions, redemptions_count, valid_until, status',
    ],
    'Redirect' => [
        'source_path' => 'names source_path/target_path; redirects stores source/destination (REVIEW §3.20)',
        'target_path' => 'names source_path/target_path; redirects stores source/destination (REVIEW §3.20)',
    ],
    'SeatHold' => [
        'status' => 'names status; seat_holds has NO status column -- the state lives on released_at/converted_at (REVIEW §3.x)',
    ],
    'SeoMeta' => [
        'meta_description' => 'names meta_title/meta_description/page_id/subject_type/subject_id; seo_meta stores entity_type/entity_id, title/description',
        'meta_title' => 'names meta_title/meta_description/page_id/subject_type/subject_id; seo_meta stores entity_type/entity_id, title/description',
        'page_id' => 'names meta_title/meta_description/page_id/subject_type/subject_id; seo_meta stores entity_type/entity_id, title/description',
        'subject_id' => 'names meta_title/meta_description/page_id/subject_type/subject_id; seo_meta stores entity_type/entity_id, title/description',
        'subject_type' => 'names meta_title/meta_description/page_id/subject_type/subject_id; seo_meta stores entity_type/entity_id, title/description',
    ],
    'Ticket' => [
        'pdf_url' => 'names qr_code/pdf_url; tickets stores qr_token_hash/qr_version and has no PDF column',
        'qr_code' => 'names qr_code/pdf_url; tickets stores qr_token_hash/qr_version and has no PDF column',
    ],
    'TicketScan' => [
        'message' => 'names message; ticket_scans stores result/reason',
    ],
    'TicketTemplate' => [
        'is_active' => 'names session_id/qr_prefix/is_active; ticket_templates stores event_id/active and no QR prefix',
        'qr_prefix' => 'names session_id/qr_prefix/is_active; ticket_templates stores event_id/active and no QR prefix',
        'session_id' => 'names session_id/qr_prefix/is_active; ticket_templates stores event_id/active and no QR prefix',
    ],
    'Webhook' => [
        'events' => 'names name/secret/events/is_active; webhooks stores url/secret_hash/event_types/active',
        'is_active' => 'names name/secret/events/is_active; webhooks stores url/secret_hash/event_types/active',
        'name' => 'names name/secret/events/is_active; webhooks stores url/secret_hash/event_types/active',
        'secret' => 'names name/secret/events/is_active; webhooks stores url/secret_hash/event_types/active',
    ],
    'WebhookDelivery' => [
        'attempt_count' => 'names webhook_event_id/status/attempt_count/response_code; webhook_deliveries stores event_id/response_status/attempts. response_code vs response_status is the same confusion the idempotency middleware had',
        'response_code' => 'names webhook_event_id/status/attempt_count/response_code; webhook_deliveries stores event_id/response_status/attempts. response_code vs response_status is the same confusion the idempotency middleware had',
        'status' => 'names webhook_event_id/status/attempt_count/response_code; webhook_deliveries stores event_id/response_status/attempts. response_code vs response_status is the same confusion the idempotency middleware had',
        'webhook_event_id' => 'names webhook_event_id/status/attempt_count/response_code; webhook_deliveries stores event_id/response_status/attempts. response_code vs response_status is the same confusion the idempotency middleware had',
    ],
    'WebhookEvent' => [
        'event_type' => 'names event_type/payload; webhook_events stores event_name/payload_json',
        'payload' => 'names event_type/payload; webhook_events stores event_name/payload_json',
    ],
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
 * Every model file under app/Modules.
 *
 * @return list<string>
 */
function findModels(string $root): array
{
    $found = [];
    $dir = $root . '/app/Modules';

    if (! is_dir($dir)) {
        return $found;
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

        if (! str_contains($path, '/Models/')) {
            continue;
        }

        $found[] = $file->getPathname();
    }

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

$models = findModels($root);
$rootPrefix = str_replace('\\', '/', $root) . '/';

if ($models === []) {
    fwrite(\STDERR, "  FAIL  no models found under app/Modules/ * /Models/. Either they moved or\n"
        . "        the discovery in findModels() is broken; a green run here would mean nothing.\n\n");

    exit(1);
}

printf("  Schema: %d tables, %d columns\n", count($tables), $columnCount);
printf("  Models: %d\n\n", count($models));

$unknownTables = [];
$unknownFields = [];
$noTable = [];
$checkedFields = 0;

foreach ($models as $path) {
    $src = file_get_contents($path);
    $rel = str_replace($rootPrefix, '', str_replace('\\', '/', $path));
    $class = pathinfo($path, \PATHINFO_FILENAME);

    if (! preg_match('/\$table\s*=\s*[\'"]([^\'"]+)[\'"]/', $src, $tm)) {
        // No $table: Eloquent would pluralise the class name, which for this schema
        // is wrong often enough to be worth naming rather than guessing at.
        $noTable[] = $rel;
        continue;
    }

    $table = $tm[1];

    if (! isset($tables[$table])) {
        $unknownTables[] = sprintf('%s (%s) -> %s', $rel, $class, $table);
        continue;
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

        if ($field === 'id' || in_array($field, $tables[$table], true)) {
            continue;
        }

        $key = $class . '.' . $field;

        if ($listMode) {
            $unknownFields[$key] = sprintf('%s  (%s)', $field, $table);
            continue;
        }

        if (isset(INVENTED_FIELDS[$class][$field])) {
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
    echo "  FAIL — the generated models name things the schema does not have.\n";
    echo "         Either fix the model, or change the schema in the spec bundle and\n";
    echo "         record the decision. Do not add to INVENTED_FIELDS to silence it.\n\n";

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
