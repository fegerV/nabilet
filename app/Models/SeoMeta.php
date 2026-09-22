<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $entity_type
 * @property int $entity_id
 * @property string $locale
 * @property string|null $title
 * @property string|null $description
 * @property string|null $canonical_url
 * @property string|null $robots
 * @property string|null $og_title
 * @property string|null $og_description
 * @property string|null $og_image
 * @property array|null $schema_json
 */
class SeoMeta extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'entity_type',
        'entity_id',
        'locale',
        'title',
        'description',
        'canonical_url',
        'robots',
        'og_title',
        'og_description',
        'og_image',
        'schema_json',
    ];

    protected $casts = [
        'schema_json' => 'array',
    ];
}
