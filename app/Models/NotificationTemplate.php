<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property string $code
 * @property string $channel
 * @property string $locale
 * @property string|null $subject
 * @property string|null $body_text
 * @property string|null $body_html
 * @property bool $active
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class NotificationTemplate extends Model
{
    protected $fillable = [
        'public_id',
        'code',
        'channel',
        'locale',
        'subject',
        'body_text',
        'body_html',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

        protected static function boot(): void
        {
            parent::boot();
            static::creating(function (self $model) {
                if (empty($model->public_id)) {
                    $model->public_id = (string) \Illuminate\Support\Str::ulid()->toBase32();
                }
            });
        }

}
